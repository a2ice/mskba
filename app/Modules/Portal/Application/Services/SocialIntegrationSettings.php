<?php

namespace App\Modules\Portal\Application\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class SocialIntegrationSettings
{
    private const MAP = [
        'telegram' => [
            'bot_token' => 'telegram.bot_token',
            'bot_username' => 'telegram.bot_username',
            'bot_domain' => 'telegram.bot_domain',
            'main_chat_id' => 'telegram.main_chat_id',
            'webhook_secret' => 'telegram.webhook_secret',
            'community_url' => 'services.social.telegram_url',
        ],
        'vk' => [
            'community_url' => 'services.social.vk_url',
            'group_id' => 'vk.wall.group_id',
            'community_token' => 'vk.wall.community_token',
            'user_token' => 'vk.wall.user_token',
            'user_token_expires_at' => 'vk.wall.user_token_expires_at',
            'mini_app_id' => 'vk.wall.mini_app_id',
            'mini_app_secret' => 'vk.wall.mini_app_secret',
            'enabled' => 'vk.wall.enabled',
        ],
    ];

    private const SECRETS = ['bot_token', 'webhook_secret', 'community_token', 'user_token', 'mini_app_secret'];

    private function scope(): string
    {
        return hash('sha256', config('app.env').'|'.parse_url((string) config('app.url'), PHP_URL_HOST));
    }

    /** Refresh at HTTP, queue-job and polling boundaries; never in config:cache. */
    public function apply(): void
    {
        // Fresh installs and rollback must still boot before the migration exists.
        if (! Schema::hasTable('social_integration_settings')) {
            return;
        }
        $rows = DB::table('social_integration_settings')->where('scope', $this->scope())->get();
        foreach ($rows as $row) {
            $map = self::MAP[$row->provider] ?? [];
            $settings = json_decode(Crypt::decryptString($row->encrypted_settings), true, 512, JSON_THROW_ON_ERROR);
            foreach ($map as $field => $key) {
                if (array_key_exists($field, $settings)) {
                    config([$key => $settings[$field]]);
                }
            }
        }
    }

    private function current(string $provider): array
    {
        $map = self::MAP[$provider] ?? throw new RuntimeException('Unknown social provider.');
        return array_map(fn (string $key) => config($key), $map);
    }

    /** Read only community URLs directly, including CLI/queued mail without HTTP middleware. */
    public function publicCommunityUrls(): array
    {
        $urls = [
            'telegram_url' => trim((string) config('services.social.telegram_url', '')),
            'vk_url' => trim((string) config('services.social.vk_url', '')),
        ];
        if (! Schema::hasTable('social_integration_settings')) {
            return $urls;
        }
        $rows = DB::table('social_integration_settings')->where('scope', $this->scope())
            ->whereIn('provider', ['telegram', 'vk'])->get(['provider', 'encrypted_settings']);
        foreach ($rows as $row) {
            $stored = json_decode(Crypt::decryptString($row->encrypted_settings), true, 512, JSON_THROW_ON_ERROR);
            if (array_key_exists('community_url', $stored)) {
                $urls[$row->provider.'_url'] = trim((string) $stored['community_url']);
            }
        }

        return $urls;
    }

    /** Never return raw secrets to a view or Livewire component. */
    public function display(string $provider): array
    {
        $settings = $this->current($provider);
        foreach (self::SECRETS as $field) {
            if (array_key_exists($field, $settings)) {
                $settings[$field] = filled($settings[$field]);
            }
        }
        return $settings;
    }

    /** Preserve blank secrets; first save imports current environment defaults. */
    public function save(string $provider, array $changes, int $actorId): void
    {
        $map = self::MAP[$provider] ?? throw new RuntimeException('Unknown social provider.');
        DB::transaction(function () use ($provider, $map, $changes, $actorId): void {
            // Serialize first insertion too, without locking unrelated settings.
            $scope = $this->scope();
            $initial = $this->current($provider);
            DB::table('social_integration_settings')->insertOrIgnore([
                'scope' => $scope, 'provider' => $provider,
                'encrypted_settings' => Crypt::encryptString(json_encode($initial, JSON_THROW_ON_ERROR)),
                'updated_by_user_id' => $actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $query = DB::table('social_integration_settings')->where('scope', $scope)->where('provider', $provider);
            $row = $query->lockForUpdate()->first();
            $values = json_decode(Crypt::decryptString($row->encrypted_settings), true, 512, JSON_THROW_ON_ERROR);
            foreach ($map as $field => $key) {
                if (! array_key_exists($field, $changes)) {
                    continue;
                }
                if (in_array($field, self::SECRETS, true) && blank($changes[$field])) {
                    continue;
                }
                $values[$field] = $changes[$field];
            }
            $query->update([
                'encrypted_settings' => Crypt::encryptString(json_encode($values, JSON_THROW_ON_ERROR)),
                'updated_by_user_id' => $actorId, 'updated_at' => now(),
            ]);
        });
        $this->apply();
    }
}
