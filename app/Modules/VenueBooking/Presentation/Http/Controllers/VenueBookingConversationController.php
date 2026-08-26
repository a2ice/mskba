<?php

namespace App\Modules\VenueBooking\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Application\Services\VenueBookingAuthorization;
use App\Modules\VenueBooking\Application\UseCases\AttachVenueBookingConversationFileHandler;
use App\Modules\VenueBooking\Application\UseCases\MarkVenueBookingConversationReadHandler;
use App\Modules\VenueBooking\Application\UseCases\SendVenueBookingMessageHandler;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingConversation;
use App\Modules\VenueBooking\Domain\Models\VenueBookingMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class VenueBookingConversationController extends Controller
{
    public function index(Request $request, VenueBooking $venueBooking, CurrentActorResolver $actors, VenueBookingAuthorization $authorization): JsonResponse
    {
        $venueBooking->load('venue');
        $this->authorizeConversation($actors->resolveForRequest($request), $venueBooking, $authorization);
        $conversation = VenueBookingConversation::query()->where('venue_booking_id', $venueBooking->id)->first();
        if ($conversation === null) {
            return response()->json(['conversation_id' => null, 'messages' => [], 'has_more' => false]);
        }

        $validated = $request->validate(['before_id' => ['nullable', 'integer', 'min:1'], 'after_id' => ['nullable', 'integer', 'min:0']]);
        if (isset($validated['before_id'], $validated['after_id'])) {
            throw ValidationException::withMessages(['after_id' => 'Нельзя одновременно листать назад и запрашивать новые сообщения.']);
        }
        $messages = VenueBookingMessage::query()->with(['authorActor.user', 'conversation'])
            ->where('conversation_id', $conversation->id)
            ->when(isset($validated['before_id']), fn (Builder $query) => $query->where('id', '<', $validated['before_id']))
            ->when(isset($validated['after_id']), fn (Builder $query) => $query->where('id', '>', $validated['after_id'])->orderBy('id'))
            ->when(! isset($validated['after_id']), fn (Builder $query) => $query->orderByDesc('id'))
            ->limit(31)->get();
        $hasMore = $messages->count() > 30;
        $messages = $messages->take(30)->sortBy('id')->values();

        return response()->json([
            'conversation_id' => $conversation->public_id,
            'messages' => $messages->map(fn (VenueBookingMessage $message): array => $this->messagePayload($message)),
            'has_more' => $hasMore,
        ]);
    }

    public function store(Request $request, VenueBooking $venueBooking, CurrentActorResolver $actors, SendVenueBookingMessageHandler $handler): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['client_id' => ['required', 'uuid'], 'body' => ['required', 'string', 'max:4000']]);
        try {
            $message = $handler->handle($venueBooking->id, $actors->resolveForRequest($request), $data['client_id'], $data['body']);
        } catch (VenueBookingTransitionException $exception) {
            return $this->error($request, $exception);
        }

        return $request->expectsJson()
            ? response()->json($this->messagePayload($message), 201)
            : back()->with('status', 'Сообщение отправлено.');
    }

    public function read(Request $request, VenueBooking $venueBooking, VenueBookingConversation $conversation, CurrentActorResolver $actors, MarkVenueBookingConversationReadHandler $handler): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['message_id' => ['nullable', 'uuid']]);
        $messageId = isset($data['message_id'])
            ? VenueBookingMessage::query()->where('public_id', $data['message_id'])->value('id')
            : null;
        if (isset($data['message_id']) && $messageId === null) {
            abort(404);
        }
        try {
            $marker = $handler->handle($venueBooking->id, $conversation->id, $actors->resolveForRequest($request), $messageId === null ? null : (int) $messageId);
        } catch (VenueBookingTransitionException $exception) {
            return $this->error($request, $exception);
        }

        return $request->expectsJson()
            ? response()->json(['read_at' => $marker->read_at->utc()->toIso8601String(), 'last_read_message_id' => $data['message_id'] ?? null])
            : back();
    }

    public function attach(Request $request, VenueBooking $venueBooking, CurrentActorResolver $actors, AttachVenueBookingConversationFileHandler $handler): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'uuid'], 'body' => ['nullable', 'string', 'max:4000'],
            'attachment' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf,txt', 'max:10240'],
        ]);
        $file = $request->file('attachment');
        try {
            $message = $handler->handle($venueBooking->id, $actors->resolveForRequest($request), $data['client_id'], $file->getContent(), $file->getClientOriginalName(), (string) $file->getMimeType(), $file->getSize(), $data['body'] ?? null);
        } catch (VenueBookingTransitionException $exception) {
            return $this->error($request, $exception);
        }

        return $request->expectsJson() ? response()->json($this->messagePayload($message), 201) : back()->with('status', 'Вложение отправлено.');
    }

    public function download(Request $request, VenueBooking $venueBooking, VenueBookingMessage $message, CurrentActorResolver $actors, VenueBookingAuthorization $authorization): StreamedResponse
    {
        $venueBooking->load('venue');
        $this->authorizeConversation($actors->resolveForRequest($request), $venueBooking, $authorization);
        $message->load('conversation');
        abort_unless($message->conversation->venue_booking_id === $venueBooking->id && $message->attachment_disk === 'local' && $message->attachment_path !== null, 404);

        return response()->streamDownload(
            static fn () => print Storage::disk($message->attachment_disk)->get($message->attachment_path),
            $message->attachment_name,
            ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    /** @return array<string, mixed> */
    private function messagePayload(VenueBookingMessage $message): array
    {
        return [
            'id' => $message->id,
            'message_id' => $message->public_id,
            'conversation_id' => $message->conversation->public_id,
            'client_id' => $message->client_id,
            'type' => $message->type,
            'body' => $message->body,
            'author' => $message->authorActor->user?->username,
            'created_at' => $message->created_at->utc()->toIso8601String(),
            'attachment' => $message->attachment_path === null ? null : [
                'name' => $message->attachment_name, 'mime' => $message->attachment_mime, 'size' => $message->attachment_size,
            ],
        ];
    }

    private function authorizeConversation(Actor $actor, VenueBooking $booking, VenueBookingAuthorization $authorization): void
    {
        try {
            $authorization->assertCanView($actor, $booking, $booking->venue);
        } catch (VenueBookingTransitionException $exception) {
            abort($exception->errorCode === 'BOOKING_FORBIDDEN' ? 403 : 409, $exception->getMessage());
        }
        if ($actor->user?->hasSystemRole(UserSystemRoleEnum::SUPERADMIN)) {
            AuditLog::query()->create([
                'actor_id' => $actor->id, 'auditable_type' => VenueBooking::class,
                'auditable_id' => $booking->id, 'event' => 'conversation_viewed_for_support',
                'old_values' => [], 'new_values' => [], 'metadata' => ['route' => request()->route()?->getName()],
            ]);
        }
    }

    private function error(Request $request, VenueBookingTransitionException $exception): JsonResponse|RedirectResponse
    {
        $status = $exception->errorCode === 'BOOKING_FORBIDDEN' ? 403 : 409;

        return $request->expectsJson()
            ? response()->json(['code' => $exception->errorCode, 'message' => $exception->getMessage()], $status)
            : back()->withInput()->with('error', $exception->getMessage());
    }
}
