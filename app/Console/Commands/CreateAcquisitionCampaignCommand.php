<?php

namespace App\Console\Commands;

use App\Modules\Acquisition\Domain\Enums\AcquisitionChannelEnum;
use App\Modules\Acquisition\Domain\Models\AcquisitionCampaign;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Console\Command;

final class CreateAcquisitionCampaignCommand extends Command
{
    protected $signature = 'acquisition:campaign
        {code : Публичный код для ссылки /go/...}
        {name : Понятное название кампании}
        {--channel=qr : qr|context_ads|social|partner|referral|direct|other}
        {--venue= : ID или alias площадки для геопроверки}
        {--radius=250 : Радиус подтверждения положения в метрах}
        {--force : Обновить существующую кампанию с таким кодом}';

    protected $description = 'Создать или обновить acquisition-кампанию и вывести canonical ссылку для QR/рекламы';

    public function handle(): int
    {
        $code = trim((string) $this->argument('code'));
        $name = trim((string) $this->argument('name'));
        $channel = AcquisitionChannelEnum::tryFrom(trim((string) $this->option('channel')));
        $radius = filter_var($this->option('radius'), FILTER_VALIDATE_INT);

        if (preg_match('/^[A-Za-z0-9_-]{2,64}$/', $code) !== 1) {
            $this->error('Код должен содержать 2–64 символа: латинские буквы, цифры, _ или -.');

            return self::FAILURE;
        }

        if ($name === '') {
            $this->error('Название кампании не может быть пустым.');

            return self::FAILURE;
        }

        if ($channel === null) {
            $this->error('Неизвестный channel. Допустимо: '.implode(', ', array_column(AcquisitionChannelEnum::cases(), 'value')));

            return self::FAILURE;
        }

        if ($radius === false || $radius < 25 || $radius > 5000) {
            $this->error('Радиус должен быть целым числом от 25 до 5000 метров.');

            return self::FAILURE;
        }

        $venue = $this->resolveVenue($this->option('venue'));
        if ($this->option('venue') !== null && $this->option('venue') !== '' && $venue === null) {
            $this->error('Площадка не найдена. Передайте её ID или alias.');

            return self::FAILURE;
        }

        $existing = AcquisitionCampaign::query()->where('public_code', $code)->first();
        if ($existing !== null && ! $this->option('force')) {
            $this->error('Кампания с таким кодом уже существует. Используйте --force для обновления.');

            return self::FAILURE;
        }

        $campaign = AcquisitionCampaign::query()->updateOrCreate(
            ['public_code' => $code],
            [
                'name' => $name,
                'channel' => $channel,
                'venue_id' => $venue?->id,
                'verification_radius_m' => $radius,
                'is_active' => true,
            ],
        );

        $this->newLine();
        $this->info($existing === null ? 'Кампания создана.' : 'Кампания обновлена.');
        $this->line('ID: '.$campaign->id);
        $this->line('Канал: '.$campaign->channel->label());
        $this->line('Площадка: '.($venue?->name ?? 'не привязана'));
        $this->line('Ссылка: '.route('acquisition.entry', ['campaignCode' => $campaign->public_code]));

        return self::SUCCESS;
    }

    private function resolveVenue(mixed $value): ?Venue
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        $value = trim((string) $value);

        if (ctype_digit($value)) {
            return Venue::query()->find((int) $value);
        }

        return Venue::query()->where('alias', $value)->first();
    }
}
