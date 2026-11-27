<?php

namespace Tests\Feature\Venue;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VenueReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_scores_are_limited_per_account_and_venue_and_text_requires_moderation(): void
    {
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED, 'is_published' => true]);
        $user = User::factory()->create(['username' => 'review_author']);
        $url = route('venues.reviews.store', $venue->id);

        $this->postJson($url, ['rating' => 5])->assertUnauthorized();
        $this->actingAs($user)->postJson($url, ['rating' => 6])->assertUnprocessable();
        $first = $this->postJson($url, ['rating' => 5])->assertCreated()
            ->assertJsonPath('published', true)->assertJsonPath('rating.count', 1)->json('id');
        $this->postJson($url, ['rating' => 4, 'body' => 'Повтор'])->assertUnprocessable();
        $this->travel(5)->minutes();
        $second = $this->postJson($url, ['rating' => 3, 'body' => 'Новый отзыв'])->assertCreated()
            ->assertJsonPath('published', false)->assertJsonPath('rating.count', 1)->json('id');

        $this->getJson(route('venues.reviews.index', $venue->id))->assertOk()
            ->assertJsonCount(1, 'reviews')->assertJsonPath('reviews.0.id', $first);
        $this->getJson(route('venues.reviews.index', ['venue' => $venue->id, 'mine' => 1]))
            ->assertOk()->assertJsonCount(2, 'reviews')->assertJsonPath('reviews.0.id', $second)
            ->assertJsonPath('reviews.0.published', false)->assertJsonPath('reviews.0.own', true);

        $other = User::factory()->create(['username' => 'another_author']);
        $this->actingAs($other)->getJson(route('venues.reviews.index', ['venue' => $venue->id, 'mine' => 1]))
            ->assertOk()->assertJsonCount(0, 'reviews');
        $this->patchJson(route('venues.reviews.update', ['venue' => $venue->id, 'review' => $first]), ['rating' => 1])
            ->assertNotFound();

        config()->set('audit.enabled', true);
        config()->set('audit.ignore_console', false);
        $this->actingAs($user)->patchJson(route('venues.reviews.update', ['venue' => $venue->id, 'review' => $first]),
            ['rating' => 4, 'body' => 'Исправлено'])->assertOk()
            ->assertJsonPath('published', false)->assertJsonPath('rating.count', 0);
        $this->assertDatabaseHas('venue_reviews', [
            'id' => $first, 'rating' => 4, 'body' => 'Исправлено', 'is_published' => false,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => VenueReview::class, 'auditable_id' => $first, 'event' => 'updated',
        ]);
    }

    public function test_public_reviews_have_stable_cursor_and_private_reviews_do_not_leak(): void
    {
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED, 'is_published' => true]);
        $user = User::factory()->create(['username' => 'review_paginator']);
        $published = VenueReview::factory()->count(11)->for($venue)->for($user)->create([
            'rating' => 5, 'is_published' => true,
        ]);
        VenueReview::factory()->for($venue)->for($user)->create([
            'rating' => 1, 'body' => 'Приватный черновик', 'is_published' => false, 'published_at' => null,
        ]);

        $url = route('venues.reviews.index', $venue->id);
        $page = $this->getJson($url)->assertOk()->assertJsonCount(10, 'reviews')
            ->assertJsonPath('rating.count', 11)->assertJsonMissing(['body' => 'Приватный черновик']);
        $this->getJson($url.'?before='.$page->json('next'))->assertOk()
            ->assertJsonCount(1, 'reviews')->assertJsonPath('reviews.0.id', $published->first()->id)
            ->assertJsonPath('next', null);
        $this->getJson($url.'?mine=1')->assertUnauthorized();

        $hidden = Venue::factory()->create(['status' => VenueStatusEnum::UNCONFIRMED, 'is_published' => false]);
        $this->getJson(route('venues.reviews.index', $hidden->id))->assertNotFound();
    }
}
