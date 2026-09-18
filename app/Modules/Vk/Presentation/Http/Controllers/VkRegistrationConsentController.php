<?php

namespace App\Modules\Vk\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\DTO\PrivacyConsentDTO;
use App\Modules\Vk\Application\DTO\VkUserIdentityDTO;
use App\Modules\Vk\Application\UseCases\CompleteVkAuthenticationHandler;
use App\Modules\Vk\Application\UseCases\ResolveVkUserHandler;
use App\Presentation\Theming\ThemeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

final class VkRegistrationConsentController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        if ($this->pending($request) === null) {
            return redirect()->route('login')->with('error', 'Сессия регистрации через VK ID истекла. Начните вход заново.');
        }

        return app(ThemeResolver::class)->page('auth.vk-consent');
    }

    public function store(
        Request $request,
        ResolveVkUserHandler $resolveUser,
        CompleteVkAuthenticationHandler $authenticate,
    ): RedirectResponse {
        $request->validate([
            'privacy_consent' => ['required', 'accepted'],
        ]);

        $pending = $this->pending($request);

        if ($pending === null) {
            return redirect()->route('login')->with('error', 'Сессия регистрации через VK ID истекла. Начните вход заново.');
        }

        $identityData = $pending['identity'];

        try {
            $result = $resolveUser->handle(
                new VkUserIdentityDTO(
                    id: (string) $identityData['id'],
                    firstName: $identityData['first_name'] ?? null,
                    lastName: $identityData['last_name'] ?? null,
                    avatarUrl: $identityData['avatar_url'] ?? null,
                    rawData: is_array($identityData['raw_data'] ?? null) ? $identityData['raw_data'] : [],
                    gender: $identityData['gender'] ?? null,
                    birthDate: $identityData['birth_date'] ?? null,
                ),
                new PrivacyConsentDTO(
                    documentVersion: (string) config('legal.personal_data_consent_version'),
                    acceptedAt: CarbonImmutable::now(),
                    source: 'vk_id_registration',
                    ipAddress: $request->ip(),
                    userAgent: $request->userAgent(),
                ),
            );
            $authenticate->handle($result['user']);
        } catch (InvalidArgumentException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        }

        $request->session()->forget('vk.pending_registration');

        return redirect()->to((string) $pending['redirect_url'])
            ->with('success', 'Аккаунт создан. Вы вошли через VK ID.');
    }

    /** @return array{identity: array<string, mixed>, redirect_url: string, expires_at: int}|null */
    private function pending(Request $request): ?array
    {
        $pending = $request->session()->get('vk.pending_registration');

        if (! is_array($pending)
            || ! is_array($pending['identity'] ?? null)
            || ! is_string($pending['redirect_url'] ?? null)
            || ! is_numeric($pending['expires_at'] ?? null)
            || (int) $pending['expires_at'] < now()->timestamp) {
            $request->session()->forget('vk.pending_registration');

            return null;
        }

        return $pending;
    }
}
