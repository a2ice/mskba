<?php

namespace App\Modules\Audit\Domain\Traits;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

trait Auditable
{
    /**
     * @var array{old: array<string, mixed>, new: array<string, mixed>}|null
     */
    protected ?array $pendingAuditUpdate = null;

    public static function bootAuditable(): void
    {
        static::created(function (Model $model): void {
            $model->writeAuditLog('created');
        });

        static::updating(function (Model $model): void {
            $changed = $model->auditAttributes($model->getDirty());

            if ($changed === []) {
                $model->pendingAuditUpdate = null;

                return;
            }

            $model->pendingAuditUpdate = [
                'old' => collect(array_keys($changed))
                    ->mapWithKeys(fn (string $key): array => [$key => $model->getRawOriginal($key)])
                    ->all(),
                'new' => $changed,
            ];
        });

        static::updated(function (Model $model): void {
            if ($model->pendingAuditUpdate === null) {
                return;
            }

            $model->writeAuditLog(
                event: 'updated',
                oldValues: $model->pendingAuditUpdate['old'],
                newValues: $model->pendingAuditUpdate['new'],
            );
            $model->pendingAuditUpdate = null;
        });

        static::deleted(function (Model $model): void {
            $model->writeAuditLog('deleted');
        });
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    protected function writeAuditLog(string $event, ?array $oldValues = null, ?array $newValues = null): void
    {
        if (! $this->shouldWriteAuditLog()) {
            return;
        }

        $oldValues ??= [];
        $newValues ??= [];

        if ($event === 'created') {
            $newValues = $this->auditAttributes($this->getAttributes());
        }

        if ($event === 'deleted') {
            $oldValues = $this->auditAttributes($this->getOriginal());
        }

        AuditLog::query()->create([
            'actor_id' => $this->currentActorId(),
            'auditable_type' => static::class,
            'auditable_id' => $this->getKey(),
            'event' => $event,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'metadata' => $this->auditMetadata(),
        ]);
    }

    protected function shouldWriteAuditLog(): bool
    {
        if (! (bool) config('audit.enabled', true)) {
            return false;
        }

        if ((bool) config('audit.ignore_console', true) && app()->runningInConsole()) {
            return false;
        }

        if (! Schema::hasTable('audit_logs')) {
            return false;
        }

        return in_array(static::class, config('audit.auditable', []), true);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function auditAttributes(array $attributes): array
    {
        $ignored = array_flip(config('audit.ignored_attributes', []));

        return collect($attributes)
            ->reject(fn (mixed $value, string $key): bool => array_key_exists($key, $ignored))
            ->all();
    }

    protected function currentActorId(): ?int
    {
        if (! app()->bound('request') || ! Schema::hasTable('actors')) {
            return null;
        }

        return app(CurrentActorResolver::class)->resolveForRequest(request())?->id;
    }

    /**
     * @return array<string, mixed>
     */
    protected function auditMetadata(): array
    {
        if (! app()->bound('request')) {
            return [];
        }

        $request = request();
        $ip = $request->ip();

        return [
            'method' => $request->method(),
            'route' => $request->route()?->getName(),
            'path' => $request->path(),
            'ip_hash' => $ip === null ? null : hash_hmac('sha256', $ip, (string) config('app.key')),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ];
    }
}
