<?php

namespace App\Modules\Portal\Application\Services;

use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Portal\Domain\Models\PortalContactSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class PortalContactSettings
{
    private function scope(): string
    {
        return hash('sha256', config('app.env').'|'.parse_url((string) config('app.url'), PHP_URL_HOST));
    }

    public function canManage(?User $actor): bool
    {
        $actor = $actor?->canonical()->fresh();

        return $actor !== null && $actor->system_role === UserSystemRoleEnum::SUPERADMIN
            && $actor->isConfirmed() && ! $actor->isBlocked() && ! $actor->trashed();
    }

    private function values(): array
    {
        // Preserve the existing configured contacts until an explicit save.
        // Never fall back for an explicitly hidden or empty saved field.
        $row = Schema::hasTable('portal_contact_settings')
            ? PortalContactSetting::query()->where('scope', $this->scope())->first()
            : null;
        $email = trim((string) config('support.email', ''));
        $values = $row?->only(['email', 'phone', 'email_public', 'phone_public', 'telegram_public', 'vk_public'])
            ?? ['email' => $email !== '' ? $email : null, 'phone' => null,
                'email_public' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false, 'phone_public' => false,
                'telegram_public' => true, 'vk_public' => true];

        // Social URLs have ONE source of truth, shared with the integrations
        // editor. The public flags do not disable bots, publishing or login.
        return $values + app(SocialIntegrationSettings::class)->publicCommunityUrls();
    }

    public function adminValues(?User $actor): array
    {
        abort_unless($this->canManage($actor), 403);

        return $this->values();
    }

    /** @return array<string, array{value: string, href: string}> */
    public function publicByChannel(): array
    {
        $values = $this->values();
        $contacts = [];
        if ($values['email_public'] && filter_var($values['email'], FILTER_VALIDATE_EMAIL) !== false) {
            $contacts['email'] = ['value' => $values['email'], 'href' => 'mailto:'.$values['email']];
        }
        if ($values['phone_public'] && $values['phone'] !== null) {
            $normalized = $this->normalizePhone($values['phone']);
            if ($normalized !== null) {
                $contacts['phone'] = ['value' => $values['phone'], 'href' => 'tel:'.$normalized];
            }
        }
        foreach (['telegram' => 'Telegram', 'vk' => 'ВКонтакте'] as $channel => $label) {
            $url = $values[$channel.'_url'];
            if ($values[$channel.'_public'] && $this->validSocialUrl($channel, $url)) {
                $contacts[$channel] = ['value' => $label, 'href' => $url];
            }
        }

        return $contacts;
    }

    /** @return list<array{value: string, href: string}> */
    public function publicContacts(): array
    {
        return array_values($this->publicByChannel());
    }

    /** @return list<string> Public social profiles for schema.org sameAs. */
    public function publicSocialUrls(): array
    {
        $contacts = array_intersect_key($this->publicByChannel(), array_flip(['telegram', 'vk']));

        return array_values(array_column($contacts, 'href'));
    }

    public function publicEmail(): ?array
    {
        return $this->publicByChannel()['email'] ?? null;
    }

    public function save(User $actor, array $input): void
    {
        abort_unless($this->canManage($actor), 403);
        $data = Validator::make($input, [
            'email' => ['nullable', 'string', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:40'],
            'telegram_url' => ['nullable', 'string', 'max:255'],
            'vk_url' => ['nullable', 'string', 'max:255'],
            'email_public' => ['required', 'boolean'],
            'phone_public' => ['required', 'boolean'],
            'telegram_public' => ['required', 'boolean'],
            'vk_public' => ['required', 'boolean'],
        ])->validate();
        $email = trim((string) ($data['email'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['email' => 'Укажите корректный email.']);
        }
        if ($phone !== '' && $this->normalizePhone($phone) === null) {
            throw ValidationException::withMessages(['phone' => 'Укажите телефон с кодом страны, например +7 (900) 000-00-00.']);
        }
        $urls = [];
        foreach (['telegram', 'vk'] as $channel) {
            $url = trim((string) ($data[$channel.'_url'] ?? ''));
            if ($url !== '' && ! $this->validSocialUrl($channel, $url)) {
                throw ValidationException::withMessages([$channel.'_url' => 'Укажите официальный HTTPS-адрес сообщества.']);
            }
            $urls[$channel] = $url;
        }
        $social = app(SocialIntegrationSettings::class);
        // Keep only the public address editable here; integration credentials
        // are preserved by the existing encrypted settings service.
        try {
            DB::transaction(function () use ($actor, $data, $email, $phone, $urls, $social): void {
                $actor = User::query()->whereKey($actor->canonical()->id)->lockForUpdate()->firstOrFail();
                abort_unless($this->canManage($actor), 403);
                $scope = $this->scope();
                DB::table('portal_contact_settings')->insertOrIgnore([
                    'scope' => $scope, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $row = PortalContactSetting::query()->where('scope', $scope)->lockForUpdate()->firstOrFail();
                $currentUrls = $social->publicCommunityUrls();
                foreach ($urls as $channel => $url) {
                    if ($url !== $currentUrls[$channel.'_url']) {
                        $social->save($channel, ['community_url' => $url !== '' ? $url : null], $actor->id);
                    }
                }
                $row->update([
                    'email' => $email !== '' ? $email : null,
                    'phone' => $phone !== '' ? $phone : null,
                    'email_public' => $email !== '' && (bool) $data['email_public'],
                    'phone_public' => $phone !== '' && (bool) $data['phone_public'],
                    'telegram_public' => $urls['telegram'] !== '' && (bool) $data['telegram_public'],
                    'vk_public' => $urls['vk'] !== '' && (bool) $data['vk_public'],
                    'updated_by_user_id' => $actor->id,
                ]);
            }, 3);
        } catch (\Throwable $exception) {
            // The social service applies runtime config; restore it if an
            // outer transaction rolls back.
            $social->apply();
            throw $exception;
        }
    }

    private function validSocialUrl(string $channel, string $url): bool
    {
        return preg_match(match ($channel) {
            'telegram' => '~\Ahttps://t\.me/[a-zA-Z0-9_]+\z~',
            'vk' => '~\Ahttps://vk\.(?:ru|com)/[a-zA-Z0-9_.-]+\z~',
        }, $url) === 1;
    }

    private function normalizePhone(string $phone): ?string
    {
        if (! preg_match('/^\+[0-9 ()-]+$/D', $phone)) {
            return null;
        }
        $normalized = preg_replace('/[ ()-]/', '', $phone);

        return preg_match('/^\+[1-9][0-9]{6,14}$/D', $normalized) ? $normalized : null;
    }
}
