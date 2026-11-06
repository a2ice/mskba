<?php

namespace App\Modules\Uniform\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Uniform\Application\Services\UniformLogoService;
use App\Modules\Uniform\Domain\Models\UniformDesign;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

    private function owned(User $user): Builder
    {
        return UniformDesign::query()->whereIn('user_id', $user->identityIds());
    }

    private function authorizeOwner(Request $request, UniformDesign $design): UniformDesign
    {
        abort_unless(in_array((int) $design->user_id, $request->user()->identityIds(), true), 404);
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
            'secondary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/D'],
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
            'secondary_color' => strtoupper($data['secondary_color']),
            'sizes' => array_values($data['sizes']),
            'sleeve_style' => $data['sleeve_style'] ?? ($design?->sleeve_style ?? 'sleeved'),
            'logo_source' => $data['logo_source'],
            'logo_team_id' => $data['logo_source'] === 'team' ? (int) $data['logo_team_id'] : null,
            'editor_version' => 1,
        ];
    }

    private function present(UniformDesign $design): array
    {
        $logoUrl = match ($design->logo_source) {
            'custom' => $design->customLogo ? route('uniforms.projects.logo', $design) : null,
            'team' => $design->logoTeam?->logo?->publicUrl(),
            default => null,
        };
        return [
            'id' => $design->id,
            'name' => $design->name,
            'primary_color' => $design->primary_color,
            'secondary_color' => $design->secondary_color,
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
