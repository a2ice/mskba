<?php

namespace App\Modules\Content\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Content\Application\Services\PageBuilderMarkup;
use App\Modules\Content\Application\Services\PageBuilderPublisher;
use App\Modules\Content\Application\Services\PageBuilderScriptVersionStore;
use App\Modules\Content\Domain\Models\PageBuilderComponent;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use App\Modules\Content\Domain\Models\PageBuilderRevision;
use App\Modules\Content\Domain\Models\PageBuilderScriptAsset;
use App\Modules\Content\Domain\Models\PageBuilderTemplate;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Dev-only, capability-scoped companion to Filament Page Builder.
 *
 * Never exposes a public write endpoint without an expiring, individually
 * revocable Sanctum PAT and the owner's own backoffice permissions.
 * Explicit read -> conditional draft -> explicit publish is mandatory.
 */
final class PageBuilderAgentApiController extends Controller
{
    public function __construct(
        private readonly PageBuilderMarkup $markup,
        private readonly PageBuilderPublisher $publisher,
        private readonly PageBuilderScriptVersionStore $scripts,
        private readonly BackofficePermissionManager $permissions,
    ) {}

    public function show(Request $request, string $kind, string $key): JsonResponse
    {
        $this->authorizeAgent($request, 'page-builder:read', 'read');
        $record = $this->record($kind, $key);

        return $this->response($record);
    }

    public function stage(Request $request, string $kind, string $key): JsonResponse
    {
        $data = $request->validate([
            'field' => ['required', 'in:html,css,js'],
            'value' => ['present', 'string', 'max:100000'],
            'expected_draft_sha256' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'reason' => ['required', 'string', 'min:4', 'max:300'],
        ]);
        $actor = $this->authorizeAgent($request, 'page-builder:write', 'write', $data['field']);

        $record = $this->record($kind, $key);
        DB::transaction(function () use ($record, $actor, $request, $data): void {
            $record = $this->lock($record);
            $field = $data['field'];
            $before = $this->source($record, $field, 'draft');
            $this->matchHash($data['expected_draft_sha256'], $before);

            $value = $data['value'];
            if ($value === $before) {
                return;
            }

            if ($field === 'html') {
                $this->markup->validate($value, $record->draft_css);
                $record->draft_html = $value;
                $record->save();
            } elseif ($field === 'css') {
                $this->publisher->saveCssDraft($record, $value);
            } else {
                $this->scripts->saveDraft($actor, $record, $value);
            }

            $this->audit($actor, $record, 'agent_stage', $field, $before, $value, $data['reason'], $request);
        }, 3);

        return $this->response($record->fresh(), 200);
    }

    public function publish(Request $request, string $kind, string $key): JsonResponse
    {
        $data = $request->validate([
            'field' => ['required', 'in:html,css,js'],
            'expected_draft_sha256' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'expected_published_sha256' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'reason' => ['required', 'string', 'min:4', 'max:300'],
        ]);
        $actor = $this->authorizeAgent($request, 'page-builder:publish', 'publish', $data['field']);

        $record = $this->record($kind, $key);
        DB::transaction(function () use ($record, $actor, $data, $request): void {
            $record = $this->lock($record);
            $field = $data['field'];
            $draft = $this->source($record, $field, 'draft');
            $published = $this->source($record, $field, 'published');
            $this->matchHash($data['expected_draft_sha256'], $draft);
            $this->matchHash($data['expected_published_sha256'], $published);

            if ($field === 'html') {
                // The normal publisher also handles a component's CSS and a
                // page's URL/SEO: it may not ship unrelated editor drafts.
                $this->assertIsolatedHtmlPublish($record);
                $this->publisher->publish($record);
            } elseif ($field === 'css') {
                abort_if($record->published_at === null, 409, 'Сначала опубликуй компонент в Filament.');
                $this->publisher->saveAndPublishCss($record, $draft);
            } else {
                abort_if(trim($draft) === '', 422, 'Нельзя опубликовать пустой JavaScript.');
                $this->scripts->savePublishAndEnable($actor, $record, $draft);
            }

            if ($published !== $draft) {
                $this->audit($actor, $record, 'agent_publish', $field, $published, $draft, $data['reason'], $request);
            }
        }, 3);

        return $this->response($record->fresh());
    }

    public function rollback(Request $request, string $kind, string $key): JsonResponse
    {
        $data = $request->validate([
            'field' => ['required', 'in:css,js'],
            'expected_published_sha256' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'reason' => ['required', 'string', 'min:4', 'max:300'],
        ]);
        $actor = $this->authorizeAgent($request, 'page-builder:publish', 'publish', $data['field']);
        $record = $this->record($kind, $key);

        DB::transaction(function () use ($record, $actor, $data, $request): void {
            $record = $this->lock($record);
            $before = $this->source($record, $data['field'], 'published');
            $this->matchHash($data['expected_published_sha256'], $before);

            if ($data['field'] === 'css') {
                $this->publisher->rollbackCss($record);
            } else {
                $this->scripts->rollbackAndEnable($actor, $record);
            }

            $after = $this->source($record->fresh(), $data['field'], 'published');
            $this->audit($actor, $record, 'agent_rollback', $data['field'], $before, $after, $data['reason'], $request);
        }, 3);

        return $this->response($record->fresh());
    }

    private function authorizeAgent(Request $request, string $ability, string $operation, ?string $field = null): User
    {
        // A production deploy or a wrongly configured environment fails closed.
        abort_unless(
            config('app.env') === 'staging'
            && rtrim((string) config('app.url'), '/') === 'https://dev.mskba.ru',
            404,
        );

        $actor = $request->user();
        $token = $actor?->currentAccessToken();
        // Sanctum's cookie-based TransientToken, mobile API tokens and
        // unlimited service tokens must never inherit editor permissions.
        abort_unless(
            $actor instanceof User
            && $actor->system_role === UserSystemRoleEnum::SUPERADMIN
            && $actor->isConfirmed() && ! $actor->isBlocked() && ! $actor->trashed()
            && $token instanceof PersonalAccessToken
            && str_starts_with((string) $token->name, 'mskba-pagebuilder-agent:')
            && $token->expires_at !== null
            && $token->expires_at->isFuture()
            && $token->expires_at->lessThanOrEqualTo(now()->addDays(7))
            && $actor->tokenCan($ability),
            403,
        );

        $required = [
            Permission::BACKOFFICE_ACCESS,
            Permission::BACKOFFICE_BUILDER_VIEW,
        ];

        if ($field === 'css' || $operation === 'read') {
            $required[] = Permission::BACKOFFICE_BUILDER_CSS_VIEW;
        }
        if ($field === 'js' || $operation === 'read') {
            $required[] = Permission::BACKOFFICE_BUILDER_JS_VIEW;
        }
        if ($operation !== 'read') {
            $required[] = Permission::BACKOFFICE_BUILDER_EDIT;
            if ($field === 'css') {
                $required[] = Permission::BACKOFFICE_BUILDER_CSS_EDIT;
            } elseif ($field === 'js') {
                $required[] = Permission::BACKOFFICE_BUILDER_JS_EDIT;
            }
        }
        if ($operation === 'publish') {
            $required[] = Permission::BACKOFFICE_BUILDER_PUBLISH;
            if ($field === 'css') {
                $required[] = Permission::BACKOFFICE_BUILDER_CSS_PUBLISH;
            } elseif ($field === 'js') {
                $required[] = Permission::BACKOFFICE_BUILDER_JS_PUBLISH;
            }
        }
        foreach ($required as $permission) {
            abort_unless($this->permissions->allows($actor, $permission), 403);
        }

        return $actor;
    }

    private function record(string $kind, string $key): Model
    {
        return match ($kind) {
            'components' => PageBuilderComponent::query()->where('slug', $key)->firstOrFail(),
            'templates' => PageBuilderTemplate::query()->where('slug', $key)->firstOrFail(),
            'pages' => $key === 'home'
                ? PageBuilderPage::query()->where('path', '/')->firstOrFail()
                : (ctype_digit($key)
                    ? PageBuilderPage::query()->findOrFail((int) $key)
                    : abort(404)),
            default => abort(404),
        };
    }

    private function lock(Model $record): Model
    {
        return $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
    }

    private function source(Model $record, string $field, string $state): string
    {
        if ($field === 'js') {
            $asset = PageBuilderScriptAsset::query()
                ->where('entity_type', PageBuilderPublisher::type($record))
                ->where('entity_id', $record->getKey())->first();

            return (string) ($asset?->{$state.'_js'} ?? '');
        }

        return (string) ($record->{$state.'_'.$field} ?? '');
    }

    private function matchHash(string $expected, string $source): void
    {
        abort_unless(
            hash_equals($expected, hash('sha256', $source)),
            409,
            'Содержимое изменилось в Filament. Прочитай свежую версию и повтори правку.',
        );
    }

    private function assertIsolatedHtmlPublish(Model $record): void
    {
        abort_if($record->published_at === null, 409, 'Сначала опубликуй компонент в Filament.');
        abort_if((string) $record->draft_css !== (string) $record->published_css, 409,
            'В Filament есть неопубликованный CSS. Публикация HTML его не должна затронуть.');

        if ($record instanceof PageBuilderComponent && $record->slug === 'hero-slider') {
            abort_unless($record->draft_slides === $record->published_slides
                && $record->draft_options === $record->published_options,
                409, 'В Hero Slider есть неопубликованные настройки.');
        }
        if ($record instanceof PageBuilderPage) {
            abort_unless(
                $record->path === $record->published_path
                && $record->name === $record->published_name
                && $record->meta_title === $record->published_meta_title
                && $record->meta_description === $record->published_meta_description
                && $record->template_id === $record->published_template_id,
                409, 'У страницы есть неопубликованные метаданные или настройки шаблона.',
            );
        }
    }

    private function audit(
        User $actor,
        Model $record,
        string $action,
        string $field,
        string $before,
        string $after,
        string $reason,
        Request $request,
    ): void {
        PageBuilderRevision::query()->create([
            'entity_type' => PageBuilderPublisher::type($record),
            'entity_id' => $record->getKey(),
            'action' => $action,
            'snapshot' => [
                'field' => $field,
                'before_sha256' => hash('sha256', $before),
                'after_sha256' => hash('sha256', $after),
                'token_id' => $actor->currentAccessToken()?->getKey(),
                'reason' => $reason,
            ],
            'user_id' => $actor->getKey(),
            'created_at' => now(),
        ]);
    }

    private function response(Model $record, int $status = 200): JsonResponse
    {
        $record->refresh();
        $type = PageBuilderPublisher::type($record);
        $asset = PageBuilderScriptAsset::query()
            ->where('entity_type', $type)->where('entity_id', $record->getKey())->first();

        $sources = [];
        foreach (['html', 'css', 'js'] as $field) {
            $draft = $this->source($record, $field, 'draft');
            $published = $this->source($record, $field, 'published');
            $sources[$field] = [
                'draft' => $draft,
                'published' => $published,
                'draft_sha256' => hash('sha256', $draft),
                'published_sha256' => hash('sha256', $published),
                'pending' => $draft !== $published,
            ];
        }

        return response()->json([
            'data' => [
                'type' => $type,
                'id' => $record->getKey(),
                'name' => $record->name,
                'slug' => $record->slug ?? null,
                'published_at' => $record->published_at?->toIso8601String(),
                'js_enabled' => (bool) ($asset?->is_enabled ?? false),
                'sources' => $sources,
                'recent_revisions' => PageBuilderRevision::query()
                    ->where('entity_type', $type)->where('entity_id', $record->getKey())
                    ->orderByDesc('id')->limit(10)->get(['id', 'action', 'user_id', 'created_at'])
                    ->toArray(),
            ],
        ], $status, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
