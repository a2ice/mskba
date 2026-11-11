<?php

namespace App\Modules\VenueIntegration\Infrastructure\Adapters;

use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\VenueIntegration\Application\Services\VenueIntegrationManager;
use App\Modules\VenueIntegration\Domain\Models\VenueIntegrationSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Task 306 / FindSport pilot. A read-only diagnostic, NOT a booking client.
 *
 * The public page contains pagesPlaygroundView(JSON) with hourly prices.
 * Its own JS GETs /playground/schedule/{id}?date=YYYY-MM-DD for a week's
 * bookedMap. We never execute the page's JS and never send POST requests.
 */
final class FindSportReadOnlyAdapter
{
    public function preview(VenueIntegrationSource $source, User $actor): array
    {
        app(VenueIntegrationManager::class)->requirePermission(
            $actor, Permission::BACKOFFICE_VENUE_INTEGRATIONS_EXECUTE,
        );

        if (! config('venue_integrations.findsport_preview_enabled', false)) {
            throw new RuntimeException('Тестовое чтение FindSport отключено конфигурацией сервера.');
        }

        $integration = $source->integration;
        if ($source->adapter_key !== 'api' || $integration?->mode !== 'test'
            || ! in_array($source->permission_status, ['testing_only', 'mskba_allowed'], true)
            || ! $source->access_reviewed_at || blank($source->permission_reference)
            || $source->courtMappings()->count() !== 1) {
            throw new RuntimeException('Для предпросмотра требуется API-источник в тестовом режиме, один зал и проверенное основание доступа.');
        }

        $url = (string) $source->endpoint_url;
        if (! preg_match('~^https://findsport\.ru/playground/([1-9][0-9]*)/?$~i', $url, $matches)) {
            throw new RuntimeException('Пилот допускает только адрес вида https://findsport.ru/playground/{id}.');
        }
        $externalId = (int) $matches[1];
        if ($source->external_id !== null && $source->external_id !== (string) $externalId) {
            throw new RuntimeException('Внешний ID источника не совпадает с ID страницы.');
        }

        $today = CarbonImmutable::now('Europe/Moscow')->startOfDay();
        $cacheKey = "venue-integration:findsport:preview:{$source->id}:".$today->toDateString();
        if (is_array($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        // One manual diagnostic per source per minute, independent of scheduled sync.
        if (! Cache::add("venue-integration:findsport:throttle:{$source->id}", true, now()->addMinute())) {
            throw new RuntimeException('Повторная проверка ограничена: используйте сохранённый предпросмотр через минуту.');
        }

        // Pin the public IPv4 address used by cURL so DNS rebinding cannot
        // redirect a permitted host towards an internal service.
        $resolvedIp = $this->resolvePublicIp('findsport.ru');
        if (! defined('CURLOPT_RESOLVE')) {
            throw new RuntimeException('Для защищённого внешнего запроса требуется PHP cURL.');
        }
        $http = Http::withOptions([
            'allow_redirects' => false,
            'curl' => [CURLOPT_RESOLVE => ["findsport.ru:443:{$resolvedIp}"]],
        ])->timeout(10)->connectTimeout(5)->withHeaders([
            'Accept' => 'text/html, application/json',
            'User-Agent' => 'MSKBA-ReadOnly-Integration-Test/1.0',
        ]);

        $page = $http->get($url);
        if ($page->status() !== 200 || strlen($page->body()) > 2_000_000) {
            throw new RuntimeException('Страница FindSport вернула ошибку либо превышен допустимый размер.');
        }
        $schedule = $http->get(
            "https://findsport.ru/playground/schedule/{$externalId}",
            ['date' => $today->toDateString()],
        );
        if ($schedule->status() !== 200 || strlen($schedule->body()) > 500_000) {
            throw new RuntimeException('FindSport не вернул допустимый JSON расписания.');
        }

        $result = $this->parse($page->body(), $schedule->body(), $externalId, $today);
        $result['observed_at'] = now()->toIso8601String();
        $result['source_id'] = $source->id;
        $result['venue_court_id'] = $source->courtMappings()->value('venue_court_id');

        DB::table('venue_integration_audit_events')->insert([
            'venue_integration_id' => $source->venue_integration_id,
            'venue_integration_source_id' => $source->id,
            'actor_user_id' => $actor->id,
            'action' => 'source.read_only_preview',
            'changes' => json_encode([
                'external_id' => $externalId,
                'days' => count($result['days']),
                'slots' => array_sum(array_column($result['days'], 'slot_count')),
            ], JSON_THROW_ON_ERROR),
            'metadata' => json_encode(['execution' => 'manual_test_read_only'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
        Cache::put($cacheKey, $result, now()->addMinutes(2));

        return $result;
    }

    /** Parse public HTML + provider JSON without executing third-party JavaScript. */
    public function parse(string $html, string $availabilityJson, int $expectedId, CarbonImmutable $startDate): array
    {
        if (! preg_match('/new\s+pagesPlaygroundView\s*\(\s*(\{[^\r\n]*\})\s*\)\s*;/u', $html, $match)) {
            throw new RuntimeException('В HTML не найден структурированный блок pagesPlaygroundView.');
        }

        $data = json_decode($match[1], true, 128, JSON_THROW_ON_ERROR);
        $bookedMap = json_decode($availabilityJson, true, 128, JSON_THROW_ON_ERROR);
        if (! is_array($data) || ! is_array($bookedMap)
            || (int) ($data['playground']['id'] ?? 0) !== $expectedId) {
            throw new RuntimeException('Ответ FindSport не соответствует ожидаемой площадке.');
        }

        $pg = $data['playground'];
        $step = (int) ($pg['timeStep'] ?? 0);
        $start = (int) ($pg['start'] ?? -1);
        $finish = (int) ($pg['finish'] ?? -1);
        $timezone = (string) ($pg['timezone'] ?? '');
        $schedules = $data['schedules'] ?? [];
        if ($step !== 1800 || $start < 0 || $finish > 86400 || $finish <= $start
            || $timezone !== 'Europe/Moscow' || ! is_array($schedules)
            || count($schedules) !== 1 || ! isset($schedules[0]['id'])
            || ! is_array($data['priceManagerData']['prices'] ?? null)) {
            throw new RuntimeException('Параметры зала отличаются от подтверждённого формата пилота.');
        }

        $sid = (string) $schedules[0]['id'];
        $prices = $data['priceManagerData']['prices'];
        $days = [];
        for ($index = 0; $index < 7; $index++) {
            $date = $startDate->addDays($index)->toDateString();
            $dayComplete = array_key_exists($date, $bookedMap) && is_array($bookedMap[$date]);
            $entries = $dayComplete ? $bookedMap[$date] : [];
            $slots = [];
            $counts = ['available' => 0, 'booked' => 0, 'unavailable' => 0, 'unknown' => 0];
            for ($seconds = $start; $seconds + $step <= $finish; $seconds += $step) {
                $time = gmdate('H:i', $seconds);
                $priceMinor = $this->priceMinor($prices, $date, $seconds, $seconds + $step);
                $reported = $entries[$time] ?? [];
                $mask = is_array($reported) ? ($reported[$sid] ?? null) : null;

                if (! $dayComplete || ($mask !== null && (! is_numeric($mask) || (int) $mask < 0 || (int) $mask > 12))) {
                    $status = 'unknown';
                } elseif ($mask !== null && (int) $mask > 0) {
                    // For full-court weight=12: 12 - bookedMask < 12.
                    $status = 'booked';
                } elseif ($priceMinor === null) {
                    $status = 'unavailable';
                } else {
                    // Candidate only: provider's own calendar interprets missing
                    // bookedMap entries as unbooked on a complete fetched date.
                    $status = 'available';
                }
                $counts[$status]++;
                $slots[] = [
                    'time' => $time,
                    'ends_at' => gmdate('H:i', ($seconds + $step) % 86400),
                    'status' => $status,
                    'price_minor' => $priceMinor,
                ];
            }
            $days[] = [
                'date' => $date,
                'complete' => $dayComplete,
                'slot_count' => count($slots),
                'counts' => $counts,
                'slots' => $slots,
            ];
        }

        return [
            'provider' => 'FindSport',
            'external_id' => $expectedId,
            'external_court_id' => $sid,
            'timezone' => $timezone,
            'step_minutes' => $step / 60,
            'minimum_minutes' => (int) ($pg['minBookingInterval'] ?? 0) / 60,
            'price_currency' => 'RUB',
            'days' => $days,
            'notice' => 'Свободно означает отсутствие пометки о занятости в полном ответе источника; не является подтверждением брони. Тарифы и занятость не изменяют правила MSKBA.',
        ];
    }

    /** Full court, no additional booking factor. PHP returns kopecks. */
    private function priceMinor(array $prices, string $date, int $start, int $finish): ?int
    {
        $weekday = (int) (new CarbonImmutable($date, 'Europe/Moscow'))->dayOfWeek;
        $dayMask = 1 << $weekday;
        $matching = [];
        foreach ($prices as $price) {
            if (! is_array($price) || (int) ($price['part'] ?? 0) !== 12
                || ! empty($price['options']) || ((int) ($price['weekdays'] ?? 0) & $dayMask) === 0
                || ($price['season']['periodStart'] ?? '') > $date
                || ($price['season']['periodFinish'] ?? '') <= $date
                || (int) ($price['start'] ?? -1) > $start
                || (int) ($price['finish'] ?? -1) < $finish) {
                continue;
            }
            // Prefer an explicit unknown over inventing the value of a discount.
            if (($price['discount_id'] ?? null) !== null) {
                return null;
            }
            $matching[] = $price;
        }
        if (count($matching) !== 1 || ! is_numeric($matching[0]['outer'] ?? null)) {
            return null;
        }

        return (int) ceil((float) $matching[0]['outer'] * ($finish - $start) / 3600) * 100;
    }

    private function resolvePublicIp(string $host): string
    {
        $records = dns_get_record($host, DNS_A);
        if (! is_array($records) || $records === []) {
            throw new RuntimeException('Не удалось разрешить публичный IP FindSport.');
        }

        $addresses = array_values(array_filter(array_map(
            static fn (array $record): ?string => $record['ip'] ?? null,
            $records,
        )));
        foreach ($addresses as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('DNS FindSport вернул запрещённый IP.');
            }
        }

        return $addresses[0];
    }
}
