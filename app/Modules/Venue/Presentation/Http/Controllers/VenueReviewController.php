<?php

namespace App\Modules\Venue\Presentation\Http\Controllers;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class VenueReviewController
{
    public function index(Request $request, Venue $venue): JsonResponse
    {
        abort_unless($venue->isPubliclyVisible(), 404);
        $viewer = $request->user()?->canonical();
        $mine = $request->boolean('mine');
        abort_if($mine && $viewer === null, 401);
        $ids = $viewer?->identityIds() ?? [];
        $data = $request->validate(['before' => ['nullable', 'integer', 'min:1']]);
        $query = $venue->reviews()->with('user.profile')
            ->when($mine, fn ($q) => $q->whereIn('user_id', $ids),
                fn ($q) => $q->where('is_published', true))
            ->when(isset($data['before']), fn ($q) => $q->where('id', '<', $data['before']))
            ->orderByDesc('id')->limit(11)->get();
        $hasMore = $query->count() > 10;
        $reviews = $query->take(10)->values();

        return response()->json([
            'reviews' => $reviews->map(function (VenueReview $review) use ($ids): array {
                $user = $review->user;
                $own = in_array((int) $review->user_id, $ids, true);

                return [
                    'id' => $review->id,
                    'rating' => $review->rating,
                    'body' => $review->body,
                    'author' => $user?->nickname ?: ($user?->username ?: 'Игрок'),
                    'avatar' => $user?->profile?->avatarUrl(),
                    'published' => (bool) $review->is_published,
                    'own' => $own,
                ];
            }),
            'next' => $hasMore ? $reviews->last()->id : null,
            'rating' => $this->rating($venue),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Venue $venue): JsonResponse
    {
        $user = $this->reviewer($request, $venue);
        $data = $this->validated($request);
        $review = DB::transaction(function () use ($user, $venue, $data): VenueReview {
            // Serialize submissions by canonical identity, including concurrent tabs.
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_if($locked->isBlocked(), 403);
            $last = $venue->reviews()->withTrashed()->whereIn('user_id', $locked->identityIds())
                ->latest('created_at')->first();
            if ($last && $last->created_at->gt(now()->subMinutes(5))) {
                throw ValidationException::withMessages([
                    'rating' => 'Повторную оценку можно оставить через 5 минут после предыдущей.',
                ]);
            }
            $published = $data['body'] === null;

            return $venue->reviews()->create($data + [
                'user_id' => $locked->id,
                'is_published' => $published,
                'published_at' => $published ? now() : null,
            ]);
        });

        return response()->json(['id' => $review->id, 'published' => $review->is_published,
            'rating' => $this->rating($venue)], 201);
    }

    public function update(Request $request, Venue $venue, int $review): JsonResponse
    {
        $user = $this->reviewer($request, $venue);
        $data = $this->validated($request);
        DB::transaction(function () use ($user, $venue, $review, $data): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_if($locked->isBlocked(), 403);
            $record = $venue->reviews()->whereIn('user_id', $locked->identityIds())
                ->lockForUpdate()->findOrFail($review);
            // Any revision returns to moderation; Auditable records old and new values.
            $record->update($data + ['is_published' => false, 'published_at' => null]);
        });

        return response()->json(['published' => false, 'rating' => $this->rating($venue)]);
    }

    private function reviewer(Request $request, Venue $venue): User
    {
        abort_unless($venue->isPubliclyVisible(), 404);
        $user = $request->user()?->canonical();
        abort_unless($user instanceof User, 401);
        abort_if($user->isBlocked(), 403);

        return $user;
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'body' => ['nullable', 'string', 'max:3000'],
        ]);
        $body = trim((string) ($data['body'] ?? ''));

        return ['rating' => (int) $data['rating'], 'body' => $body === '' ? null : $body];
    }

    private function rating(Venue $venue): array
    {
        $query = $venue->reviews()->where('is_published', true);
        $average = (clone $query)->avg('rating');

        return ['value' => $average === null ? null : round((float) $average, 1),
            'count' => $query->count()];
    }
}
