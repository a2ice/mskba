<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Application\Services\EventVenueInterestService;
use App\Modules\Event\Application\UseCases\CreateEventHandler;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum;
use App\Modules\Event\Domain\Enums\GameScoringTypeEnum;
use App\Modules\Event\Domain\Enums\GameTimingModeEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Application\Services\VerifiedContactOperationalPermissionGranter;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Venue\Domain\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

final class EventFirstCreationController extends Controller
{
    public function store(
        Request $request,
        CurrentActorResolver $actors,
        CreateEventHandler $events,
        EventVenueInterestService $interests,
    ): JsonResponse|RedirectResponse {
        $input = $this->validated($request);
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null || $actor->user_id === null, 403);
        $user = $actor->user?->canonical();
        abort_if($user === null || $user->isBlocked() || $user->trashed(), 403);
        abort_if($user->operationalPermissions()->where('permission', UserOperationalPermissionEnum::CREATE_EVENT->value)->where('is_allowed', false)->exists(), 403);
        $contacts = app(VerifiedContactOperationalPermissionGranter::class);
        if (! $contacts->hasVerifiedContact($user)) {
            $message = 'Для создания мероприятия необходимо иметь подтверждённый контакт';
            if ($request->expectsJson()) {
                $pending = $user->identityContactsQuery()->where('type', 'email')
                    ->whereNull('verified_at')->latest('id')->first();
                return response()->json([
                    'message' => $message,
                    'code' => 'CONTACT_REQUIRED',
                    'pending_contact' => $pending ? ['id' => $pending->id, 'value' => $pending->value] : null,
                ], 422)->header('Cache-Control', 'private, no-store');
            }
            return back()->withInput()->with('error', $message);
        }
        $contacts->grantMissing($user);
        abort_unless(app(UserOperationalPermissionChecker::class)->allows($user, UserOperationalPermissionEnum::CREATE_EVENT), 403);

        $slots = $this->validatedSlots($input, (int) $input['venue_id']);
        $separate = ($input['schedule_mode'] ?? 'alternatives') === 'separate';
        $type = EventTypeEnum::from($input['type']);
        $title = trim((string) ($input['title'] ?? ''));
        $baseTitle = $title !== '' ? $title : $type->label().' — '.$request->date('date')?->format('d.m.Y');
        if ($baseTitle === '' || str_ends_with($baseTitle, ' — ')) {
            $baseTitle = $type->label().' — новое мероприятие';
        }

        $results = DB::transaction(function () use ($input, $slots, $separate, $actor, $events, $interests, $baseTitle): array {
            $options = $separate ? ($slots === [] ? [[]] : array_map(fn ($slot) => [$slot], $slots)) : [$slots];
            $created = [];
            foreach ($options as $index => $eventOptions) {
                $first = $eventOptions[0] ?? null;
                $requestId = $index === 0
                    ? $input['event_request_id']
                    : Uuid::uuid5($input['event_request_id'], 'venue-event-slot-'.$index)->toString();
                $name = $separate && count($options) > 1 && $first !== null
                    ? $baseTitle.' · '.CarbonImmutable::parse($first['starts_at'])->format('d.m H:i')
                    : $baseTitle;
                $payload = [
                    ...$input,
                    'participation_roles' => ['player'],
                    'event_request_id' => $requestId,
                    'title' => mb_substr($name, 0, 150),
                    'starts_at' => $first['starts_at'] ?? null,
                    'duration_minutes' => $first['duration_minutes'] ?? 90,
                    'proposed_time_options' => $eventOptions,
                    'event_first_funnel' => true,
                    'defer_booking' => true,
                    'booking_scope' => 'whole',
                    'send_pre_request' => false,
                ];
                $event = $events->handle($actor, $payload);
                if ($first !== null) {
                    $interests->createFor($event, $actor);
                }
                $created[] = $event;
            }
            return $created;
        }, 3);

        $urls = array_map(fn (Event $event): string => route('events.show', $event->routeIdentifier()), $results);
        $message = count($urls) > 1
            ? 'Созданы '.count($urls).' отдельных мероприятий. Все выбранные времена предварительные, бронирования нет.'
            : ($slots === [] ? 'Черновик сохранён. Дату и время можно уточнить позднее.'
                : 'Мероприятие создано, пожелания по времени отправлены менеджеру. Бронирования пока нет.');
        if ($request->expectsJson()) {
            return response()->json(['event_url' => $urls[0], 'event_urls' => $urls,
                'created_count' => count($urls), 'message' => $message], 201);
        }
        return redirect()->to($urls[0])->with('status', $message);
    }

    public function schedule(
        Request $request, string $event,
        CurrentActorResolver $actors,
        EventVenueInterestService $interests,
    ): JsonResponse|RedirectResponse {
        $item = Event::query()->whereRouteIdentifier($event)->firstOrFail();
        $actor = $actors->resolveForRequest($request);
        abort_unless($actor !== null && $actor->user_id !== null
            && $item->organizerActor?->user?->canonical()->id === $actor->user?->canonical()->id, 403);

        $input = $request->validate([
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'duration_minutes' => ['required', 'integer', 'between:30,480', 'multiple_of:30'],
        ]);
        $slots = $this->validatedSlots(['slots' => [$input]], (int) $item->venue_id);
        $first = $slots[0];
        DB::transaction(function () use ($item, $actor, $first, $interests): void {
            $draft = Event::query()->lockForUpdate()->findOrFail($item->id);
            abort_unless($draft->status === EventStatusEnum::DRAFT && $draft->starts_at === null, 409);
            $tz = $draft->venue->schedule()->value('timezone') ?: config('app.timezone', 'Europe/Moscow');
            $local = CarbonImmutable::parse($first['starts_at'], $tz);
            $dbTz = DB::connection()->getConfig('timezone') ?: config('app.timezone', 'UTC');
            $draft->forceFill([
                'starts_at' => $local->setTimezone($dbTz),
                'ends_at' => $local->addMinutes($first['duration_minutes'])->setTimezone($dbTz),
                'proposed_time_options' => [$first],
                'status' => EventStatusEnum::PUBLISHED,
                'booking_deferred' => true,
            ])->save();
            $interests->createFor($draft, $actor);
            DB::afterCommit(fn () => event(new EventChanged($draft->id)));
        });
        return $request->expectsJson()
            ? response()->json(['event_url' => route('events.show', $item->routeIdentifier())])
            : redirect()->route('events.show', $item->routeIdentifier())
                ->with('status', 'Желаемое время отправлено менеджеру. Это не подтверждённое бронирование.');
    }

    /** @return array<string,mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'event_request_id' => ['required', 'uuid'],
            'venue_id' => ['required', 'integer', 'exists:venues,id'],
            'venue_court_id' => ['nullable', 'integer',
                Rule::exists('venue_courts', 'id')->where('venue_id', (int) $request->input('venue_id'))],
            'type' => ['required', Rule::in(EventTypeEnum::creatableValues())],
            'title' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'discussion_enabled' => ['sometimes', 'boolean'],
            'report_comments_enabled' => ['sometimes', 'boolean'],
            'visibility' => ['required', Rule::enum(EventVisibilityEnum::class)],
            'max_participants' => ['nullable', 'integer', 'between:2,500'],
            ...\App\Modules\Event\Application\Services\EventParticipationPricing::rules(),
            // The event can be created independently from its optional Game child.
            'create_game' => ['sometimes', 'boolean'],
            'add_organizer_role' => ['sometimes', 'boolean'],
            'organizer_participates' => ['sometimes', 'boolean'],
            'game_format' => ['nullable', Rule::enum(GameFormatEnum::class)],
            'game_recruitment_mode' => ['nullable', Rule::enum(GameRecruitmentModeEnum::class)],
            'game_accepts_applications' => ['nullable', 'boolean'],
            'side_a_size' => ['nullable', 'integer', 'between:1,7'],
            'side_b_size' => ['nullable', 'integer', 'between:1,7'],
            'scoring_type' => ['nullable', Rule::enum(GameScoringTypeEnum::class)],
            'timing_mode' => ['nullable', Rule::enum(GameTimingModeEnum::class)],
            'periods_count' => ['nullable', 'integer', Rule::in([2, 4])],
            'schedule_mode' => ['nullable', Rule::in(['alternatives', 'separate'])],
            'slots' => ['sometimes', 'array', 'max:8'],
            'slots.*.starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'slots.*.duration_minutes' => ['required', 'integer', 'between:30,480', 'multiple_of:30'],
        ]);
    }

    /** @param array<string,mixed> $input @return list<array{starts_at:string,duration_minutes:int}> */
    private function validatedSlots(array $input, int $venueId): array
    {
        $venue = Venue::query()->findOrFail($venueId);
        abort_unless($venue->isPubliclyVisible(), 404);
        $timezone = $venue->schedule()->value('timezone') ?: config('app.timezone', 'Europe/Moscow');
        $result = [];
        $seen = [];
        foreach (($input['slots'] ?? []) as $slot) {
            $start = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $slot['starts_at'], $timezone);
            if ($start->lessThan(now()->setTimezone($timezone)->subMinute())) {
                throw ValidationException::withMessages(['slots' => 'Выберите будущее время.']);
            }
            $key = $start->toIso8601String().':'.(int) $slot['duration_minutes'];
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['slots' => 'Одинаковые интервалы не нужно указывать дважды.']);
            }
            $seen[$key] = true;
            $result[] = ['starts_at' => $slot['starts_at'], 'duration_minutes' => (int) $slot['duration_minutes']];
        }
        return $result;
    }
}
