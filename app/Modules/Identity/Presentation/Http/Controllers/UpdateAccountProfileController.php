<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class UpdateAccountProfileController
{
    public function __invoke(Request $request): RedirectResponse|JsonResponse
    {
        abort_unless(app(ThemeResolver::class)->active() === 'mskba_app', 404);
        $actor = $request->user();
        abort_unless($actor, 401);

        // A role-derived or individually delegated self-edit permission authorizes
        // direct changes to confirmed personal data. Otherwise retain moderation.
        // The same gate is enforced again inside the transaction below.
        $canonical = $actor->canonical();
        abort_if($canonical->isBlocked() || $canonical->trashed(), 403);
        $selfEdit = app(UserOperationalPermissionChecker::class)->allows(
            $canonical, UserOperationalPermissionEnum::EDIT_OWN_PROFILE,
        );
        if ($canonical->isConfirmed() && ! $selfEdit) {
            foreach (['first_name', 'last_name', 'middle_name', 'birth_date', 'gender'] as $field) {
                abort_if($request->exists($field), 403, 'Для изменения подтверждённых данных отправьте заявку.');
            }
        }

        $rules = ['middle_name' => ['sometimes', 'nullable', 'string', 'max:255']];
        if (! $canonical->isConfirmed() || $selfEdit) {
            $rules += [
                'first_name' => ['sometimes', $canonical->isConfirmed() ? 'required' : 'nullable', 'string', 'max:255'],
                'last_name' => ['sometimes', $canonical->isConfirmed() ? 'required' : 'nullable', 'string', 'max:255'],
                'birth_date' => ['sometimes', $canonical->isConfirmed() ? 'required' : 'nullable', 'date_format:Y-m-d', 'before:today'],
                'gender' => ['sometimes', $canonical->isConfirmed() ? 'required' : 'nullable', Rule::enum(UserGenderEnum::class)],
            ];
        }
        $data = $request->validate($rules);
        foreach ($data as $key => $value) {
            if (is_string($value) && $key !== 'birth_date') {
                $data[$key] = trim($value) ?: null;
            }
        }

        DB::transaction(function () use ($canonical, $data, $request): void {
            $user = User::query()->whereKey($canonical->id)->lockForUpdate()->firstOrFail();
            abort_if($user->isBlocked() || $user->trashed(), 403);
            $confirmed = $user->isConfirmed();
            if ($confirmed) {
                abort_unless(
                    app(UserOperationalPermissionChecker::class)->allows(
                        $user, UserOperationalPermissionEnum::EDIT_OWN_PROFILE,
                    ),
                    403,
                    'Для изменения подтверждённых данных отправьте заявку.',
                );
            }

            $profile = $user->profile()->lockForUpdate()->first();
            if ($profile === null) {
                $profile = $user->profile()->create([]);
            }

            $before = [];
            foreach ($data as $field => $value) {
                $previous = $profile->getRawOriginal($field);
                $new = $value === '' ? null : $value;
                if (($previous === null && $new === null)
                    || ($previous !== null && $new !== null && (string) $previous === (string) $new)) {
                    continue;
                }
                $before[$field] = $previous;
            }
            if ($before === []) {
                return;
            }
            $profile->fill($data)->save();

            if ($confirmed) {
                $profile->refresh();
                $after = [];
                foreach (array_keys($before) as $field) {
                    $after[$field] = $profile->getRawOriginal($field);
                }
                AuditLog::query()->create([
                    'actor_id' => app(CurrentActorResolver::class)->resolveForRequest($request)?->id,
                    'auditable_type' => User::class,
                    'auditable_id' => $user->id,
                    'event' => 'self_confirmed_profile_updated',
                    'old_values' => $before,
                    'new_values' => $after,
                    'metadata' => [
                        'source' => 'account.profile.update',
                        'permission' => UserOperationalPermissionEnum::EDIT_OWN_PROFILE->value,
                        'profile_id' => $profile->id,
                    ],
                ]);
            }
        });

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Данные профиля сохранены.',
                'values' => $data,
            ]);
        }

        return redirect()->route('account.profile')->with('profile_status', 'Данные профиля сохранены.');
    }
}
