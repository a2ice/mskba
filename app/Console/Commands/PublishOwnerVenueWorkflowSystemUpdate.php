<?php

namespace App\Console\Commands;

use App\Modules\Content\Application\Services\ProjectUpdateDrafts;
use App\Modules\Content\Domain\Enums\ContentStatusEnum;
use App\Modules\Content\Domain\Enums\ContentTypeEnum;
use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Content\Domain\Models\ProjectUpdateDetail;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Throwable;

/**
 * The explicitly approved public system update, after a verified release only.
 * No caller-facing API token may publish; the deploy workflow guards the PR's
 * ancestry and all production smoke checks before invoking this command.
 */
final class PublishOwnerVenueWorkflowSystemUpdate extends Command
{
    protected $signature = 'project-updates:publish-owner-venue-workflow {--confirm-release : Invoked after verified production rollout of PR #871}';

    protected $description = 'Idempotently publish the approved owner-contract verification release note';

    private const SOURCE_KEY = 'mskba-owner-venue-workflow-20261120';

    private const PR = 'https://github.com/a2ice/mskba/pull/871';

    public function handle(): int
    {
        if (! $this->option('confirm-release')
            || config('app.env') !== 'production'
            || rtrim((string) config('app.url'), '/') !== 'https://mskba.ru'
            || ! Route::has('account.venue-contracts.show')) {
            $this->error('Refusing publication: verified owner-workflow production release required.');
            return self::FAILURE;
        }

        $dev = User::withTrashed()->where('username', 'dev')->first();
        if ($dev === null || $dev->trashed() || $dev->isBlocked()
            || $dev->system_role !== UserSystemRoleEnum::SYSTEM) {
            $this->error('Scoped system publisher is unavailable. Publication refused.');
            return self::FAILURE;
        }

        try {
            $detail = ProjectUpdateDetail::query()->where('source_key', self::SOURCE_KEY)->first();
            if ($detail?->contentItem?->status === ContentStatusEnum::PUBLISHED) {
                if ($detail->pr_url !== self::PR || ! $detail->contentItem->publish_in_feed) {
                    throw new RuntimeException('Existing published record does not match the verified release.');
                }
                return $this->verifyPublicUrl($detail->contentItem);
            }

            app(ProjectUpdateDrafts::class)->upsert([
                'source_key' => self::SOURCE_KEY,
                'title' => 'Управление площадками: подтверждение полномочий стало удобнее',
                'summary' => 'Приглашения владельцев, подтверждение управления и контракты теперь связаны в личном кабинете MSKBA.',
                'content' => "Обновили оформление полномочий на баскетбольных площадках MSKBA.\n\nПолучатель приглашения может создать аккаунт или войти в существующий, подтвердить почту и перейти в **Мои контракты → Площадки**. Здесь доступна единая карточка подтверждения управления: подача заявки, обоснование полномочий, прикрепление подтверждающих документов и отслеживание статуса.\n\nАдминистрация рассматривает запросы и уточняет сведения в защищённой переписке. При отказе можно скорректировать объяснение и направить новую заявку, а при блокировке повторная подача доступна только после снятия ограничения администратором. После одобрения на странице появляется действующий контракт и назначенные права.\n\nЭти изменения не смешивают проверку полномочий представителя с модерацией самой площадки.",
                'github_pr' => self::PR,
                'task_reference' => 'owner-venue-workflow-20261120',
                'documentation' => ['docs/tasks/venue-owner-claim-pipeline/description.md'],
            ], $dev);

            $item = DB::transaction(function () use ($dev): ContentItem {
                $detail = ProjectUpdateDetail::query()->where('source_key', self::SOURCE_KEY)
                    ->lockForUpdate()->firstOrFail();
                $content = ContentItem::query()->whereKey($detail->content_item_id)
                    ->lockForUpdate()->firstOrFail();
                if ($content->type !== ContentTypeEnum::SYSTEM_UPDATE
                    || $content->status !== ContentStatusEnum::DRAFT
                    || $detail->pr_url !== self::PR
                    || ! in_array('docs/tasks/venue-owner-claim-pipeline/description.md', $detail->documentation_paths ?? [], true)) {
                    throw new RuntimeException('Release draft did not match verified PR/documentation.');
                }
                $detail->forceFill([
                    'production_verified_at' => now(),
                    'production_verified_by_user_id' => null,
                    'source' => 'release-acceptance:github-actions',
                ])->save();
                $content->forceFill([
                    'status' => ContentStatusEnum::PUBLISHED,
                    'publish_in_feed' => true,
                    'publish_in_telegram' => false,
                    'feed_published_at' => now(),
                    'updated_by_user_id' => $dev->id,
                ])->save();

                return $content;
            });

            return $this->verifyPublicUrl($item);
        } catch (Throwable $e) {
            report($e);
            $this->error('Publication failed: '.$e->getMessage());
            return self::FAILURE;
        }
    }

    private function verifyPublicUrl(ContentItem $item): int
    {
        $path = parse_url($item->publicUrl(), PHP_URL_PATH);
        if (! is_string($path) || ! str_starts_with($path, '/feed/')) {
            $this->error('Unexpected public route for system update.');
            return self::FAILURE;
        }

        $url = 'https://mskba.ru'.$path;
        try {
            $response = Http::timeout(20)->get($url);
            if (! $response->successful()
                || ! str_contains($response->body(), 'подтверждение полномочий стало удобнее')
                || str_contains($response->body(), self::PR)
                || str_contains($response->body(), 'docs/tasks/venue-owner-claim-pipeline/description.md')) {
                throw new RuntimeException('Public news not visible or contains private metadata.');
            }
            $this->info('OWNER_VENUE_WORKFLOW_NEWS_PUBLISHED=true');
            $this->line('OWNER_VENUE_WORKFLOW_NEWS_URL='.$url);
            return self::SUCCESS;
        } catch (Throwable $e) {
            report($e);
            $this->error('Public news verification failed: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
