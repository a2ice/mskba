<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Application\Services\EventResultGalleryManager;
use App\Modules\Event\Application\UseCases\CancelEventHandler;
use App\Modules\Event\Application\UseCases\CompleteEventHandler;
use App\Modules\Event\Application\UseCases\CreateEventHandler;
use App\Modules\Event\Application\UseCases\JoinEventHandler;
use App\Modules\Event\Application\UseCases\LeaveEventHandler;
use App\Modules\Event\Application\UseCases\ListEventsHandler;
use App\Modules\Event\Application\UseCases\ListEventVenuesHandler;
use App\Modules\Event\Application\UseCases\SetEventParticipationHandler;
use App\Modules\Event\Application\UseCases\ShowEventHandler;
use App\Modules\Event\Application\UseCases\UpdateEventHandler;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Presentation\Http\Requests\CancelEventRequest;
use App\Modules\Event\Presentation\Http\Requests\CreateEventRequest;
use App\Modules\Event\Presentation\Http\Requests\StoreEventResultPhotoRequest;
use App\Modules\Event\Presentation\Http\Requests\UpdateEventRequest;
use App\Modules\Event\Presentation\Http\Requests\UpdateEventResultRequest;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Presentation\Theming\ThemeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class EventController extends Controller
{
    public function index(Request $request, ListEventsHandler $events, CurrentActorResolver $actors): Response
    {
        $validated = $request->validate([
            'type' => ['nullable', Rule::enum(EventTypeEnum::class)],
            'period' => ['nullable', Rule::in(['upcoming', 'past'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => [
                'nullable',
                'date_format:Y-m-d',
                Rule::when($request->filled('date_from'), ['after_or_equal:date_from']),
            ],
            'outcome' => ['nullable', Rule::in(['completed', 'cancelled', 'unmarked'])],
        ]);
        $type = isset($validated['type']) ? EventTypeEnum::from($validated['type']) : null;
        $period = $validated['period'] ?? 'upcoming';
        $dateFrom = $validated['date_from'] ?? null;
        $dateTo = $validated['date_to'] ?? null;
        $outcome = $period === 'past' ? ($validated['outcome'] ?? null) : null;

        return ThemeResolver::page('events.index', [
            'events' => $events->handle(
                $actors->resolveForRequest($request),
                $type,
                $period,
                $dateFrom,
                $dateTo,
                $outcome,
            ),
            'types' => EventTypeEnum::cases(),
            'selectedType' => $type,
            'period' => $period,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'outcome' => $outcome,
        ]);
    }

    public function create(Request $request, ListEventVenuesHandler $venues): Response
    {
        $validated = $request->validate([
            'type' => ['nullable', Rule::enum(EventTypeEnum::class)],
        ]);
        $selectedType = isset($validated['type']) ? EventTypeEnum::from($validated['type']) : null;
        $defaultType = $selectedType ?? EventTypeEnum::GAME;
        $now = CarbonImmutable::now((string) config('app.timezone', 'Europe/Moscow'));
        $defaultStartsAt = $now->addMinutes(15)->ceilMinute();

        return ThemeResolver::page('events.create', [
            'venues' => $venues->handle(),
            'types' => EventTypeEnum::cases(),
            'visibilities' => EventVisibilityEnum::cases(),
            'selectedType' => $selectedType,
            'defaultType' => $defaultType,
            'currentDate' => $now->format('Ymd'),
            'defaultTitle' => $defaultType->label().' - '.$now->format('Ymd'),
            'defaultStartsAt' => $defaultStartsAt->format('Y-m-d\TH:i'),
            'durationOptions' => range(30, 480, 30),
            'defaultDuration' => 60,
        ]);
    }

    public function store(
        CreateEventRequest $request,
        CreateEventHandler $events,
        CurrentActorResolver $actors,
    ): RedirectResponse {
        $actor = $actors->resolveForRequest($request);

        if ($actor === null) {
            return redirect()->route('login');
        }

        try {
            $event = $events->handle($actor, $request->validated());
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        $message = $event->booking?->status->value === 'confirmed'
            ? 'Мероприятие создано, площадка забронирована.'
            : 'Мероприятие сохранено. Бронирование ожидает подтверждения площадки.';

        return redirect()->route('events.show', $event->routeIdentifier())->with('status', $message);
    }

    public function show(
        Request $request,
        string $event,
        ShowEventHandler $events,
        CurrentActorResolver $actors,
        EventManagementAccess $access,
    ): Response {
        $item = $events->handle($event, $actors->resolveForRequest($request));
        $currentParticipant = $request->user() === null
            ? null
            : $item->participants->firstWhere('user_id', $request->user()->id);

        $actor = $actors->resolveForRequest($request);

        return ThemeResolver::page('events.show', [
            'event' => $item,
            'currentParticipant' => $currentParticipant,
            'isParticipating' => $currentParticipant?->status === EventParticipantStatusEnum::CONFIRMED
                && $currentParticipant->confirmation_version === $item->participation_confirmation_version,
            'canManage' => $actor !== null && $access->canManage($item, $actor),
        ]);
    }

    public function edit(
        Request $request,
        string $event,
        ShowEventHandler $events,
        ListEventVenuesHandler $venues,
        CurrentActorResolver $actors,
        EventManagementAccess $access,
    ): Response|RedirectResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        $item = $events->handle($event, $actor);
        abort_unless($access->canManage($item, $actor), 403);

        if (in_array($item->status, [EventStatusEnum::CANCELLED, EventStatusEnum::COMPLETED], true)
            || $item->ends_at->lessThanOrEqualTo(now())) {
            return redirect()->route('events.show', $item->routeIdentifier())
                ->with('error', 'Завершённое или отменённое мероприятие нельзя редактировать.');
        }

        $freeVenues = $venues->handle(freeOnly: true);

        if ($item->venue->hasFreeAccess() && ! $freeVenues->contains('id', $item->venue_id)) {
            $freeVenues->push($item->venue);
            $freeVenues = $freeVenues->sortBy('name')->values();
        }

        return ThemeResolver::page('events.edit', [
            'event' => $item,
            'venues' => $freeVenues,
            'types' => EventTypeEnum::cases(),
            'visibilities' => EventVisibilityEnum::cases(),
            'canReschedule' => $item->venue->hasFreeAccess(),
            'durationOptions' => range(30, 480, 30),
            'currentDuration' => (int) $item->starts_at->diffInMinutes($item->ends_at),
        ]);
    }

    public function update(
        UpdateEventRequest $request,
        string $event,
        UpdateEventHandler $events,
        CurrentActorResolver $actors,
    ): RedirectResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        try {
            $item = $events->handle($event, $actor, $request->validated());
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->route('events.show', $item->routeIdentifier())
            ->with('status', 'Мероприятие обновлено.');
    }

    public function cancel(
        CancelEventRequest $request,
        string $event,
        CancelEventHandler $events,
        CurrentActorResolver $actors,
    ): RedirectResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        try {
            $events->handle($event, $actor, $request->validated('reason'));
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Мероприятие отменено, время площадки освобождено.');
    }

    public function complete(
        UpdateEventResultRequest $request,
        string $event,
        CompleteEventHandler $events,
        CurrentActorResolver $actors,
    ): RedirectResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        try {
            $events->handle($event, $actor, $request->validated('result_description'));
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Итоги мероприятия сохранены.');
    }

    public function storeResultPhoto(
        StoreEventResultPhotoRequest $request,
        string $event,
        ShowEventHandler $events,
        EventResultGalleryManager $gallery,
        CurrentActorResolver $actors,
    ): RedirectResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        $item = $events->handle($event, $actor);
        $path = $request->file('photo')?->getRealPath();
        $contents = is_string($path) ? file_get_contents($path) : false;

        if (! is_string($contents)) {
            return back()->with('photo_error', 'Не удалось прочитать изображение.');
        }

        try {
            $gallery->store($item, $actor, $contents);
        } catch (InvalidArgumentException|\RuntimeException $exception) {
            return back()->with('photo_error', $exception->getMessage());
        }

        return back()->with('photo_status', 'Фотография добавлена.');
    }

    public function destroyResultPhoto(
        Request $request,
        string $event,
        int $photo,
        ShowEventHandler $events,
        EventResultGalleryManager $gallery,
        CurrentActorResolver $actors,
    ): RedirectResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        $item = $events->handle($event, $actor);
        try {
            $gallery->delete($item, $actor, $photo);
        } catch (InvalidArgumentException $exception) {
            return back()->with('photo_error', $exception->getMessage());
        }

        return back()->with('photo_status', 'Фотография удалена.');
    }

    public function join(Request $request, string $event, JoinEventHandler $events): RedirectResponse
    {
        try {
            $events->handle($event, $request->user());
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Вы присоединились к мероприятию.');
    }

    public function leave(Request $request, string $event, LeaveEventHandler $events): RedirectResponse
    {
        try {
            $events->handle($event, $request->user());
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Вы вышли из состава участников.');
    }

    public function participation(
        Request $request,
        string $event,
        SetEventParticipationHandler $events,
    ): RedirectResponse {
        $validated = $request->validate([
            'status' => [
                'required',
                Rule::in([
                    EventParticipantStatusEnum::CONFIRMED->value,
                    EventParticipantStatusEnum::TENTATIVE->value,
                    EventParticipantStatusEnum::LEFT->value,
                ]),
            ],
        ]);
        $status = EventParticipantStatusEnum::from($validated['status']);

        try {
            $events->handle($event, $request->user(), $status);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', match ($status) {
            EventParticipantStatusEnum::CONFIRMED => 'Вы присоединились к мероприятию.',
            EventParticipantStatusEnum::TENTATIVE => 'Ответ «Думаю» сохранён.',
            EventParticipantStatusEnum::LEFT => 'Ответ «Не пойду» сохранён.',
        });
    }
}
