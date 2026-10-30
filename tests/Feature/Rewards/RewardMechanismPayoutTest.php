<?php

namespace Tests\Feature\Rewards;

use App\Modules\Acquisition\Domain\Models\ReferralAttribution;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletOperationTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletOwnerTypeEnum;
use App\Modules\Finance\Domain\Models\Wallet;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Application\UseCases\CompleteAccountConfirmationWizardHandler;
use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Events\UserAccountConfirmed;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Moderation\Domain\Enums\ModerationRequestStatusEnum;
use App\Modules\Moderation\Domain\Enums\ModerationTypeEnum;
use App\Modules\Moderation\Domain\Events\ModerationRequestApproved;
use App\Modules\Moderation\Domain\Models\ModerationRequest;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Notification\Domain\Events\UserNotificationCreated;
use App\Modules\Notification\Domain\Models\UserNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use App\Modules\Rewards\Domain\Enums\RewardGrantStatusEnum;
use App\Modules\Rewards\Domain\Models\RewardGrant;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

final class RewardMechanismPayoutTest extends TestCase
{
    // These tests exercise after-commit listeners. An outer test transaction
    // would suppress queue execution until after test teardown.
    use DatabaseMigrations;

    public function test_confirming_direct_referral_credits_300_bonus_rubles_once(): void
    {
        $referrer = $this->confirmedUser('reward_root');
        $referred = $this->unconfirmedUser('reward_child');

        $this->referral($referrer, $referred);
        $this->addVerifiedPrimaryEmail($referred);

        Event::fake([UserNotificationCreated::class]);
        $this->confirmAccount($referred);

        $this->assertSame(UserStatusEnum::CONFIRMED, $referred->refresh()->status);
        $this->assertSame(30_000, $this->bonusBalance($referrer));

        $grant = RewardGrant::query()
            ->whereHas('reward', fn ($query) => $query->where('code', 'referral_user_confirmed'))
            ->sole();

        $this->assertSame(RewardGrantStatusEnum::COMPLETED, $grant->status);
        $this->assertSame(WalletOperationTypeEnum::REFERRAL_REWARD, $grant->wallet_operation_type);
        $this->assertSame($referrer->id, $grant->recipient_user_id);

        $notice = $this->assertRewardNotice($referrer, $grant, 'Бонус за приглашённого друга', 30_000);
        Event::assertDispatched(
            UserNotificationCreated::class,
            fn (UserNotificationCreated $event): bool => $event->notificationId === $notice->id,
        );

        event(new UserAccountConfirmed((int) $referred->id));

        $this->assertSame(30_000, $this->bonusBalance($referrer));
        $this->assertSame(
            1,
            RewardGrant::query()
                ->where('business_fact_type', 'user_confirmation')
                ->where('business_fact_key', 'user:'.$referred->id)
                ->count(),
        );
        $this->assertSame(1, UserNotification::query()->where('user_id', $referrer->id)
            ->where('type', UserNotificationTypeEnum::FINANCE->value)->count());
        Event::assertDispatchedTimes(UserNotificationCreated::class, 1);
    }

    public function test_second_level_chain_credits_direct_referrer_300_and_root_referrer_100(): void
    {
        $root = $this->confirmedUser('reward_a');
        $direct = $this->confirmedUser('reward_b');
        $confirmed = $this->unconfirmedUser('reward_c');

        $this->referral($root, $direct);
        $this->referral($direct, $confirmed);
        $this->addVerifiedPrimaryEmail($confirmed);

        $this->confirmAccount($confirmed);

        $this->assertSame(30_000, $this->bonusBalance($direct));
        $this->assertSame(10_000, $this->bonusBalance($root));

        $directGrant = RewardGrant::query()->where('recipient_user_id', $direct->id)->sole();
        $rootGrant = RewardGrant::query()->where('recipient_user_id', $root->id)->sole();
        $this->assertRewardNotice($direct, $directGrant, 'Бонус за приглашённого друга', 30_000);
        $this->assertRewardNotice($root, $rootGrant, 'Бонус за приглашение второго уровня', 10_000);

        event(new UserAccountConfirmed((int) $confirmed->id));

        $this->assertSame(30_000, $this->bonusBalance($direct));
        $this->assertSame(10_000, $this->bonusBalance($root));
        $this->assertSame(
            2,
            RewardGrant::query()
                ->where('business_fact_type', 'user_confirmation')
                ->where('business_fact_key', 'user:'.$confirmed->id)
                ->count(),
        );
        $this->assertSame(2, UserNotification::query()->where('type', 'finance')->count());
    }

    public function test_first_successful_venue_moderation_credits_creator_100_bonus_rubles_once(): void
    {
        $creator = $this->confirmedUser('venue_reward_creator');
        $moderator = $this->confirmedUser('venue_reward_admin', UserSystemRoleEnum::SUPERADMIN);
        $actor = app(CurrentActorResolver::class)->resolve($creator, null);

        $this->assertNotNull($actor);

        $venue = Venue::factory()->create([
            'created_by_actor_id' => $actor->id,
            'status' => VenueStatusEnum::UNCONFIRMED,
        ]);

        $request = ModerationRequest::query()->create([
            'type' => ModerationTypeEnum::VENUE,
            'subject_id' => $venue->id,
            'venue_revision_id' => null,
            'submitted_by_actor_id' => $actor->id,
            'reviewed_by_user_id' => $moderator->id,
            'status' => ModerationRequestStatusEnum::APPROVED,
            'submitted_at' => now()->subMinute(),
            'reviewed_at' => now(),
        ]);

        event(new ModerationRequestApproved($request));

        $this->assertSame(10_000, $this->bonusBalance($creator));

        $grant = RewardGrant::query()
            ->whereHas('reward', fn ($query) => $query->where('code', 'venue_first_approval'))
            ->sole();

        $this->assertSame(WalletOperationTypeEnum::BONUS_GRANT, $grant->wallet_operation_type);
        $this->assertSame('venue:'.$venue->id, $grant->business_fact_key);
        $this->assertRewardNotice($creator, $grant, 'Бонус за новую площадку', 10_000);

        $secondRequest = ModerationRequest::query()->create([
            'type' => ModerationTypeEnum::VENUE,
            'subject_id' => $venue->id,
            'venue_revision_id' => null,
            'submitted_by_actor_id' => $actor->id,
            'reviewed_by_user_id' => $moderator->id,
            'status' => ModerationRequestStatusEnum::APPROVED,
            'submitted_at' => now(),
            'reviewed_at' => now(),
        ]);

        event(new ModerationRequestApproved($secondRequest));

        $this->assertSame(10_000, $this->bonusBalance($creator));
        $this->assertSame(
            1,
            RewardGrant::query()
                ->where('business_fact_type', 'venue_first_approval')
                ->where('business_fact_key', 'venue:'.$venue->id)
                ->count(),
        );
        $this->assertSame(1, UserNotification::query()->where('user_id', $creator->id)
            ->where('type', 'finance')->count());
    }

    public function test_reward_listeners_wait_for_outer_transaction_commit_before_queueing(): void
    {
        // Force a real database queue: sync test queues would execute inline,
        // hiding a regression in after-commit dispatch semantics.
        config()->set('queue.default', 'database');

        $confirmed = $this->confirmedUser('queue_referral_subject');

        DB::transaction(function () use ($confirmed): void {
            event(new UserAccountConfirmed((int) $confirmed->id));
            $this->assertSame(0, $this->queuedRewardCount('ProcessUserAccountConfirmedRewards'));
        });

        $this->assertSame(1, $this->queuedRewardCount('ProcessUserAccountConfirmedRewards'));

        $actor = app(CurrentActorResolver::class)->resolve($confirmed, null);
        $this->assertNotNull($actor);
        $venue = Venue::factory()->create(['created_by_actor_id' => $actor->id]);
        $request = ModerationRequest::query()->create([
            'type' => ModerationTypeEnum::VENUE,
            'subject_id' => $venue->id,
            'venue_revision_id' => null,
            'submitted_by_actor_id' => $actor->id,
            'status' => ModerationRequestStatusEnum::APPROVED,
            'submitted_at' => now(),
            'reviewed_at' => now(),
        ]);

        DB::transaction(function () use ($request): void {
            event(new ModerationRequestApproved($request));
            $this->assertSame(0, $this->queuedRewardCount('ProcessVenueFirstApprovalReward'));
        });

        $this->assertSame(1, $this->queuedRewardCount('ProcessVenueFirstApprovalReward'));
        // Both queued listener types survive the commit; neither ran inline.
        $this->assertSame(0, RewardGrant::query()->count());
    }

    public function test_rolled_back_confirmation_does_not_enqueue_a_reward(): void
    {
        config()->set('queue.default', 'database');

        $confirmed = $this->confirmedUser('queue_rollback_subject');

        try {
            DB::transaction(function () use ($confirmed): void {
                event(new UserAccountConfirmed((int) $confirmed->id));
                throw new \RuntimeException('rollback on purpose');
            });
            $this->fail('Expected outer transaction rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback on purpose', $exception->getMessage());
        }

        $this->assertSame(0, $this->queuedRewardCount('ProcessUserAccountConfirmedRewards'));
        $this->assertSame(0, RewardGrant::query()->count());
        $this->assertSame(0, UserNotification::query()->where('type', 'finance')->count());
    }

    public function test_personal_referral_link_is_attached_only_on_new_users_first_login(): void
    {
        $referrer = $this->confirmedUser('referral_link_owner');

        $this->get(route('referral.entry', ['username' => $referrer->username]))
            ->assertRedirect(route('acquisition.join'));

        $referred = User::factory()->create([
            'username' => 'new_referral_user',
            'password' => 'Strong1!',
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $this->post(route('auth.login'), [
            'login' => $referred->username,
            'password' => 'Strong1!',
        ])->assertRedirect();

        $attribution = ReferralAttribution::query()
            ->where('referred_user_id', $referred->id)
            ->sole();

        $this->assertSame($referrer->id, $attribution->referrer_user_id);
        $this->assertSame('referral_link', $attribution->source);
    }

    private function assertRewardNotice(
        User $recipient,
        RewardGrant $grant,
        string $title,
        int $amountMinor,
    ): UserNotification {
        $notification = UserNotification::query()
            ->where('user_id', $recipient->id)
            ->where('type', UserNotificationTypeEnum::FINANCE->value)
            ->sole();

        $this->assertSame($title, $notification->title);
        $this->assertSame('rewards.grant.completed', $notification->payload['source']);
        $this->assertSame($grant->id, $notification->payload['reward_grant_id']);
        $this->assertSame($grant->wallet_operation_id, $notification->payload['wallet_operation_id']);
        $this->assertSame($amountMinor, $notification->payload['amount_minor']);
        $this->assertSame('/account/wallet', $notification->action_url);
        $this->assertSame('Открыть кошелёк', $notification->action_text);
        $this->assertStringContainsString('бонусами', $notification->body);

        return $notification;
    }

    private function queuedRewardCount(string $listener): int
    {
        return (int) DB::table('jobs')->where('payload', 'like', '%'.$listener.'%')->count();
    }

    private function confirmedUser(
        string $username,
        UserSystemRoleEnum $role = UserSystemRoleEnum::USER,
    ): User {
        return User::factory()->create([
            'username' => $username,
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => $role,
        ]);
    }

    private function unconfirmedUser(string $username): User
    {
        return User::factory()->create([
            'username' => $username,
            'status' => UserStatusEnum::UNCONFIRMED,
        ]);
    }

    private function referral(User $referrer, User $referred): ReferralAttribution
    {
        return ReferralAttribution::query()->create([
            'referrer_user_id' => $referrer->canonical()->id,
            'referred_user_id' => $referred->canonical()->id,
            'source' => 'test',
            'captured_at' => now()->subMinute(),
            'linked_at' => now(),
        ]);
    }

    private function confirmAccount(User $user): User
    {
        return app(CompleteAccountConfirmationWizardHandler::class)->handle(
            user: $user,
            firstName: 'Иван',
            lastName: 'Иванов',
            birthDate: '2000-04-10',
            gender: UserGenderEnum::MALE,
        );
    }

    private function addVerifiedPrimaryEmail(User $user): void
    {
        $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => $user->username.'@example.test',
            'is_primary' => true,
            'is_public' => false,
            'verified_at' => now(),
        ]);
    }

    private function bonusBalance(User $user): int
    {
        return (int) Wallet::query()
            ->where('owner_type', WalletOwnerTypeEnum::USER->value)
            ->where('owner_id', $user->canonical()->id)
            ->value('bonus_balance_minor');
    }
}
