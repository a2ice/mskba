<?php

namespace App\Console\Commands;

use App\Modules\Content\Domain\Enums\ContentStatusEnum;
use App\Modules\Content\Domain\Enums\ContentTypeEnum;
use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Content\Domain\Models\ProjectUpdateDetail;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Explicit, one-time production acceptance of the first real system update.
 * The GitHub deploy workflow invokes it only after post-deploy checks pass
 * and after verifying task #329's production commit is an ancestor of HEAD.
 * It uses a temporary token belonging to the existing system user "dev"
 * to exercise the actual HTTP draft API before publishing the accepted item.
 */
final class PublishTask329SystemUpdate extends Command
{
    protected $signature = 'project-updates:publish-task-329 {--confirm-release : Deploy workflow confirms verified production and merged PR #821}';

    protected $description = 'One-time idempotent system update smoke test for the delivered venue duplicate management';

    private const SOURCE_KEY = 'mskba-pr821-task329';

    private const PR = 'https://github.com/a2ice/mskba/pull/821';

    public function handle(): int
    {
        if (! $this->option('confirm-release')
            || config('app.env') !== 'production'
            || rtrim((string) config('app.url'), '/') !== 'https://mskba.ru') {
            $this->error('Refusing publication: explicit verified production release is required.');

            return self::FAILURE;
        }

        $detail = ProjectUpdateDetail::query()->where('source_key', self::SOURCE_KEY)->first();
        if ($detail !== null && $detail->contentItem?->status === ContentStatusEnum::PUBLISHED) {
            if ($detail->pr_url !== self::PR || ! $detail->contentItem->publish_in_feed) {
                $this->error('Existing system update does not match the expected published record.');

                return self::FAILURE;
            }

            return $this->verifyPublicUrl($detail->contentItem);
        }

        // System accounts may intentionally be unconfirmed to prevent
        // ordinary browser sign-in. Scoped API tokens only require the
        // explicitly provisioned system role and an unblocked identity.
        $dev = User::withTrashed()->where('username', 'dev')->first();
        if ($dev === null) {
            $this->error('System dev account is missing from production.');
            return self::FAILURE;
        }
        if ($dev->trashed()) {
            $this->error('System dev account is soft-deleted; refusing publication.');
            return self::FAILURE;
        }
        if ($dev->system_role !== UserSystemRoleEnum::SYSTEM) {
            $this->error('Account dev has a non-system role; refusing publication.');
            return self::FAILURE;
        }
        if ($dev->isBlocked()) {
            $this->error('System dev account is explicitly blocked; refusing publication.');
            return self::FAILURE;
        }

        $token = null;
        try {
            $token = $dev->createToken(
                'mskba-project-updates:one-time-prod-329',
                ['project-updates:write'],
                now()->addMinutes(5),
            );

            $response = Http::acceptJson()->withToken($token->plainTextToken)
                ->timeout(20)->post('https://mskba.ru/api/v1/internal/project-updates', [
                    'source_key' => self::SOURCE_KEY,
                    'title' => 'Упростили работу с дублирующимися площадками',
                    'summary' => 'Администраторы MSKBA теперь могут находить и объединять дубли площадок, сохраняя подтверждение основной записи и историю изменений.',
                    'content' => 'В управлении площадками появилась возможность вручную назначать дубли и выбирать основную запись. После объединения повторная карточка скрывается из каталога, а старые ссылки ведут на основную площадку. Если у дубля есть бронирования, заявки или другие связанные данные, объединение останавливается до отдельной проверки — чтобы не нарушить действующие процессы.',
                    'github_pr' => self::PR,
                    'task_reference' => '329',
                    'documentation' => ['docs/tasks/329/description.md'],
                ]);

            if (! $response->successful() || $response->json('data.status') !== 'draft') {
                throw new RuntimeException('Scoped dev draft API did not accept the system update. HTTP '.$response->status());
            }

            $content = DB::transaction(function () use ($dev): ContentItem {
                $detail = ProjectUpdateDetail::query()
                    ->where('source_key', self::SOURCE_KEY)->lockForUpdate()->firstOrFail();
                $content = ContentItem::query()->whereKey($detail->content_item_id)
                    ->lockForUpdate()->firstOrFail();

                if ($content->status !== ContentStatusEnum::DRAFT
                    || $content->type !== ContentTypeEnum::SYSTEM_UPDATE
                    || $detail->pr_url !== self::PR
                    || ! in_array('docs/tasks/329/description.md', $detail->documentation_paths ?? [], true)) {
                    throw new RuntimeException('Draft, PR, or internal documentation did not match task #329.');
                }

                // The caller is the *post-deploy* verification job, not the API token.
                // No external client can publish through the draft endpoint.
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

            return $this->verifyPublicUrl($content);
        } catch (Throwable $e) {
            report($e);
            $this->error('System update smoke/publish failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            // This ephemeral write-only credential is never printed or retained.
            $token?->accessToken?->delete();
        }
    }

    private function verifyPublicUrl(ContentItem $content): int
    {
        // Build from the site's canonical host rather than Laravel's
        // process-local URL generator (which CI may cache as localhost).
        $path = parse_url($content->publicUrl(), PHP_URL_PATH);
        if (! is_string($path) || ! str_starts_with($path, '/feed/')) {
            $this->error('Unexpected system update route.');
            return self::FAILURE;
        }
        $url = 'https://mskba.ru'.$path;
        try {
            $response = Http::timeout(20)->get($url);
            if (! $response->successful()
                || ! str_contains($response->body(), 'Упростили работу с дублирующимися площадками')
                || str_contains($response->body(), self::PR)
                || str_contains($response->body(), 'docs/tasks/329/description.md')) {
                throw new RuntimeException('The public news page is unavailable or contains private references.');
            }
            $this->info('FIRST_SYSTEM_UPDATE_PUBLISHED=true');
            $this->line('FIRST_SYSTEM_UPDATE_URL='.$url);
            return self::SUCCESS;
        } catch (Throwable $e) {
            report($e);
            $this->error('Public system update verification failed: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
