<?php

namespace App\Modules\Admin\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Application\UseCases\ListAdminUserDuplicatesHandler;
use App\Modules\Identity\Application\Services\UserDuplicateResolutionAttemptLogger;
use App\Modules\Identity\Application\UseCases\ResolveUserDuplicateHandler;
use App\Modules\Identity\Domain\Enums\UserDuplicateStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\UserDuplicate;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class AdminUserDuplicatesController extends Controller
{
    public function index(Request $request, ListAdminUserDuplicatesHandler $duplicates): Response
    {
        return ThemeResolver::page('admin.user-duplicates', [
            'duplicates' => $duplicates->handle($request->query()),
            'filters' => $request->query(),
            'statuses' => UserDuplicateStatusEnum::cases(),
        ]);
    }

    public function merge(
        Request $request,
        UserDuplicate $userDuplicate,
        ResolveUserDuplicateHandler $resolve,
        UserDuplicateResolutionAttemptLogger $attemptLogger,
    ): RedirectResponse {
        $userDuplicate->loadMissing(['user', 'duplicateUser']);
        $hasElevatedRole = collect([$userDuplicate->user, $userDuplicate->duplicateUser])
            ->filter()
            ->contains(fn ($user): bool => $user->canonical()->system_role !== UserSystemRoleEnum::USER);

        $validator = Validator::make($request->all(), [
            'canonical_user_id' => [
                'required',
                'integer',
                Rule::in([(int) $userDuplicate->user_id, (int) $userDuplicate->duplicate_user_id]),
            ],
            'confirm_merge' => ['required', 'accepted'],
            'confirm_privileged' => $hasElevatedRole ? ['required', 'accepted'] : ['nullable'],
        ], [
            'canonical_user_id.required' => 'Выберите основной аккаунт.',
            'canonical_user_id.integer' => 'Выбран некорректный основной аккаунт.',
            'canonical_user_id.in' => 'Основной аккаунт должен входить в проверяемую пару.',
            'confirm_merge.required' => 'Подтвердите, что вы проверили оба аккаунта.',
            'confirm_merge.accepted' => 'Подтвердите, что вы проверили оба аккаунта.',
            'confirm_privileged.required' => 'Отдельно подтвердите объединение аккаунта с расширенными правами.',
            'confirm_privileged.accepted' => 'Отдельно подтвердите объединение аккаунта с расширенными правами.',
        ]);

        if ($validator->fails()) {
            $attemptLogger->mergeFailed(
                candidate: $userDuplicate,
                request: $request,
                reasonType: 'validation',
                message: 'Запрос на объединение не прошёл проверку.',
                validationFields: $validator->errors()->keys(),
            );

            return redirect()
                ->route('admin.users.duplicates')
                ->withErrors($validator)
                ->withInput()
                ->with('merge_error_messages', $validator->errors()->all())
                ->with('open_user_duplicate_id', $userDuplicate->id);
        }

        $validated = $validator->validated();

        try {
            $resolve->merge(
                candidate: $userDuplicate,
                canonicalUserId: (int) $validated['canonical_user_id'],
                resolvedBy: $request->user(),
            );
        } catch (InvalidArgumentException $exception) {
            $attemptLogger->mergeFailed(
                candidate: $userDuplicate,
                request: $request,
                reasonType: 'domain',
                message: $exception->getMessage(),
            );

            return redirect()
                ->route('admin.users.duplicates')
                ->withErrors(['merge' => $exception->getMessage()])
                ->withInput()
                ->with('merge_error_messages', [$exception->getMessage()])
                ->with('open_user_duplicate_id', $userDuplicate->id);
        }

        return redirect()->route('admin.users.duplicates')->with('success', 'Аккаунты объединены.');
    }

    public function reject(
        Request $request,
        UserDuplicate $userDuplicate,
        ResolveUserDuplicateHandler $resolve,
    ): RedirectResponse {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $resolve->reject(
                candidate: $userDuplicate,
                resolvedBy: $request->user(),
                reason: $validated['reason'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            return redirect()->route('admin.users.duplicates')->with('error', $exception->getMessage());
        }

        return redirect()->route('admin.users.duplicates')->with('success', 'Кандидат отклонён.');
    }
}
