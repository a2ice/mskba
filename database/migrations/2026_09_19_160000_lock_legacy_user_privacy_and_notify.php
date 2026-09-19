<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $userIds = DB::table('users')
            ->whereNull('canonical_user_id')
            ->whereNull('deleted_at')
            ->where('status', '!=', 'blocked')
            ->whereNull('personal_data_distribution_setup_completed_at')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($userIds === []) {
            return;
        }

        $privacyTypes = [
            'discoverability',
            'contacts',
            'messages',
            'group_invitations',
            'profile',
            'avatar',
            'role_player',
            'role_coach',
            'role_referee',
            'role_statistician',
            'role_media',
            'role_venue_related',
            'role_organizer',
            'player_characteristics',
            'player_teams',
            'player_sections',
            'player_games',
            'player_tournaments',
            'coach_sections',
            'media_materials',
            'venue_venues',
            'referee_events',
            'statistician_events',
        ];

        DB::transaction(function () use ($userIds, $privacyTypes, $now): void {
            DB::table('users')
                ->whereIn('id', $userIds)
                ->whereNull('personal_data_distribution_required_at')
                ->update([
                    'personal_data_distribution_required_at' => $now,
                    'updated_at' => $now,
                ]);

            $privacyRows = [];
            foreach ($userIds as $userId) {
                foreach ($privacyTypes as $type) {
                    $privacyRows[] = [
                        'user_id' => $userId,
                        'type' => $type,
                        'visibility' => 'nobody',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            foreach (array_chunk($privacyRows, 500) as $chunk) {
                DB::table('user_privacy_settings')->upsert(
                    $chunk,
                    ['user_id', 'type'],
                    ['visibility', 'updated_at'],
                );
            }

            $privacySettingIds = DB::table('user_privacy_settings')
                ->whereIn('user_id', $userIds)
                ->pluck('id');

            DB::table('user_privacy_setting_allowed_users')
                ->whereIn('privacy_setting_id', $privacySettingIds)
                ->delete();

            foreach ($userIds as $userId) {
                $alreadyNotified = DB::table('user_notifications')
                    ->where('user_id', $userId)
                    ->where('title', 'Проверьте настройки приватности')
                    ->where('action_url', '/account/settings')
                    ->exists();

                if ($alreadyNotified) {
                    continue;
                }

                DB::table('user_notifications')->insert([
                    'user_id' => $userId,
                    'type' => 'profile',
                    'status' => 'new',
                    'title' => 'Проверьте настройки приватности',
                    'body' => 'Мы перевели ваш профиль и взаимодействия с другими участниками в закрытый режим. Если вы хотите отображаться в каталоге участников, получать приглашения и участвовать в связанных с профилем возможностях MSKBA, откройте настройки аккаунта и включите только те разрешения, которые вам нужны.',
                    'action_url' => '/account/settings',
                    'action_text' => 'Настроить приватность',
                    'payload' => json_encode([
                        'source' => 'identity.privacy_review_required',
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'read_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Intentional no-op: this is a one-time privacy hardening migration.
        // Reverting it could reopen data a user expects to remain private.
    }
};
