<?php

namespace App\Modules\Invitation\Presentation\Http\Controllers;

use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Application\DTO\PrivacyConsentDTO;
use App\Modules\Identity\Application\UseCases\RegisterUserHandler;
use App\Modules\Identity\Application\UseCases\AuthHandler;
use App\Modules\Invitation\Presentation\Http\Requests\InvitationRegisterRequest;
use App\Modules\Invitation\Application\Services\AccountInvitationManager;
use App\Modules\Invitation\Domain\Models\AccountInvitation;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class AccountInvitationController
{
    // Exchange the bearer token for a server-side session before rendering the
    // page. Theme scripts, metrics and outbound links never see it in the URL.
    public function entry(Request $request, string $token, AccountInvitationManager $manager): RedirectResponse
    {
        $manager->findByToken($token);
        $request->session()->put('account_invitation_token', $token);

        return redirect()->route('account-invitations.continue')
            ->withHeaders(['Referrer-Policy' => 'no-referrer',
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Robots-Tag' => 'noindex, nofollow, noarchive']);
    }

    public function show(Request $request, AccountInvitationManager $manager): Response
    {
        $token = (string) $request->session()->get('account_invitation_token', '');
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
            'Referrer-Policy' => 'same-origin',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ]);
    }

    public function interest(Request $request, AccountInvitationManager $manager): RedirectResponse
    {
        $token = (string) $request->session()->get('account_invitation_token', '');
        $invitation = $manager->findByToken($token);
        $manager->markInterested($invitation);

        return redirect()->route('account-invitations.continue')->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ]);
    }

    public function register(
        InvitationRegisterRequest $request, AccountInvitationManager $manager,
        RegisterUserHandler $register, AuthHandler $authHandler
    ): RedirectResponse {
        $token = (string) $request->session()->get('account_invitation_token', '');
        $invitation = $manager->findByToken($token);
        abort_unless($invitation->status === 'pending'
            && ! $invitation->isExpired() && $invitation->accepted_user_id === null, 410);
        if ($request->user() !== null) {
            return redirect()->route('account-invitations.continue')
                ->with('warning', 'Вы уже вошли в аккаунт. Примите приглашение для текущего пользователя.');
        }
        // The invitation-specific FormRequest validates explicit acceptance
        // and redirects any registration errors to the token-free continuation.

        // An invited email with verified/historical ownership must be attached
        // to that identity after login, not used to produce a duplicate user.
        if (Contact::query()->withTrashed()
            ->where('user_email_key', $invitation->contact_value)->exists()) {
            throw ValidationException::withMessages([
                'username' => 'Этот email уже связан с аккаунтом. Войдите в него и примите приглашение.',
            ])->redirectTo(route('account-invitations.continue'));
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
            throw ValidationException::withMessages(['username' => $e->getMessage()])
                ->redirectTo(route('account-invitations.continue'));
        }

        // Reuse the normal browser login path: canonical identity resolution,
        // first-login tracking and session regeneration remain consistent.
        $login = $authHandler->login($user->username, $data['password'], false);
        abort_if($login->status === 'error', 401);

        return redirect()->route('account-invitations.continue')
            ->with('status', 'Аккаунт создан, приглашение принято. Email подтверждён.');
    }

    public function accept(Request $request, AccountInvitationManager $manager): RedirectResponse
    {
        try {
            $request->validate(['accept_invitation' => ['accepted']]);
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('account-invitations.continue'));
        }
        $token = (string) $request->session()->get('account_invitation_token', '');
        $user = $request->user();
        abort_unless($user !== null, 401);

        try {
            $manager->accept($manager->findByToken($token), $user);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['accept_invitation' => $e->getMessage()])
                ->redirectTo(route('account-invitations.continue'));
        }

        return redirect()->route('account-invitations.continue')
            ->with('status', 'Приглашение принято. Указанный email подтверждён.');
    }
}
