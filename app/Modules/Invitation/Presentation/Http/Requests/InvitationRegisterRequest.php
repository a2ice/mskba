<?php

namespace App\Modules\Invitation\Presentation\Http\Requests;

use App\Modules\Identity\Presentation\Http\Requests\RegisterRequest;

/**
 * Invitation forms must never fall back to the last visited asset URL when
 * validation fails (e.g. because the page uses a restrictive referrer policy).
 */
final class InvitationRegisterRequest extends RegisterRequest
{
    protected $redirectRoute = 'account-invitations.continue';

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
