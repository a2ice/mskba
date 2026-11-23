<?php

namespace App\Modules\Content\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Application\Services\PublicUserProfileService;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\League\Domain\Models\League;
use App\Modules\Shop\Application\Services\ShopCatalogService;
use App\Modules\Shop\Domain\Models\ShopProduct;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Venue\Domain\Models\Venue;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class SiteSearchController extends Controller
{
    private const TYPES = [
        'venues' => 'Площадки',
        'events' => 'Мероприятия',
        'teams' => 'Команды',
        'people' => 'Участники',
        'tournaments' => 'Турниры',
        'leagues' => 'Лиги',
        'sections' => 'Секции',
        'news' => 'Новости',
        'shop' => 'Магазин',
    ];

    public function __invoke(Request $request, PublicUserProfileService $profiles, ShopCatalogService $shop): Response
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'types' => ['nullable', 'array', 'max:9'],
            'types.*' => ['required', 'string', 'distinct', Rule::in(array_keys(self::TYPES))],
        ]);
        $search = trim((string) ($validated['q'] ?? ''));
        $chosen = $validated['types'] ?? array_keys(self::TYPES);
        $types = array_values(array_intersect(array_keys(self::TYPES), $chosen));
        $groups = [];

        if ($search !== '') {
            // Escape user wildcards. All searches remain bound through Eloquent's whereLike.
            $like = '%'.addcslashes($search, '%_\\').'%';
            if (in_array('venues', $types, true)) {
                $groups['venues'] = $this->group(
                    'Площадки', route('venues', ['search' => $search]),
                    Venue::query()->whereNull('canonical_venue_id')->where('is_published', true)
                        ->where('status', 'confirmed')
                        ->where(fn (Builder $q) => $q->whereLike('name', $like)
                            ->orWhereLike('raw_address', $like)->orWhereLike('short_description', $like)),
                    fn (Venue $item): array => ['title' => $item->name, 'url' => route('venues.show', $item->routeIdentifier())]
                );
            }
            if (in_array('events', $types, true)) {
                $groups['events'] = $this->group(
                    'Мероприятия', route('events.index', ['q' => $search]),
                    Event::query()->where('status', EventStatusEnum::PUBLISHED->value)
                        ->where('visibility', EventVisibilityEnum::PUBLIC->value)
                        ->where('type', '!=', EventTypeEnum::GAME_TRAINING->value)
                        ->where(fn (Builder $q) => $q->whereLike('title', $like)
                            ->orWhereLike('description', $like)),
                    fn (Event $item): array => ['title' => $item->title, 'url' => route('events.show', $item->routeIdentifier())]
                );
            }
            if (in_array('teams', $types, true)) {
                $groups['teams'] = $this->group(
                    'Команды', route('teams.index', ['q' => $search]),
                    Team::query()->whereNull('temporary_for_event_id')->where('status', 'active')
                        ->where(fn (Builder $q) => $q->whereLike('name', $like)
                            ->orWhereLike('description', $like)),
                    fn (Team $item): array => ['title' => $item->name, 'url' => route('teams.show', $item->routeIdentifier())]
                );
            }
            if (in_array('people', $types, true)) {
                // Enforce exactly the catalog's per-viewer privacy rules. A match on
                // a private name is never emitted unless the public label also matches.
                $viewer = $request->user()?->canonical();
                $people = User::query()->whereNull('canonical_user_id')
                    ->where('status', UserStatusEnum::CONFIRMED->value)
                    ->where(fn (Builder $q) => $q->whereLike('username', $like)
                        ->orWhereLike('nickname', $like)
                        ->orWhereHas('profile', fn (Builder $profile) => $profile
                            ->whereLike('first_name', $like)->orWhereLike('last_name', $like)))
                    ->with(['profile.activeAvatar', 'participationRoles', 'telegramAccount', 'vkAccount'])
                    ->limit(100)->get()
                    ->map(fn (User $user) => $profiles->catalogEntry($user, $viewer))
                    ->filter(fn (?array $item) => $item !== null && (
                        mb_stripos($item['name'], $search) !== false
                        || mb_stripos((string) ($item['nickname'] ?? ''), $search) !== false
                    ))->values();
                $groups['people'] = [
                    'title' => 'Участники', 'url' => route('participants.index', ['q' => $search]),
                    'count' => $people->count(),
                    'items' => $people->take(6)->map(fn (array $item): array => [
                        'title' => $item['name'],
                        'url' => $item['url'],
                    ])->all(),
                ];
            }
            if (in_array('tournaments', $types, true)) {
                $groups['tournaments'] = $this->group(
                    'Турниры', route('tournaments.index', ['query' => $search]),
                    Tournament::query()->whereIn('status', ['confirmed', 'cancelled'])
                        ->whereLike('title', $like),
                    fn (Tournament $item): array => ['title' => $item->title, 'url' => route('tournaments.show', $item->routeIdentifier())]
                );
            }
            if (in_array('leagues', $types, true)) {
                $groups['leagues'] = $this->group(
                    'Лиги', route('leagues.index', ['q' => $search]),
                    League::query()->whereIn('status', ['active', 'archived'])
                        ->where(fn (Builder $q) => $q->whereLike('name', $like)
                            ->orWhereLike('description', $like)),
                    fn (League $item): array => ['title' => $item->name, 'url' => route('leagues.show', $item->alias)]
                );
            }
            if (config('features.sports_sections.enabled') && in_array('sections', $types, true)) {
                $groups['sections'] = $this->group(
                    'Секции', route('sports-sections.index', ['q' => $search]),
                    SportsSection::query()->where('status', 'active')
                        ->where(fn (Builder $q) => $q->whereLike('name', $like)
                            ->orWhereLike('description', $like)),
                    fn (SportsSection $item): array => ['title' => $item->name, 'url' => route('sports-sections.show', $item)]
                );
            }
            if (in_array('news', $types, true)) {
                $groups['news'] = $this->group(
                    'Новости', route('news.index', ['q' => $search]),
                    ContentItem::query()->publishedInFeed()->where('type', '!=', 'system_update')
                        ->where(fn (Builder $q) => $q->whereLike('title', $like)
                            ->orWhereLike('short_description', $like)),
                    fn (ContentItem $item): array => ['title' => $item->title, 'url' => route('news.show', $item->alias)]
                );
            }
            if (in_array('shop', $types, true)) {
                $visibleIds = $shop->visibleCategoryIds();
                $groups['shop'] = $this->group(
                    'Магазин', route('shop.index', ['q' => $search]),
                    ShopProduct::query()->where('status', 'published')
                        ->whereHas('categories', fn (Builder $q) => $q->whereIn('shop_categories.id', $visibleIds))
                        ->where(fn (Builder $q) => $q->whereLike('name', $like)
                            ->orWhereLike('sku', $like)->orWhereLike('description', $like)),
                    fn (ShopProduct $item): array => ['title' => $item->name, 'url' => route('shop.product', $item->slug)]
                );
            }
        }

        return ThemeResolver::page('search.index', [
            'search' => $search,
            'types' => $types,
            'availableTypes' => self::TYPES,
            'groups' => $groups,
        ])->header('Cache-Control', 'private, no-store');
    }

    /**
     * @param Builder<\Illuminate\Database\Eloquent\Model> $query
     * @param callable(\Illuminate\Database\Eloquent\Model): array{title: string, url: string|null} $map
     * @return array{title: string, url: string, count: int, items: array}
     */
    private function group(string $title, string $url, Builder $query, callable $map): array
    {
        return [
            'title' => $title,
            'url' => $url,
            'count' => (clone $query)->count(),
            'items' => (clone $query)->limit(6)->get()->map($map)->all(),
        ];
    }
}
