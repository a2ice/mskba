<?php

namespace App\Modules\Venue\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Venue\Domain\Enums\VenueOwnershipClaimStatusEnum;
use App\Modules\Venue\Domain\Events\VenueOwnershipClaimMessageSent;
use App\Modules\Venue\Domain\Models\VenueOwnershipClaim;
use App\Modules\Venue\Domain\Models\VenueOwnershipClaimConversation;
use App\Modules\Venue\Domain\Models\VenueOwnershipClaimMessage;
use App\Modules\Venue\Infrastructure\Broadcasting\VenueOwnershipClaimMessageSentBroadcast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class VenueOwnershipClaimConversationController extends Controller
{
    public function index(
        Request $request,
        VenueOwnershipClaim $venueOwnershipClaim,
        CurrentActorResolver $actors,
    ): JsonResponse {
        $viewer = $this->authorizeClaim($request, $venueOwnershipClaim, $actors);
        $validated = $request->validate([
            'after_id' => ['nullable', 'integer', 'min:0'],
        ]);

        $conversation = VenueOwnershipClaimConversation::query()
            ->where('venue_ownership_claim_id', $venueOwnershipClaim->id)
            ->first();

        $messages = collect();
        if ($conversation !== null) {
            $messages = VenueOwnershipClaimMessage::query()
                ->with(['authorActor.user', 'conversation'])
                ->where('conversation_id', $conversation->id)
                ->when(
                    isset($validated['after_id']),
                    fn (Builder $query) => $query->where('id', '>', (int) $validated['after_id']),
                )
                ->orderBy('id')
                ->limit(100)
                ->get();
        }

        $isReviewer = $this->isReviewer($request);

        return response()->json([
            'claim_id' => $venueOwnershipClaim->public_id,
            'status' => $venueOwnershipClaim->status->value,
            'status_label' => $venueOwnershipClaim->status->label(),
            'conversation_id' => $conversation?->public_id,
            'can_start' => $conversation === null
                && $isReviewer
                && $venueOwnershipClaim->status === VenueOwnershipClaimStatusEnum::PENDING,
            'can_reply' => $conversation !== null
                && $venueOwnershipClaim->status === VenueOwnershipClaimStatusEnum::PENDING,
            'messages' => $messages
                ->map(fn (VenueOwnershipClaimMessage $message): array => $this->messagePayload($message, $viewer))
                ->values()
                ->all(),
        ]);
    }

    public function store(
        Request $request,
        VenueOwnershipClaim $venueOwnershipClaim,
        CurrentActorResolver $actors,
    ): JsonResponse {
        $data = $request->validate([
            'client_id' => ['required', 'uuid'],
            'body' => ['required', 'string', 'max:4000'],
        ]);
        $actor = $this->authorizeClaim($request, $venueOwnershipClaim, $actors);

        $message = $this->createMessage(
            request: $request,
            claim: $venueOwnershipClaim,
            actor: $actor,
            clientId: $data['client_id'],
            body: trim($data['body']),
        );

        return response()->json($this->messagePayload($message, $actor), 201);
    }

    public function attach(
        Request $request,
        VenueOwnershipClaim $venueOwnershipClaim,
        CurrentActorResolver $actors,
    ): JsonResponse {
        $data = $request->validate([
            'client_id' => ['required', 'uuid'],
            'body' => ['nullable', 'string', 'max:4000'],
            'attachment' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf,txt', 'max:10240'],
        ]);
        $actor = $this->authorizeClaim($request, $venueOwnershipClaim, $actors);
        $file = $request->file('attachment');

        $message = DB::transaction(function () use ($request, $venueOwnershipClaim, $actor, $data, $file): VenueOwnershipClaimMessage {
            $claim = VenueOwnershipClaim::query()->lockForUpdate()->findOrFail($venueOwnershipClaim->id);
            $conversation = $this->conversationForWrite($request, $claim);
            $this->ensureConversationWritable($claim);

            $existing = VenueOwnershipClaimMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('author_actor_id', $actor->id)
                ->where('client_id', $data['client_id'])
                ->first();
            if ($existing !== null) {
                return $existing->load(['authorActor.user', 'conversation']);
            }

            $storedName = (string) Str::uuid().'-'.preg_replace('/[^A-Za-z0-9._-]+/', '-', $file->getClientOriginalName());
            $path = 'venue-ownership-claims/'.$claim->public_id.'/'.$conversation->public_id.'/'.$storedName;
            Storage::disk('local')->put($path, $file->getContent());

            $message = VenueOwnershipClaimMessage::query()->create([
                'public_id' => (string) Str::uuid(),
                'conversation_id' => $conversation->id,
                'author_actor_id' => $actor->id,
                'client_id' => $data['client_id'],
                'type' => 'attachment',
                'body' => isset($data['body']) ? trim((string) $data['body']) : null,
                'attachment_disk' => 'local',
                'attachment_path' => $path,
                'attachment_name' => $file->getClientOriginalName(),
                'attachment_mime' => (string) $file->getMimeType(),
                'attachment_size' => $file->getSize(),
            ]);

            DB::afterCommit(function () use ($claim, $conversation, $message): void {
                broadcast(new VenueOwnershipClaimMessageSentBroadcast(
                    $claim->public_id,
                    $conversation->public_id,
                    $message->public_id,
                ))->toOthers();
                event(new VenueOwnershipClaimMessageSent($message->id));
            });

            return $message->load(['authorActor.user', 'conversation']);
        });

        return response()->json($this->messagePayload($message, $actor), 201);
    }

    public function download(
        Request $request,
        VenueOwnershipClaim $venueOwnershipClaim,
        VenueOwnershipClaimMessage $message,
        CurrentActorResolver $actors,
    ): StreamedResponse {
        $this->authorizeClaim($request, $venueOwnershipClaim, $actors);
        $message->load('conversation');
        abort_unless(
            $message->conversation->venue_ownership_claim_id === $venueOwnershipClaim->id
                && $message->attachment_disk === 'local'
                && $message->attachment_path !== null,
            404,
        );

        return response()->streamDownload(
            static fn () => print Storage::disk('local')->get($message->attachment_path),
            $message->attachment_name,
            [
                'Content-Type' => 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function createMessage(
        Request $request,
        VenueOwnershipClaim $claim,
        Actor $actor,
        string $clientId,
        string $body,
    ): VenueOwnershipClaimMessage {
        return DB::transaction(function () use ($request, $claim, $actor, $clientId, $body): VenueOwnershipClaimMessage {
            $claim = VenueOwnershipClaim::query()->lockForUpdate()->findOrFail($claim->id);
            $conversation = $this->conversationForWrite($request, $claim);
            $this->ensureConversationWritable($claim);

            $existing = VenueOwnershipClaimMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('author_actor_id', $actor->id)
                ->where('client_id', $clientId)
                ->first();
            if ($existing !== null) {
                return $existing->load(['authorActor.user', 'conversation']);
            }

            $message = VenueOwnershipClaimMessage::query()->create([
                'public_id' => (string) Str::uuid(),
                'conversation_id' => $conversation->id,
                'author_actor_id' => $actor->id,
                'client_id' => $clientId,
                'type' => 'text',
                'body' => $body,
            ]);

            DB::afterCommit(function () use ($claim, $conversation, $message): void {
                broadcast(new VenueOwnershipClaimMessageSentBroadcast(
                    $claim->public_id,
                    $conversation->public_id,
                    $message->public_id,
                ))->toOthers();
                event(new VenueOwnershipClaimMessageSent($message->id));
            });

            return $message->load(['authorActor.user', 'conversation']);
        });
    }

    private function conversationForWrite(Request $request, VenueOwnershipClaim $claim): VenueOwnershipClaimConversation
    {
        $conversation = VenueOwnershipClaimConversation::query()
            ->where('venue_ownership_claim_id', $claim->id)
            ->first();

        if ($conversation !== null) {
            return $conversation;
        }

        if (! $this->isReviewer($request)) {
            abort(409, 'Переписку начинает подтверждающая сторона. Ожидайте сообщение администратора.');
        }

        return VenueOwnershipClaimConversation::query()->firstOrCreate(
            ['venue_ownership_claim_id' => $claim->id],
            ['public_id' => (string) Str::uuid()],
        );
    }

    private function ensureConversationWritable(VenueOwnershipClaim $claim): void
    {
        abort_unless(
            $claim->status === VenueOwnershipClaimStatusEnum::PENDING,
            409,
            'Решение по заявке уже принято. Переписка доступна только для чтения.',
        );
    }

    private function authorizeClaim(
        Request $request,
        VenueOwnershipClaim $claim,
        CurrentActorResolver $actors,
    ): Actor {
        $user = $request->user()?->canonical();
        abort_unless($user !== null, 401);
        abort_unless(
            $user->isSameIdentity($claim->applicant_user_id) || $this->isReviewer($request),
            403,
        );

        return $actors->resolveForRequest($request);
    }

    private function isReviewer(Request $request): bool
    {
        $user = $request->user()?->canonical();

        return $user !== null
            && $user->isConfirmed()
            && $user->system_role->atLeast(UserSystemRoleEnum::ADMIN);
    }

    /** @return array<string, mixed> */
    private function messagePayload(VenueOwnershipClaimMessage $message, Actor $viewer): array
    {
        return [
            'id' => $message->id,
            'message_id' => $message->public_id,
            'conversation_id' => $message->conversation->public_id,
            'client_id' => $message->client_id,
            'type' => $message->type,
            'body' => $message->body,
            'is_own' => $message->author_actor_id === $viewer->id,
            'created_at' => $message->created_at?->utc()->toIso8601String(),
            'attachment' => $message->attachment_path === null ? null : [
                'name' => $message->attachment_name,
                'mime' => $message->attachment_mime,
                'size' => $message->attachment_size,
            ],
        ];
    }
}
