<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class UpdateAccountProfileController
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless(app(ThemeResolver::class)->active() === 'mskba_app', 404);
        $actor = $request->user();
        abort_unless($actor, 401);

        // Confirmed identity attributes require an approval request, not a form PATCH.
        // Inspect supplied keys before validation: excluded/nullable inputs cannot
        // silently bypass the lock via their values or an omitted field.
        $locked = ['first_name', 'last_name', 'birth_date', 'gender'];
        $canonical = $actor->canonical();
        abort_if($canonical->isBlocked(), 403);
        if ($canonical->isConfirmed()) {
            foreach ($locked as $field) {
                abort_if($request->exists($field), 403, 'Изменение подтверждённых данных возможно только по заявке.');
            }
        }

        $rules = ['middle_name' => ['sometimes', 'nullable', 'string', 'max:255']];
        if (! $canonical->isConfirmed()) {
            $rules += [
                'first_name' => ['sometimes', 'nullable', 'string', 'max:255'],
                'last_name' => ['sometimes', 'nullable', 'string', 'max:255'],
                'birth_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before:today'],
                'gender' => ['sometimes', 'nullable', Rule::enum(UserGenderEnum::class)],
            ];
        }
        $data = $request->validate($rules);
        foreach ($data as $key => $value) {
            if (is_string($value) && $key !== 'birth_date') {
                $data[$key] = trim($value) ?: null;
            }
        }

        DB::transaction(function () use ($canonical, $data): void {
            $user = User::query()->whereKey($canonical->id)->lockForUpdate()->firstOrFail();
            abort_if($user->isBlocked(), 403);
            if ($user->isConfirmed()) {
                foreach (['first_name', 'last_name', 'birth_date', 'gender'] as $field) {
                    abort_if(array_key_exists($field, $data), 403);
                }
            }

            $profile = $user->profile()->lockForUpdate()->first();
            if ($profile === null) {
                $profile = $user->profile()->create([]);
            }
            $profile->fill($data)->save();
        });

        return redirect()->route('account.profile')->with('profile_status', 'Данные профиля сохранены.');
    }
}
