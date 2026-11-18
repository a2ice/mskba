<?php

namespace App\Modules\Invitation\Presentation\Http\Controllers;

use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Application\DTO\PrivacyConsentDTO;
use App\Modules\Identity\Application\UseCases\RegisterUserHandler;
use App\Modules\Identity\Presentation\Http\Requests\RegisterRequest;
use App\Modules\Invitation\Application\Services\AccountInvitationManager;
use App\Modules\Invitation\Domain\Models\AccountInvitation;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class AccountInvitationController
{
    public function show(Request $request, string $token, AccountInvitationManager $manager): Response
    {
        $invitation = $manager->findByToken($token);
        $manager->seen($invitation);
        $invitation->refresh();

        $current = $request->user()?->canonical();
        if ($current !== null && $invitation->accepted_user_id !== null
            && $current->isSameIdentity((int) $invitation->accepted_user_id)) {
            $invitation = $manager->advance($invitation, $current);
        }

        return response()->view('invitations.show', [
            'invitation' => $invitation,
            'token' => $token,
            'currentUser' => $current,
        ])->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ]);
    }

    public function register(
        RegisterRequest $request, string $token, AccountInvitationManager $manager,
        RegisterUserHandler $register
    ): RedirectResponse {
        $invitation = $manager->findByToken($token);
        abort_unless($invitation->status === 'pending'
            && ! $invitation->isExpired() && $invitation->accepted_user_id === null, 410);
        if ($request->user() !== null) {
            return redirect()->route('account-invitations.show', $token)
                ->with('warning', 'Вы уже вошли в аккаунт. Примите приглашение для текущего пользователя.');
        }
        $request->validate(['accept_invitation' => ['accepted']]);

        // An invited email belonging to any existing identity must be attached
        // to that identity after login, not used to produce a duplicate user.
        if (Contact::query()->withTrashed()
            ->where('user_email_key', $invitation->contact_value)->exists()) {
            throw ValidationException::withMessages([
                'username' => 'Этот email уже связан с аккаунтом. Войдите в него и примите приглашение.',
            ]);
        }

        $data = $request->validated();
        try {
            $user = DB::transaction(function () use ($data, $request, $register, $manager, $invitation) {
                $created = $register->handle(
                    username: $data['username'],
                    password: $data['password'],
                    participantRole: null,
                    profile: $request->profile(),
                    privacyConsent: new PrivacyConsentDTO(
                        documentVersion: (string) config('legal.personal_data_consent_version'),
                        acceptedAt: CarbonImmutable::now(),
                        source: 'site_invitation',
                        ipAddress: $request->ip(),
                        userAgent: $request->userAgent(),
                    ),
                );
                $manager->accept($invitation, $created, true);
                return $created;
            });
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['username' => $e->getMessage()]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('account-invitations.show', $token)
            ->with('status', 'Аккаунт создан, приглашение принято. Email подтверждён.');
    }

    public function accept(Request $request, string $token, AccountInvitationManager $manager): RedirectResponse
    {
        $request->validate(['accept_invitation' => ['accepted']]);
        $user = $request->user();
        abort_unless($user !== null, 401);

        try {
            $manager->accept($manager->findByToken($token), $user);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['accept_invitation' => $e->getMessage()]);
        }

        return redirect()->route('account-invitations.show', $token)
            ->with('status', 'Приглашение принято. Указанный email подтверждён.');
    }
}
