<?php

namespace App\Modules\Uniform\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Contract\Application\Services\ContractAssurance;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Uniform\Application\Services\UniformLogoService;
use App\Modules\Uniform\Domain\Models\UniformDesign;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class UniformDesignController extends Controller
{
    public function workshop(): Response
    {
        return response()->view('theme::pages.uniforms.workshop', ['title' => 'Мастерская формы']);
    }

    public function my(): Response
    {
        return response()->view('theme::pages.uniforms.my', ['title' => 'Мои формы']);
    }

    public function options(Request $request): JsonResponse
    {
        $user = $request->user();
        $teams = $user === null ? collect() : $this->teams($user)->with('logo')->orderBy('name')->get();

        return $this->json([
            'authenticated' => $user !== null,
            'teams' => $teams->filter(fn (Team $team) => $team->logo !== null)
                ->map(fn (Team $team) => [
                    'id' => $team->id,
                    'name' => $team->name,
                    'logo_url' => $team->logo?->publicUrl(),
                ])->values(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $designs = $this->owned($request->user())
            ->with(['customLogo', 'logoTeam.logo'])
            ->latest('updated_at')->get()->map(fn (UniformDesign $design) => $this->present($design));

        return $this->json(['designs' => $designs]);
    }

    public function show(Request $request, UniformDesign $design): JsonResponse
    {
        return $this->json(['design' => $this->present($this->authorizeOwner($request, $design)->load(['customLogo', 'logoTeam.logo']))]);
    }

    public function store(Request $request, UniformLogoService $logos): JsonResponse
    {
        $data = $this->validateDesign($request);
        $user = $request->user()->canonical();
        $this->validateLogo($request, $data, $user);
        $design = UniformDesign::query()->create([
            'user_id' => $user->id,
            ...$this->attributes($data),
        ]);
        try {
            if (($data['logo_source'] ?? 'none') === 'custom' && $request->hasFile('logo')) {
                $logos->upload($design, $request->file('logo'));
            }
        } catch (\Throwable $e) {
            $design->forceDelete();
            throw $e;
        }
        return $this->json(['design' => $this->present($design->load(['customLogo', 'logoTeam.logo']))], 201);
    }

    public function update(Request $request, UniformDesign $design, UniformLogoService $logos): JsonResponse
    {
        $this->authorizeOwner($request, $design);
        $data = $this->validateDesign($request);
        $this->validateLogo($request, $data, $request->user()->canonical(), $design);
        if (($data['logo_source'] ?? 'none') === 'custom' && $request->hasFile('logo')) {
            $logos->upload($design, $request->file('logo'));
        } elseif (($data['logo_source'] ?? 'none') !== 'custom') {
            $logos->remove($design);
        }
        $design->update($this->attributes($data, $design));

        return $this->json(['design' => $this->present($design->fresh()->load(['customLogo', 'logoTeam.logo']))]);
    }

    public function destroy(Request $request, UniformDesign $design, UniformLogoService $logos): JsonResponse
    {
        $this->authorizeOwner($request, $design);
        $logos->remove($design);
        $design->delete();

        return $this->json(['message' => 'Форма удалена.']);
    }

    public function logo(Request $request, UniformDesign $design): Response
    {
        $this->authorizeOwner($request, $design);
        $media = $design->customLogo()->firstOrFail();
        abort_unless(Storage::disk($media->disk)->exists($media->path), 404);

        return response(Storage::disk($media->disk)->get($media->path), 200, [
            'Content-Type' => 'image/webp',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * One design belongs to the team itself, not to the creator's personal library.
     * Every read enforces active team membership; every write enforces the contract
     * capability, with the active owner contract receiving full access.
     */
    public function teamShow(string $team, Request $request, CurrentActorResolver $actors, TeamManagementAccess $access): JsonResponse
    {
        [$item, $member, $canEdit] = $this->teamContext($team, $request, $actors, $access);
        $design = $item->uniformDesign()->with(['team', 'customLogo', 'logoTeam.logo'])->first();

        return $this->json([
            'team' => ['id' => $item->id, 'name' => $item->name],
            'can_edit' => $canEdit,
            'player_number' => $member?->formattedJerseyNumber(),
            'design' => $design ? $this->present($design) : null,
        ]);
    }

    public function teamSave(
        string $team, Request $request, CurrentActorResolver $actors,
        TeamManagementAccess $access, UniformLogoService $logos,
    ): JsonResponse {
        [$item, , $canEdit] = $this->teamContext($team, $request, $actors, $access);
        abort_unless($canEdit, 403);
        $data = $this->validateDesign($request);
        // A team kit defines the style/position of a number, never a member's
        // number; the roster's ContractMembership.jersey_number is authoritative.
        $data['jersey_number'] = 1;
        if ($data['logo_source'] === 'team') {
            if (! $item->logo()->exists()) {
                throw ValidationException::withMessages(['logo_source' => 'У команды пока нет логотипа.']);
            }
            if (isset($data['logo_team_id']) && (int) $data['logo_team_id'] !== (int) $item->id) {
                throw ValidationException::withMessages(['logo_team_id' => 'Используйте логотип этой команды.']);
            }
            $data['logo_team_id'] = $item->id;
        }
        $result = DB::transaction(function () use ($item, $request, $data, $logos): UniformDesign {
            // Lock the team, not merely an optional design row, to serialize first creation.
            $locked = Team::query()->lockForUpdate()->findOrFail($item->id);
            $design = $locked->uniformDesign()->with('customLogo')->first();
            if ($data['logo_source'] === 'custom' && ! $request->hasFile('logo') && ! $design?->customLogo()->exists()) {
                throw ValidationException::withMessages(['logo' => 'Загрузите логотип формы команды.']);
            }
            if ($design === null) {
                $design = UniformDesign::query()->create([
                    'team_id' => $locked->id,
                    'user_id' => $request->user()->canonical()->id,
                    ...$this->attributes($data),
                ]);
            } else {
                $design->update($this->attributes($data, $design));
            }
            if ($data['logo_source'] === 'custom' && $request->hasFile('logo')) {
                $logos->upload($design, $request->file('logo'));
            } elseif ($data['logo_source'] !== 'custom') {
                $logos->remove($design);
            }

            return $design->fresh()->load(['team', 'customLogo', 'logoTeam.logo']);
        });

        return $this->json(['design' => $this->present($result), 'can_edit' => true]);
    }

    public function teamLogo(string $team, Request $request, CurrentActorResolver $actors, TeamManagementAccess $access): Response
    {
        [$item] = $this->teamContext($team, $request, $actors, $access);
        $design = $item->uniformDesign()->firstOrFail();
        abort_unless($design->logo_source === 'custom', 404);
        $media = $design->customLogo()->firstOrFail();
        abort_unless(Storage::disk($media->disk)->exists($media->path), 404);

        return response(Storage::disk($media->disk)->get($media->path), 200, [
            'Content-Type' => 'image/webp',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return array{Team, ?\App\Modules\Contract\Domain\Models\ContractMembership, bool} */
    private function teamContext(
        string $identifier, Request $request, CurrentActorResolver $actors, TeamManagementAccess $access,
    ): array {
        $item = Team::query()->whereRouteIdentifier($identifier)
            ->whereNull('temporary_for_event_id')->firstOrFail();
        $user = $request->user()->canonical();
        $member = $item->memberships()
            ->whereIn('user_id', $user->identityIds())
            ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
            ->whereHas('contract', fn ($query) => $query
                ->where('status', ContractStatusEnum::ACTIVE->value)
                ->where(fn ($q) => app(ContractAssurance::class)->constrain($q))
                ->where(fn ($dates) => $dates->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($dates) => $dates->whereNull('expires_at')->orWhere('expires_at', '>', now())))
            ->orderByDesc('id')->first();
        $actor = $actors->resolveForRequest($request);
        $canEdit = $actor !== null && $access->allows($item, $actor, TeamPermissionEnum::MANAGE_UNIFORMS);
        abort_unless($member !== null || $canEdit, 403);

        return [$item, $member, $canEdit];
    }

    private function owned(User $user): Builder
    {
        return UniformDesign::query()->whereNull('team_id')->whereIn('user_id', $user->identityIds());
    }

    private function authorizeOwner(Request $request, UniformDesign $design): UniformDesign
    {
        abort_unless($design->team_id === null && in_array((int) $design->user_id, $request->user()->identityIds(), true), 404);
        return $design;
    }

    private function teams(User $user): Builder
    {
        $ids = $user->identityIds();

        return Team::query()->whereNull('temporary_for_event_id')
            ->where(function (Builder $query) use ($ids): void {
                $query->whereHas('createdByActor', fn (Builder $actor) => $actor->whereIn('user_id', $ids))
                    ->orWhereHas('memberships', fn (Builder $memberships) => $memberships
                        ->whereIn('user_id', $ids)
                        ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value));
            });
    }

    private function validateDesign(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'primary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/D'],
            'secondary_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/D'],
            'jersey_number' => ['sometimes', 'integer', 'between:1,999'],
            'number_enabled' => ['sometimes', 'boolean'],
            'jersey_number_x' => ['sometimes', 'integer', 'between:188,268'],
            'jersey_number_y' => ['sometimes', 'integer', 'between:316,450'],
            'jersey_number_scale' => ['sometimes', 'numeric', 'between:0.4,1.6'],
            'shorts_number_x' => ['sometimes', 'integer', 'between:148,302'],
            'shorts_number_y' => ['sometimes', 'integer', 'between:658,766'],
            'shorts_number_scale' => ['sometimes', 'numeric', 'between:0.4,1.6'],
            'number_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/D'],
            'number_outline' => ['sometimes', 'boolean'],
            'number_outline_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/D'],
            'logo_front_position' => ['sometimes', Rule::in(['none', 'left', 'right'])],
            'logo_back_position' => ['sometimes', Rule::in(['none', 'above', 'below'])],
            'sizes' => ['required', 'array', 'min:1', 'max:6'],
            'sizes.*' => ['required', 'string', 'distinct', Rule::in(['XS','S','M','L','XL','XXL'])],
            'sleeve_style' => ['sometimes', 'required', Rule::in(['sleeved', 'sleeveless'])],
            'logo_source' => ['required', Rule::in(['none', 'team', 'custom'])],
            'logo_team_id' => ['nullable', 'integer', 'min:1'],
            'logo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);
    }

    private function validateLogo(Request $request, array $data, User $user, ?UniformDesign $design = null): void
    {
        $source = $data['logo_source'] ?? 'none';
        if ($source === 'team') {
            if (!isset($data['logo_team_id']) || !$this->teams($user)->whereKey($data['logo_team_id'])
                ->whereHas('logo')->exists()) {
                throw ValidationException::withMessages(['logo_team_id' => 'Выберите доступную команду с логотипом.']);
            }
        }
        if ($source === 'custom' && !$request->hasFile('logo') && !$design?->customLogo()->exists()) {
            throw ValidationException::withMessages(['logo' => 'Загрузите свой логотип.']);
        }
    }

    private function attributes(array $data, ?UniformDesign $design = null): array
    {
        return [
            'name' => trim($data['name']),
            'primary_color' => strtoupper($data['primary_color']),
            'secondary_color' => isset($data['secondary_color']) ? strtoupper($data['secondary_color']) : null,
            'jersey_number' => (int) ($data['jersey_number'] ?? $design?->jersey_number ?? 1),
            'number_enabled' => (bool) ($data['number_enabled'] ?? $design?->number_enabled ?? true),
            'jersey_number_x' => (int) ($data['jersey_number_x'] ?? $design?->jersey_number_x ?? 225),
            'jersey_number_y' => (int) ($data['jersey_number_y'] ?? $design?->jersey_number_y ?? 394),
            'jersey_number_scale' => (float) ($data['jersey_number_scale'] ?? $design?->jersey_number_scale ?? 1),
            'shorts_number_x' => (int) ($data['shorts_number_x'] ?? $design?->shorts_number_x ?? 178),
            'shorts_number_y' => (int) ($data['shorts_number_y'] ?? $design?->shorts_number_y ?? 755),
            'shorts_number_scale' => (float) ($data['shorts_number_scale'] ?? $design?->shorts_number_scale ?? 1),
            'number_color' => isset($data['number_color']) ? strtoupper($data['number_color']) : null,
            'number_outline' => (bool) ($data['number_outline'] ?? false),
            'number_outline_color' => isset($data['number_outline_color']) && ($data['number_outline'] ?? false)
                ? strtoupper($data['number_outline_color']) : null,
            'logo_front_position' => $data['logo_front_position'] ?? $design?->logo_front_position ?? 'left',
            'logo_back_position' => $data['logo_back_position'] ?? $design?->logo_back_position ?? 'above',
            'sizes' => array_values($data['sizes']),
            'sleeve_style' => $data['sleeve_style'] ?? ($design?->sleeve_style ?? 'sleeved'),
            'logo_source' => $data['logo_source'],
            'logo_team_id' => $data['logo_source'] === 'team' ? (int) $data['logo_team_id'] : null,
            'editor_version' => 2,
        ];
    }

    private function present(UniformDesign $design): array
    {
        $logoUrl = match ($design->logo_source) {
            'custom' => $design->customLogo ? ($design->team_id !== null
                ? route('uniforms.teams.logo', $design->team->routeIdentifier())
                : route('uniforms.projects.logo', $design)) : null,
            'team' => $design->logoTeam?->logo?->publicUrl(),
            default => null,
        };
        return [
            'id' => $design->id,
            'team_id' => $design->team_id,
            'name' => $design->name,
            'primary_color' => $design->primary_color,
            'secondary_color' => $design->secondary_color,
            'jersey_number' => $design->jersey_number ?? 1,
            'number_enabled' => (bool) ($design->number_enabled ?? true),
            'jersey_number_x' => $design->jersey_number_x ?? 225,
            'jersey_number_y' => $design->jersey_number_y ?? 394,
            'jersey_number_scale' => $design->jersey_number_scale ?? 1,
            'shorts_number_x' => $design->shorts_number_x ?? 178,
            'shorts_number_y' => $design->shorts_number_y ?? 755,
            'shorts_number_scale' => $design->shorts_number_scale ?? 1,
            'number_color' => $design->number_color,
            'number_outline' => (bool) $design->number_outline,
            'number_outline_color' => $design->number_outline_color,
            'logo_front_position' => $design->logo_front_position ?? 'left',
            'logo_back_position' => $design->logo_back_position ?? 'above',
            'sizes' => $design->sizes,
            'sleeve_style' => $design->sleeve_style,
            'logo_source' => $design->logo_source,
            'logo_team_id' => $design->logo_team_id,
            'logo_url' => $logoUrl,
            'updated_at' => $design->updated_at?->toIso8601String(),
        ];
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store');
    }
}
