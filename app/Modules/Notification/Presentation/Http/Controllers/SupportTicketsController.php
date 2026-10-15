<?php

namespace App\Modules\Notification\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\Services\AccountConversationRail;
use App\Modules\Notification\Application\Services\SupportTicketAccess;
use App\Modules\Notification\Application\Services\SupportTicketMessagingService;
use App\Modules\Notification\Application\Services\SupportTicketTimeline;
use App\Modules\Notification\Application\UseCases\CreateSupportTicketHandler;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Application\UseCases\MarkConversationNotificationsReadHandler;
use App\Modules\Notification\Domain\Enums\SupportClosureReasonEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Notification\Domain\Models\RequestThread;
use App\Modules\Notification\Domain\Models\SupportTicket;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Notification\Infrastructure\Broadcasting\ConversationChangedBroadcast;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SupportTicketsController extends Controller
{
    public function start(Request $request, AccountConversationRail $rail): Response
    {
        $this->ensureActive($request->user());

        return ThemeResolver::page('account.support-ticket-create', [
            'conversationCards' => $rail->items($request->user(), $request),
            'activeConversationId' => '',
        ]);
    }

    public function store(Request $request, CreateSupportTicketHandler $createSupportTicket): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:2000'],
        ]);
        abort_unless(trim($data['subject']) !== '' && trim($data['body']) !== '', 422);

        $ticket = $createSupportTicket->handle($request->user(), $data['subject'], $data['body']);

        return redirect()->route('account.notifications.threads.support', $ticket);
    }

    public function show(Request $request, SupportTicket $ticket, SupportTicketAccess $access): Response
    {
        $viewer = $request->user()->canonical();
        $thread = $ticket->thread()->with('lead')->firstOrFail();
        abort_unless($access->canRead($viewer, $ticket, $thread), 403);
        $page = app(SupportTicketTimeline::class)->page($thread);
        $unread = UserNotification::query()->whereIn('user_id', $viewer->identityIds())
            ->where('payload->support_ticket_id', $ticket->id)->where('status', UserNotificationStatusEnum::NEW)->count();

        $isLead = $access->isAgent($viewer) && (int) $thread->lead_user_id === $viewer->id;
        $availableAgents = collect();
        if ($isLead && $ticket->status === 'open') {
            // Two indexed branches instead of a multi-table JOIN. Confirmed,
            // permitted staff only; server validates again when transferring.
            $availableAgents = User::query()->where('status', 'confirmed')
                ->whereKeyNot($viewer->id)->whereKeyNot($ticket->user_id)
                ->where(fn ($q) => $q->where('system_role', UserSystemRoleEnum::SUPERADMIN->value)
                    ->orWhereHas('operationalPermissions', fn ($p) => $p
                        ->where('permission', 'support.ticket.handle')->where('is_allowed', true)))
                ->orderBy('username')->limit(30)->get()
                ->filter(fn (User $candidate) => $access->isAgent($candidate))->values();
        }

        return ThemeResolver::page('account.support-ticket-conversation', [
            'ticket' => $ticket, 'thread' => $thread, 'viewer' => $viewer,
            'messages' => $page['entries'], 'nextBefore' => $page['next_before'],
            'canSend' => $access->canWrite($viewer, $ticket, $thread), 'unread' => $unread,
            'isAgent' => $access->isAgent($viewer),
            'isLead' => $isLead, 'availableAgents' => $availableAgents,
            'canReopen' => $ticket->status === 'closed' && $access->isAgent($viewer)
                && $access->canRead($viewer, $ticket, $thread)
                && ($isLead || $thread->lead_user_id === null),
            'supportSignature' => $this->stateSignature($viewer, $ticket, $thread, $access),
            'conversationCards' => app(AccountConversationRail::class)->items($viewer, $request),
            'activeConversationId' => 'support-'.$ticket->id,
        ]);
    }

    public function state(Request $request, SupportTicket $ticket, SupportTicketAccess $access): JsonResponse
    {
        $viewer = $request->user()->canonical();
        $thread = $ticket->thread()->firstOrFail();
        abort_unless($access->canRead($viewer, $ticket, $thread), 403);

        return response()->json([
            'status' => $ticket->status,
            'can_send' => $access->canWrite($viewer, $ticket, $thread),
            'signature' => $this->stateSignature($viewer, $ticket, $thread, $access),
        ])->header('Cache-Control', 'private, no-store');
    }

    private function stateSignature(User $viewer, SupportTicket $ticket,
        RequestThread $thread, SupportTicketAccess $access): string
    {
        $isAgent = $access->isAgent($viewer);
        $isLead = $isAgent && (int) $thread->lead_user_id === $viewer->id;
        $canReopen = $ticket->status === 'closed' && $isAgent
            && $access->canRead($viewer, $ticket, $thread)
            && ($isLead || $thread->lead_user_id === null);

        return implode(':', [
            $ticket->status,
            $access->canWrite($viewer, $ticket, $thread) ? '1' : '0',
            $isLead ? '1' : '0',
            $canReopen ? '1' : '0',
            $thread->lead_user_id ?? 0,
        ]);
    }

    public function history(Request $request, SupportTicket $ticket, SupportTicketAccess $access,
        SupportTicketTimeline $timeline): JsonResponse
    {
        $viewer = $request->user()->canonical();
        $thread = $ticket->thread()->firstOrFail();
        abort_unless($access->canRead($viewer, $ticket, $thread), 403);
        $before = $request->query('before');
        abort_unless($before === null || is_string($before), 422);
        $page = $timeline->page($thread, $before);

        return response()->json([
            'html' => $page['entries']->map(fn ($message) => view('theme::pages.account.partials.chat-bubble', [
                'entry' => $message,
                'viewer' => $viewer,
            ])->render())->implode(''),
            'next_before' => $page['next_before'],
        ]);
    }

    public function read(Request $request, SupportTicket $ticket, SupportTicketAccess $access,
        MarkConversationNotificationsReadHandler $read): JsonResponse
    {
        $viewer = $request->user()->canonical();
        abort_unless($access->canRead($viewer, $ticket, $ticket->thread), 403);

        return response()->json(['unread_count' => $read->handle($viewer, supportTicketId: $ticket->id)]);
    }

    public function message(Request $request, SupportTicket $ticket,
        SupportTicketMessagingService $messaging): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $messaging->send($request->user(), $ticket->id, $data['body']);

        return response()->json(['message' => 'Отправлено.'], 201);
    }

    public function claim(Request $request, SupportTicket $ticket, SupportTicketAccess $access): RedirectResponse
    {
        $actor = $request->user()->canonical();
        DB::transaction(function () use ($actor, $ticket, $access): void {
            $thread = $ticket->thread()->lockForUpdate()->firstOrFail();
            abort_unless($ticket->status === 'open' && $thread->lead_user_id === null &&
                $access->isAgent($actor) && $access->canRead($actor, $ticket, $thread), 403);
            $thread->update(['lead_user_id' => $actor->id]);
            $thread->memberships()->firstOrCreate(['user_id' => $actor->id], ['joined_at' => now()]);
        });

        return redirect()->route('account.notifications.threads.support', $ticket);
    }

    public function transfer(Request $request, SupportTicket $ticket, SupportTicketAccess $access): RedirectResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
        $actor = $request->user()->canonical();
        DB::transaction(function () use ($ticket, $access, $actor, $data): void {
            $thread = $ticket->thread()->lockForUpdate()->firstOrFail();
            abort_unless($ticket->status === 'open' && (int) $thread->lead_user_id === $actor->id
                && $access->isAgent($actor), 403);
            $target = User::query()->findOrFail($data['user_id'])->canonical();
            abort_unless($target->id !== $actor->id && $access->isAgent($target), 422);
            // A transfer is an explicit invitation to this particular historical ticket.
            $member = $thread->memberships()->where('user_id', $target->id)->first();
            abort_if($member?->removed_at !== null, 403, 'Исключённый участник требует отдельного восстановления.');
            $thread->memberships()->firstOrCreate(['user_id' => $target->id],
                ['joined_at' => now(), 'invited_by_user_id' => $actor->id]);
            $thread->update(['lead_user_id' => $target->id]);
        });
        $this->broadcast($ticket->thread, true);
        app(CreateUserNotificationHandler::class)->handle(new CreateUserNotificationDTO(
            userId: (int) $data['user_id'], type: UserNotificationTypeEnum::SYSTEM,
            title: 'Техподдержка №'.$ticket->id.' — назначен главный',
            body: 'Вам передали ведение обращения №'.$ticket->id.'.',
            actionUrl: route('account.notifications.threads.support', $ticket),
            actionText: 'Открыть обращение',
            payload: ['source' => 'support.lead_transferred', 'support_ticket_id' => $ticket->id],
        ));

        return redirect()->route('account.notifications.threads.support', $ticket);
    }

    public function close(Request $request, SupportTicket $ticket, SupportTicketAccess $access): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', Rule::enum(SupportClosureReasonEnum::class)],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
        $reason = SupportClosureReasonEnum::from(
            $data['reason'] ?? SupportClosureReasonEnum::RESOLVED->value,
        );
        $description = trim((string) ($data['description'] ?? ''));
        if ($reason === SupportClosureReasonEnum::OTHER && $description === '') {
            throw ValidationException::withMessages([
                'description' => 'Укажи описание другой причины закрытия.',
            ]);
        }
        if ($reason !== SupportClosureReasonEnum::OTHER) {
            $description = '';
        }

        $actor = $request->user()->canonical();
        DB::transaction(function () use ($ticket, $actor, $access, $reason, $description): void {
            $locked = SupportTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $thread = $locked->thread()->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'open' && (int) $thread->lead_user_id === $actor->id
                && $access->isAgent($actor) && $access->canRead($actor, $locked, $thread), 403);
            $locked->update([
                'status' => 'closed', 'close_reason' => $reason->value,
                'close_description' => $description ?: null,
                'closed_by_user_id' => $actor->id, 'closed_at' => now(),
            ]);
            $thread->events()->create([
                'actor_user_id' => $actor->id, 'action' => 'support_closed',
                'metadata' => ['reason' => $reason->value, 'description' => $description],
                'created_at' => now(),
            ]);
            $thread->touch();
        });
        $this->broadcast($ticket->thread, true);

        return redirect()->route('account.notifications.threads.support', $ticket);
    }

    public function reopen(Request $request, SupportTicket $ticket, SupportTicketAccess $access): RedirectResponse
    {
        $actor = $request->user()->canonical();
        DB::transaction(function () use ($ticket, $actor, $access): void {
            $locked = SupportTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $thread = $locked->thread()->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'closed' && $access->isAgent($actor)
                && $access->canRead($actor, $locked, $thread)
                && ($thread->lead_user_id === null || (int) $thread->lead_user_id === $actor->id), 403);
            if ($thread->lead_user_id === null) {
                $thread->update(['lead_user_id' => $actor->id]);
                $thread->memberships()->firstOrCreate(['user_id' => $actor->id], ['joined_at' => now()]);
            }
            $locked->update([
                'status' => 'open', 'close_reason' => null, 'close_description' => null,
                'closed_by_user_id' => null, 'closed_at' => null,
            ]);
            $thread->events()->create([
                'actor_user_id' => $actor->id, 'action' => 'support_reopened',
                'created_at' => now(),
            ]);
            $thread->touch();
        });
        $this->broadcast($ticket->thread, true);

        return redirect()->route('account.notifications.threads.support', $ticket);
    }

    private function ensureActive(User $user): void
    {
        abort_if($user->canonical()->isBlocked() || $user->canonical()->trashed(), 403);
    }

    private function broadcast(RequestThread $thread, bool $accessChanged = false): void
    {
        try {
            ConversationChangedBroadcast::dispatch('support', $thread->id, $accessChanged);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
