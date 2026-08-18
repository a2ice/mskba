<?php

namespace Tests\Feature\Reaction;

use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use App\Modules\Telegram\Domain\Models\TelegramChat;
use App\Modules\Telegram\Domain\Models\TelegramContentPublication;
use App\Modules\Telegram\Infrastructure\Jobs\ProcessTelegramReactionJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class TelegramReactionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'telegram.bot_token' => '123456:test-token',
            'telegram.webhook_secret' => 'reaction-webhook-secret',
            'telegram.api_ip' => null,
            'telegram.http_proxy' => null,
        ]);
    }

    public function test_webhook_queues_message_reaction_update(): void
    {
        Queue::fake();
        $reaction = $this->reactionPayload(777, 501, now()->timestamp, '🔥');

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'reaction-webhook-secret')
            ->postJson(route('integrations.telegram.webhook'), [
                'update_id' => 9001,
                'message_reaction' => $reaction,
            ])
            ->assertOk();

        Queue::assertPushed(
            ProcessTelegramReactionJob::class,
            fn (ProcessTelegramReactionJob $job): bool => $job->updateId === 9001
                && data_get($job->reaction, 'user.id') === 777
                && data_get($job->reaction, 'message_id') === 501,
        );
    }

    public function test_polling_queues_message_reaction_update(): void
    {
        config([
            'telegram.updates_transport' => 'polling',
            'telegram.polling_timeout' => 1,
        ]);
        Queue::fake();
        $reaction = $this->reactionPayload(888, 502, now()->timestamp, '❤');

        Http::fake([
            'https://api.telegram.org/bot123456:test-token/getUpdates' => Http::response([
                'ok' => true,
                'result' => [[
                    'update_id' => 9101,
                    'message_reaction' => $reaction,
                ]],
            ]),
        ]);

        $this->artisan('telegram:poll-updates', ['--once' => true])
            ->expectsOutput('Telegram updates processed: 1')
            ->assertSuccessful();

        Queue::assertPushed(
            ProcessTelegramReactionJob::class,
            fn (ProcessTelegramReactionJob $job): bool => $job->updateId === 9101
                && data_get($job->reaction, 'user.id') === 888,
        );
        Http::assertSent(fn ($request): bool => $request->url()
            === 'https://api.telegram.org/bot123456:test-token/getUpdates'
            && in_array('message_reaction', $request['allowed_updates'], true));
    }

    public function test_unlinked_telegram_user_updates_one_external_vote(): void
    {
        [$content] = $this->publishedContent();
        $baseTimestamp = now()->timestamp;

        $this->runReaction($this->reactionPayload(777, 501, $baseTimestamp, '❤'), 1001);

        $this->assertDatabaseHas('reactions', [
            'subject_type' => 'content',
            'subject_id' => $content->id,
            'actor_type' => 'telegram',
            'actor_id' => '777',
            'user_id' => null,
            'value' => 1,
            'source' => 'telegram',
            'source_sequence' => 1001,
        ]);

        $this->runReaction($this->reactionPayload(777, 501, $baseTimestamp + 10, '💩'), 1002);

        $this->assertDatabaseHas('reactions', [
            'subject_type' => 'content',
            'subject_id' => $content->id,
            'actor_type' => 'telegram',
            'actor_id' => '777',
            'value' => -1,
            'source_sequence' => 1002,
        ]);
        $this->assertSame(1, DB::table('reactions')->count());

        $payload = $this->reactionPayload(777, 501, $baseTimestamp + 20, null);
        $payload['new_reaction'] = [['type' => 'emoji', 'emoji' => '🤔']];
        $this->runReaction($payload, 1003);

        $this->assertDatabaseHas('reactions', [
            'subject_type' => 'content',
            'subject_id' => $content->id,
            'actor_type' => 'telegram',
            'actor_id' => '777',
            'value' => 0,
            'source_sequence' => 1003,
        ]);
    }

    public function test_linked_telegram_and_web_share_one_actor_and_stale_update_cannot_win(): void
    {
        [$content] = $this->publishedContent();
        $baseTimestamp = now()->subMinute()->timestamp;
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        TelegramAccount::query()->create([
            'user_id' => $user->id,
            'telegram_user_id' => 777,
            'username' => 'reaction_user',
        ]);

        $this->runReaction($this->reactionPayload(777, 501, $baseTimestamp, '👍'), 2001);

        $this->assertDatabaseHas('reactions', [
            'subject_type' => 'content',
            'subject_id' => $content->id,
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'user_id' => $user->id,
            'value' => 1,
            'source' => 'telegram',
        ]);

        $this->actingAs($user)
            ->putJson(route('reactions.set', [
                'subjectType' => 'content',
                'subjectId' => $content->id,
            ]), ['value' => -1])
            ->assertOk()
            ->assertJson(['viewer_reaction' => -1]);

        $this->runReaction($this->reactionPayload(777, 501, $baseTimestamp + 5, '🔥'), 2002);

        $this->assertDatabaseHas('reactions', [
            'subject_type' => 'content',
            'subject_id' => $content->id,
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'value' => -1,
            'source' => 'web',
        ]);
        $this->assertSame(1, DB::table('reactions')->count());
    }

    /** @return array{ContentItem, TelegramChat, TelegramContentPublication} */
    private function publishedContent(): array
    {
        $author = User::factory()->create();
        $suffix = bin2hex(random_bytes(4));
        $content = ContentItem::query()->create([
            'created_by_user_id' => $author->id,
            'type' => 'material',
            'title' => 'Telegram reaction '.$suffix,
            'alias' => 'telegram-reaction-'.$suffix,
            'short_description' => 'Короткое описание.',
            'full_description' => 'Текст материала.',
            'publish_in_feed' => true,
            'publish_in_telegram' => true,
            'feed_published_at' => now()->subMinute(),
        ]);
        $chat = TelegramChat::query()->create([
            'telegram_chat_id' => -1002136558099,
            'title' => 'MSKBA',
            'type' => 'supergroup',
            'is_active' => true,
            'publishes_coordination' => true,
            'publishes_events' => true,
        ]);
        $publication = TelegramContentPublication::query()->create([
            'content_item_id' => $content->id,
            'chat_id' => $chat->id,
            'message_id' => 501,
            'message_type' => 'text',
            'is_enabled' => true,
            'status' => 'published',
            'published_at' => now(),
        ]);

        return [$content, $chat, $publication];
    }

    /** @return array<string, mixed> */
    private function reactionPayload(
        int $telegramUserId,
        int $messageId,
        int $timestamp,
        ?string $emoji,
    ): array {
        return [
            'chat' => ['id' => -1002136558099, 'type' => 'supergroup'],
            'message_id' => $messageId,
            'user' => ['id' => $telegramUserId, 'is_bot' => false, 'first_name' => 'User'],
            'date' => $timestamp,
            'old_reaction' => [],
            'new_reaction' => $emoji === null ? [] : [['type' => 'emoji', 'emoji' => $emoji]],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function runReaction(array $payload, int $updateId): void
    {
        app()->call([new ProcessTelegramReactionJob($payload, $updateId), 'handle']);
    }
}
