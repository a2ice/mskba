<?php

namespace App\Modules\Invitation\Presentation\Http\Requests;

use App\Modules\Identity\Presentation\Http\Requests\RegisterRequest;
use App\Modules\Identity\Application\Services\UsernameSuggestionService;
use App\Modules\Invitation\Application\Services\AccountInvitationManager;
use Illuminate\Validation\ValidationException;

/**
 * Invitation forms must never fall back to the last visited asset URL when
 * validation fails (e.g. because the page uses a restrictive referrer policy).
 */
final class InvitationRegisterRequest extends RegisterRequest
{
    protected $redirectRoute = 'account-invitations.continue';

    protected function prepareForValidation(): void
    {
        if ($this->has('proposed_username')) {
            $token = (string) $this->session()->get('account_invitation_token', '');
            $invitation = app(AccountInvitationManager::class)->findByToken($token);
            abort_unless($invitation->canManageLink() && ! $invitation->isExpired(), 410);
            if (! $this->boolean('custom_username')) {
                $suggestion = app(UsernameSuggestionService::class)->suggest($invitation->contact_value);
                if ($this->input('proposed_username') !== $suggestion) {
                    throw ValidationException::withMessages([
                        'username' => 'Предложенный логин изменился. Проверьте новый вариант и подтвердите ещё раз.',
                    ])->redirectTo(route('account-invitations.continue'));
                }
                // Never use an inactive custom field instead of the shown suggestion.
                $this->merge(['username' => $suggestion]);
            }
        }

        parent::prepareForValidation();
    }

    public function rules(): array
    {
        return [...parent::rules(), 'accept_invitation' => ['accepted']];
    }

    public function messages(): array
    {
        return [...parent::messages(),
            'accept_invitation.accepted' => 'Подтвердите принятие приглашения.',
        ];
    }
}
