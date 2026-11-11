<?php

namespace App\Modules\VenueIntegration\Application\Services;

use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueIntegration\Domain\Models\VenueIntegration;
use App\Modules\VenueIntegration\Domain\Models\VenueIntegrationSource;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Task 305: stores reviewed integration configuration ONLY. Never fetches a URL,
 * schedules a job, creates a third-party booking, or makes a payment.
 */
final readonly class VenueIntegrationManager
{
    public function __construct(private BackofficePermissionManager $permissions) {}

    public function can(User $actor, Permission $permission): bool
    {
        $actor = $actor->canonical();

        return $actor->isConfirmed() && ! $actor->isBlocked() && ! $actor->trashed()
            && $this->permissions->allows($actor, Permission::BACKOFFICE_ACCESS)
            && $this->permissions->allows($actor, Permission::BACKOFFICE_VENUE_MANAGE)
            && $this->permissions->allows($actor, $permission);
    }

    public function requirePermission(User $actor, Permission $permission): User
    {
        $actor = $actor->canonical();
        if (! $this->can($actor, $permission)) {
            throw new AuthorizationException('Недостаточно прав на интеграции площадок.');
        }

        return $actor;
    }

    /** @param array<string, mixed> $input */
    public function saveConfiguration(Venue $venue, User $actor, array $input): VenueIntegration
    {
        $actor = $this->requirePermission($actor, Permission::BACKOFFICE_VENUE_INTEGRATIONS_MANAGE);
        abort_if($venue->trashed(), 404);

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:4000'],
            'timezone' => ['required', 'timezone'],
            'freshness_ttl_seconds' => ['required', 'integer', 'min:60', 'max:86400'],
            'horizon_days' => ['required', 'integer', 'min:1', 'max:90'],
            'refresh_on_visit' => ['sometimes', 'boolean'],
            'scheduled_refresh' => ['sometimes', 'boolean'],
            'mode' => ['required', Rule::in(['test', 'production'])],
            'enabled' => ['sometimes', 'boolean'],
        ])->validate();

        return DB::transaction(function () use ($venue, $actor, $data): VenueIntegration {
            $config = VenueIntegration::query()->where('venue_id', $venue->id)
                ->lockForUpdate()->first();
            $created = $config === null;
            $config ??= new VenueIntegration(['venue_id' => $venue->id]);
            $before = $created ? [] : $this->safeConfigurationState($config);
            $enabled = (bool) ($data['enabled'] ?? $config->enabled ?? false);
            $mode = (string) $data['mode'];

            if ($enabled) {
                $activeSources = $config->exists
                    ? $config->sources()->where('enabled', true)->get()
                    : collect();
                if ($activeSources->isEmpty()) {
                    throw ValidationException::withMessages([
                        'enabled' => 'Сначала настройте и разрешите хотя бы один источник для конкретного зала.',
                    ]);
                }
                foreach ($activeSources as $source) {
                    $this->assertSourceMayBeActive($source, $mode);
                }
            }

            $config->fill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'timezone' => $data['timezone'],
                'freshness_ttl_seconds' => $data['freshness_ttl_seconds'],
                'horizon_days' => $data['horizon_days'],
                'refresh_on_visit' => (bool) ($data['refresh_on_visit'] ?? false),
                'scheduled_refresh' => (bool) ($data['scheduled_refresh'] ?? false),
                'mode' => $mode,
                'enabled' => $enabled,
                'updated_by_user_id' => $actor->id,
            ]);
            if ($created) {
                $config->created_by_user_id = $actor->id;
            } else {
                $config->version = $config->version + 1;
            }
            $config->save();

            $this->audit($config, null, $actor, $created ? 'configuration.created' : 'configuration.updated', [
                'before' => $before,
                'after' => $this->safeConfigurationState($config),
            ]);

            return $config->refresh();
        });
    }

    /**
     * A source draft is always disabled unless an administrator explicitly
     * confirms mappings and an allowed access status. A saved URL is not
     * permission to execute it. For API/browser adapters, the runtime kill
     * switch additionally prevents activation before Tasks 306–309.
     *
     * @param array<string,mixed> $input
     */
    public function saveSource(
        VenueIntegration $integration,
        User $actor,
        array $input,
        ?VenueIntegrationSource $source = null,
    ): VenueIntegrationSource {
        $actor = $this->requirePermission($actor, Permission::BACKOFFICE_VENUE_INTEGRATIONS_MANAGE);
        abort_if($integration->venue?->trashed() || $integration->venue === null, 404);
        if ($source !== null && (int) $source->venue_integration_id !== (int) $integration->id) {
            throw new AuthorizationException('Источник не принадлежит выбранной площадке.');
        }

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'external_id' => ['nullable', 'string', 'max:190'],
            'adapter_key' => ['required', Rule::in(['manual', 'api', 'browser'])],
            'endpoint_url' => ['nullable', 'string', 'max:2048'],
            'priority' => ['required', 'integer', 'min:0', 'max:1000'],
            'capabilities' => ['nullable', 'array', 'max:5'],
            'capabilities.*' => [Rule::in(['availability', 'pricing'])],
            'permission_status' => ['required', Rule::in([
                'unchecked', 'mskba_allowed', 'testing_only', 'prohibited',
            ])],
            'permission_reference' => ['nullable', 'string', 'max:2000'],
            'credentials' => ['sometimes', 'nullable', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:8000'],
            'court_ids' => ['required', 'array', 'min:1', 'max:100'],
            'court_ids.*' => ['required', 'integer', 'distinct'],
            'enabled' => ['sometimes', 'boolean'],
        ])->validate();

        $url = trim((string) ($data['endpoint_url'] ?? ''));
        if ($url !== '') {
            $this->assertSafeEndpointUrl($url);
        }
        if ($data['adapter_key'] !== 'manual' && $url === '') {
            throw ValidationException::withMessages([
                'endpoint_url' => 'Для внешнего API/браузера необходим HTTPS-адрес.',
            ]);
        }
        if ($data['permission_status'] === 'prohibited' && (bool) ($data['enabled'] ?? false)) {
            throw ValidationException::withMessages(['enabled' => 'Запрещённый источник нельзя включить.']);
        }

        $courtIds = array_values(array_unique(array_map('intval', $data['court_ids'])));
        if ($integration->venue->courts()->whereIn('id', $courtIds)->count() !== count($courtIds)) {
            throw ValidationException::withMessages([
                'court_ids' => 'Все выбранные залы должны принадлежать этой площадке.',
            ]);
        }

        return DB::transaction(function () use ($integration, $actor, $source, $data, $courtIds, $url): VenueIntegrationSource {
            $config = VenueIntegration::query()->whereKey($integration->id)->lockForUpdate()->firstOrFail();
            $record = $source === null ? new VenueIntegrationSource([
                'venue_integration_id' => $config->id,
            ]) : VenueIntegrationSource::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            $created = ! $record->exists;
            if (! $created && (int) $record->venue_integration_id !== (int) $config->id) {
                throw new AuthorizationException('Источник не принадлежит выбранной площадке.');
            }
            $before = $created ? [] : $this->safeSourceState($record);
            $requestedEnabled = (bool) ($data['enabled'] ?? $record->enabled ?? false);
            $ref = trim((string) ($data['permission_reference'] ?? ''));
            $status = (string) $data['permission_status'];
            $newReview = $status !== $record->permission_status
                || $ref !== (string) $record->permission_reference;

            $record->fill([
                'name' => $data['name'],
                'external_id' => $data['external_id'] ?? null,
                'adapter_key' => $data['adapter_key'],
                'endpoint_url' => $url !== '' ? $url : null,
                'priority' => $data['priority'],
                'capabilities' => array_values(array_unique($data['capabilities'] ?? [])),
                'permission_status' => $status,
                'permission_reference' => $ref !== '' ? $ref : null,
                'access_reviewed_at' => $newReview
                    ? (in_array($status, ['mskba_allowed', 'testing_only'], true) && $ref !== '' ? now() : null)
                    : $record->access_reviewed_at,
                'enabled' => false, // validated and enabled only after mapping sync below
                'updated_by_user_id' => $actor->id,
            ]);
            if (! $created) {
                $record->version = $record->version + 1;
            }
            if (isset($data['credentials']) && $data['credentials'] !== []) {
                $record->credentials = $data['credentials'];
            }
            $record->save();
            $record->courtMappings()->delete();
            foreach ($courtIds as $courtId) {
                $record->courtMappings()->create(['venue_court_id' => $courtId]);
            }

            if ($requestedEnabled) {
                $this->assertSourceMayBeActive($record->refresh(), (string) $config->mode);
                $record->enabled = true;
                $record->save();
            }

            $this->audit($config, $record, $actor, $created ? 'source.created' : 'source.updated', [
                'before' => $before,
                'after' => $this->safeSourceState($record->refresh()),
                'court_ids' => $courtIds,
                'credentials_changed' => isset($data['credentials']) && $data['credentials'] !== [],
            ]);

            return $record->refresh();
        });
    }

    private function assertSourceMayBeActive(VenueIntegrationSource $source, string $mode): void
    {
        if ($source->courtMappings()->count() === 0) {
            throw ValidationException::withMessages(['court_ids' => 'У источника должен быть выбран зал.']);
        }
        if ($source->permission_status === 'prohibited') {
            throw ValidationException::withMessages(['enabled' => 'Доступ к этому источнику запрещён.']);
        }
        if ($source->adapter_key !== 'manual') {
            if (! config('venue_integrations.live_source_execution_enabled', false)) {
                throw ValidationException::withMessages([
                    'enabled' => 'Внешние запросы отключены до проверки адаптеров и разрешений.',
                ]);
            }
            if (! in_array($source->permission_status, ['mskba_allowed', 'testing_only'], true)
                || ! $source->access_reviewed_at
                || blank($source->permission_reference)) {
                throw ValidationException::withMessages([
                    'permission_status' => 'Сначала подтвердите допустимость доступа и укажите основание.',
                ]);
            }
            if ($mode === 'production' && $source->permission_status !== 'mskba_allowed') {
                throw ValidationException::withMessages([
                    'permission_status' => 'Разрешение только для тестирования не допускает production.',
                ]);
            }
        }
    }

    private function assertSafeEndpointUrl(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host']) || isset($parts['user'], $parts['pass'])
            || isset($parts['user']) || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw ValidationException::withMessages(['endpoint_url' => 'Допустим только публичный HTTPS-адрес без логина и фрагмента.']);
        }
        $host = strtolower(trim((string) $parts['host'], '[]'));
        if ($host === 'localhost' || ! str_contains($host, '.')
            || preg_match('/\.(local|localhost|internal|invalid|test|example|lan)$/i', $host)
            || filter_var($host, FILTER_VALIDATE_IP) !== false
                && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw ValidationException::withMessages(['endpoint_url' => 'Внутренние IP-адреса и локальные хосты запрещены.']);
        }
        if (preg_match('/(?:[?&])(?:api_key|token|secret|password|authorization)=/i', $url)) {
            throw ValidationException::withMessages([
                'endpoint_url' => 'Секреты нельзя передавать в URL: используйте защищённое поле credentials.',
            ]);
        }
        // No DNS lookup here. The future adapter must re-resolve + validate IP
        // for every outgoing request, including redirects, to stop DNS rebinding.
    }

    /** @return array<string,mixed> */
    private function safeConfigurationState(VenueIntegration $record): array
    {
        return $record->only([
            'name', 'timezone', 'freshness_ttl_seconds', 'horizon_days',
            'refresh_on_visit', 'scheduled_refresh', 'mode', 'enabled', 'version',
        ]);
    }

    /** @return array<string,mixed> */
    private function safeSourceState(VenueIntegrationSource $record): array
    {
        return [
            'name' => $record->name,
            'adapter_key' => $record->adapter_key,
            'endpoint_host' => parse_url((string) $record->endpoint_url, PHP_URL_HOST) ?: null,
            'priority' => $record->priority,
            'capabilities' => $record->capabilities,
            'permission_status' => $record->permission_status,
            'enabled' => $record->enabled,
            'version' => $record->version,
        ];
    }

    /** @param array<string,mixed> $changes */
    private function audit(
        VenueIntegration $config,
        ?VenueIntegrationSource $source,
        User $actor,
        string $action,
        array $changes,
    ): void {
        DB::table('venue_integration_audit_events')->insert([
            'venue_integration_id' => $config->id,
            'venue_integration_source_id' => $source?->id,
            'actor_user_id' => $actor->id,
            'action' => $action,
            'changes' => json_encode($changes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'metadata' => json_encode([
                'context' => 'backoffice',
                'execution' => 'configuration_only',
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
