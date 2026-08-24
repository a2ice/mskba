<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Domain\Models\UserDuplicate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final class UserDuplicateResolutionAttemptLogger
{
    /**
     * @param  list<string>  $validationFields
     */
    public function mergeFailed(
        UserDuplicate $candidate,
        Request $request,
        string $reasonType,
        string $message,
        array $validationFields = [],
    ): void {
        $context = [
            'user_duplicate_id' => (int) $candidate->id,
            'resolved_by_user_id' => $request->user()?->canonical()->id,
            'reason_type' => $reasonType,
            'validation_fields' => array_values(array_unique($validationFields)),
            'message' => $message,
            'route' => $request->route()?->getName(),
        ];

        Log::warning('Admin user duplicate merge failed.', $context);

        if (! (bool) config('audit.enabled', true) || ! Schema::hasTable('audit_logs')) {
            return;
        }

        $ip = $request->ip();

        AuditLog::query()->create([
            'actor_id' => Schema::hasTable('actors')
                ? app(CurrentActorResolver::class)->resolveForRequest($request)?->id
                : null,
            'auditable_type' => UserDuplicate::class,
            'auditable_id' => $candidate->id,
            'event' => 'merge_failed',
            'old_values' => [],
            'new_values' => [],
            'metadata' => [
                'reason_type' => $reasonType,
                'validation_fields' => $context['validation_fields'],
                'message' => $message,
                'method' => $request->method(),
                'route' => $context['route'],
                'path' => $request->path(),
                'ip_hash' => $ip === null ? null : hash_hmac('sha256', $ip, (string) config('app.key')),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
            ],
        ]);
    }
}
