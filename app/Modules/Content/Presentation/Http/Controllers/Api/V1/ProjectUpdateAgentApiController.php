<?php

namespace App\Modules\Content\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Content\Application\Services\ProjectUpdateDrafts;
use App\Modules\Content\Domain\Enums\ContentTypeEnum;
use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Content\Domain\Models\ProjectUpdateDetail;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

final class ProjectUpdateAgentApiController extends Controller
{
    public function store(Request $request, ProjectUpdateDrafts $drafts): JsonResponse
    {
        $actor = $this->authorizeAgent($request);
        $data = $request->validate([
            'source_key' => ['required', 'string', 'min:4', 'max:160', 'regex:/\A[a-zA-Z0-9:_-]+\z/'],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['required', 'string', 'max:1000'],
            'content' => ['required', 'string', 'max:50000'],
            'github_pr' => ['required', 'url:https', 'max:2048', 'regex:~\Ahttps://github\.com/a2ice/mskba/pull/[1-9][0-9]*/?\z~'],
            'task_reference' => ['nullable', 'string', 'max:80'],
            'documentation' => ['required', 'array', 'min:1', 'max:12'],
            'documentation.*' => ['required', 'string', 'distinct', 'max:400', 'regex:~\Adocs/[a-zA-Z0-9_./-]+\.md\z~'],
        ]);

        $item = $drafts->upsert($data, $actor);

        return $this->response($item, 200);
    }

    public function show(Request $request, ContentItem $contentItem): JsonResponse
    {
        $this->authorizeAgent($request);
        abort_unless($contentItem->type === ContentTypeEnum::SYSTEM_UPDATE, 404);

        return $this->response($contentItem, 200);
    }

    private function authorizeAgent(Request $request): User
    {
        $actor = $request->user();
        $token = $actor?->currentAccessToken();

        abort_unless(
            $actor instanceof User
            && $actor->username === 'dev'
            && $actor->system_role === UserSystemRoleEnum::SYSTEM
            && $actor->isConfirmed() && ! $actor->isBlocked() && ! $actor->trashed()
            && $token instanceof PersonalAccessToken
            && str_starts_with((string) $token->name, 'mskba-project-updates:')
            && $token->expires_at !== null
            && $token->expires_at->isFuture()
            && $token->expires_at->lessThanOrEqualTo(now()->addDays(7))
            && $actor->tokenCan('project-updates:write'),
            403,
        );

        return $actor;
    }

    private function response(ContentItem $item, int $status): JsonResponse
    {
        $detail = ProjectUpdateDetail::query()->where('content_item_id', $item->id)->first();

        return response()->json([
            'data' => [
                'id' => $item->id,
                'type' => $item->type->value,
                'status' => $item->status->value,
                'title' => $item->title,
                'summary' => $item->short_description,
                'content' => $item->full_description,
                'url' => $item->status->value === 'published' && $item->publish_in_feed
                    ? $item->publicUrl()
                    : null,
                'source_key' => $detail?->source_key,
                'github_pr' => $detail?->pr_url,
                'documentation' => $detail?->documentation_paths ?? [],
                'task_reference' => $detail?->task_reference,
                'production_verified_at' => $detail?->production_verified_at?->toIso8601String(),
            ],
        ], $status, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
