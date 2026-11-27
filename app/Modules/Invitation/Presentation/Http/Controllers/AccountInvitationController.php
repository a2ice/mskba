<?php

namespace App\Modules\Invitation\Presentation\Http\Controllers;

use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Application\DTO\PrivacyConsentDTO;
use App\Modules\Identity\Application\Services\UsernameSuggestionService;
use Illuminate\Database\UniqueConstraintViolationException;
use App\Modules\Identity\Application\UseCases\RegisterUserHandler;
use App\Modules\Identity\Application\UseCases\AuthHandler;
use App\Modules\Invitation\Presentation\Http\Requests\InvitationRegisterRequest;
use App\Modules\Invitation\Application\Services\AccountInvitationManager;
use App\Modules\Invitation\Domain\Models\AccountInvitation;
use App\Modules\Venue\Domain\Models\Venue;
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
        if ($request->session()->get('account_invitation_token') !== $token) {
            $request->session()->forget('account_invitation_started');
        }
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

        $started = $request->session()->get('account_invitation_started') === $invitation->token_hash
            || $request->session()->has('errors');
        $suggestedUsername = $started && $current === null && $invitation->canManageLink() && ! $invitation->isExpired()
            ? app(UsernameSuggestionService::class)->suggest($invitation->contact_value) : null;

        return response()->view('invitations.show', [
            'invitation' => $invitation,
            'token' => $token,
            'currentUser' => $current,
            'started' => $started,
            'suggestedUsername' => $suggestedUsername,
            'portalContacts' => $manager->publicPortalContacts(),
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
        if ($manager->markInterested($invitation)) {
            $request->session()->put('account_invitation_started', $invitation->token_hash);
        }

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
        $suggestions = app(UsernameSuggestionService::class);
        if (! $suggestions->isAvailable($data['username'])) {
            throw ValidationException::withMessages(['username' => 'Этот логин уже занят. Проверьте предложенный логин или выберите свой.'])
                ->redirectTo(route('account-invitations.continue'));
        }
        $systemPassword = $request->usesSystemPassword();
        $password = $systemPassword ? bin2hex(random_bytes(16)).'Aa1!' : $data['password'];
        try {
            $user = DB::transaction(function () use ($data, $request, $register, $manager, $invitation, $systemPassword, $password) {
                $created = $register->handle(
                    username: $data['username'],
                    password: $password,
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
                if ($systemPassword) {
                    $created->forceFill(['is_temporary_password' => true])->save();
                }
                $manager->accept($invitation, $created, true);
                return $created;
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($suggestions->isAvailable($data['username'])) {
                throw $e;
            }
            throw ValidationException::withMessages(['username' => 'Этот логин уже занят. Проверьте новый предложенный логин или выберите свой.'])
                ->redirectTo(route('account-invitations.continue'));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['username' => $e->getMessage()])
                ->redirectTo(route('account-invitations.continue'));
        }

        // Reuse the normal browser login path: canonical identity resolution,
        // first-login tracking and session regeneration remain consistent.
        $login = $authHandler->login($user->username, $password, false);
        abort_if($login->status === 'error', 401);

        $sent = $manager->sendAcceptanceNotice($invitation, $user, $systemPassword ? $password : null);
        $response = redirect()->route('account-invitations.continue')
            ->with('status', 'Аккаунт создан, приглашение принято. Email подтверждён.');
        if (! $sent) {
            $response->with('warning', $systemPassword
                ? 'Не удалось отправить письмо с паролем. Вы уже вошли: установите свой пароль в личном кабинете или воспользуйтесь восстановлением доступа.'
                : 'Не удалось отправить письмо с дальнейшими шагами. Продолжите подключение в личном кабинете.');
        } elseif ($systemPassword) {
            $response->with('warning', 'Вы вошли с системным паролем. Рекомендуем установить свой пароль в настройках аккаунта.');
        }

        return $response;
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
            $invitation = $manager->findByToken($token);
            [$accepted, $firstAcceptance] = DB::transaction(function () use ($manager, $invitation, $user): array {
                Venue::query()->whereKey($invitation->scope_id)->lockForUpdate()->firstOrFail();
                $current = AccountInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
                $first = $current->accepted_user_id === null;
                // Pass the original token revision, so rotation between reads is rejected.
                return [$manager->accept($invitation, $user), $first];
            });
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['accept_invitation' => $e->getMessage()])
                ->redirectTo(route('account-invitations.continue'));
        }

        $response = redirect()->route('account-invitations.continue')
            ->with('status', 'Приглашение принято. Указанный email подтверждён.');
        if ($firstAcceptance && ! $manager->sendAcceptanceNotice($accepted, $user->canonical())) {
            $response->with('warning', 'Не удалось отправить письмо с дальнейшими шагами. Продолжите подключение в личном кабинете.');
        }

        return $response;
    }
}
