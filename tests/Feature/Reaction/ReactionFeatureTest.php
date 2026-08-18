<?php

namespace Tests\Feature\Reaction;

use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReactionFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_like_switch_and_remove_reaction(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $content = $this->content();
        $url = route('reactions.set', ['subjectType' => 'content', 'subjectId' => $content->id]);

        $this->actingAs($user)
            ->putJson($url, ['value' => 1])
            ->assertOk()
            ->assertExactJson([
                'likes' => 1,
                'dislikes' => 0,
                'viewer_reaction' => 1,
            ]);

        $this->assertDatabaseHas('reactions', [
            'subject_type' => 'content',
            'subject_id' => $content->id,
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'user_id' => $user->id,
            'value' => 1,
            'source' => 'web',
        ]);

        $this->actingAs($user)
            ->putJson($url, ['value' => -1])
            ->assertOk()
            ->assertExactJson([
                'likes' => 0,
                'dislikes' => 1,
                'viewer_reaction' => -1,
            ]);

        $this->assertSame(1, (int) \DB::table('reactions')->count());

        $this->actingAs($user)
            ->putJson($url, ['value' => null])
            ->assertOk()
            ->assertExactJson([
                'likes' => 0,
                'dislikes' => 0,
                'viewer_reaction' => null,
            ]);

        $this->assertDatabaseHas('reactions', [
            'subject_type' => 'content',
            'subject_id' => $content->id,
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'value' => 0,
        ]);
    }

    public function test_reaction_endpoint_requires_auth_valid_value_and_public_subject(): void
    {
        $content = $this->content();
        $url = route('reactions.set', ['subjectType' => 'content', 'subjectId' => $content->id]);

        $this->putJson($url, ['value' => 1])->assertUnauthorized();

        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($user)
            ->putJson($url, ['value' => 2])
            ->assertUnprocessable();

        $unpublished = $this->content(false);
        $this->actingAs($user)
            ->putJson(route('reactions.set', [
                'subjectType' => 'content',
                'subjectId' => $unpublished->id,
            ]), ['value' => 1])
            ->assertNotFound();

        $this->actingAs($user)
            ->putJson(route('reactions.set', [
                'subjectType' => 'venue',
                'subjectId' => 1,
            ]), ['value' => 1])
            ->assertNotFound();
    }

    public function test_feed_renders_aggregate_counts_and_viewer_state(): void
    {
        $content = $this->content();
        $viewer = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $other = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $url = route('reactions.set', ['subjectType' => 'content', 'subjectId' => $content->id]);

        $this->actingAs($viewer)->putJson($url, ['value' => 1])->assertOk();
        $this->actingAs($other)->putJson($url, ['value' => -1])->assertOk();

        $this->actingAs($viewer)
            ->get(route('news.index'))
            ->assertOk()
            ->assertSee('data-reaction-current="1"', false)
            ->assertSee('data-reaction-count="likes">1</span>', false)
            ->assertSee('data-reaction-count="dislikes">1</span>', false);

        $this->actingAs($viewer)
            ->get(route('news.show', $content->alias))
            ->assertOk()
            ->assertSee('data-reaction-current="1"', false)
            ->assertSee('data-reaction-count="likes">1</span>', false)
            ->assertSee('data-reaction-count="dislikes">1</span>', false);
    }

    private function content(bool $published = true): ContentItem
    {
        $author = User::factory()->create();
        $suffix = bin2hex(random_bytes(4));

        return ContentItem::query()->create([
            'created_by_user_id' => $author->id,
            'type' => 'material',
            'title' => 'Reaction test '.$suffix,
            'alias' => 'reaction-test-'.$suffix,
            'short_description' => 'Короткое описание.',
            'full_description' => 'Текст материала.',
            'publish_in_feed' => $published,
            'publish_in_telegram' => false,
            'feed_published_at' => $published ? now()->subMinute() : null,
        ]);
    }
}
