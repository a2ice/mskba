<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Application\UseCases\AdminUpdateUserOperationalPermissionsHandler;
use App\Modules\Identity\Application\UseCases\AdminUpdateUserSystemRoleHandler;
use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\ProfileChangeRequest;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\Services\ProfileRequestThreadAccess;
use App\Modules\Notification\Domain\Models\RequestThread;
use App\Modules\Notification\Domain\Models\RequestThreadMembership;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Notification\Infrastructure\Broadcasting\ConversationChannel;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class ProfileRequestThreadPilotTest extends TestCase
{
    use RefreshDatabase;

    private string $oldTheme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->oldTheme = (string) config('themes.active');
        config()->set('themes.active', 'mskba_app');
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
        app()->forgetInstance(ThemeResolver::class);
    }

    protected function tearDown(): void
    {
        config()->set('themes.active', $this->oldTheme);
        View::replaceNamespace('theme', resource_path('themes/'.$this->oldTheme.'/views'));
        app()->forgetInstance(ThemeResolver::class);
        parent::tearDown();
    }

    private function account(UserSystemRoleEnum $role = UserSystemRoleEnum::USER): User
    {
        $user = User::factory()->create(['system_role' => $role, 'status' => UserStatusEnum::CONFIRMED]);
        $user->createProfile(['first_name' => 'Мария', 'last_name' => 'Иванова',
            'birth_date' => '2000-05-20', 'gender' => UserGenderEnum::FEMALE]);

        return $user;
    }

    private function change(User $applicant): ProfileChangeRequest
    {
        $this->actingAs($applicant)->post(route('account.profile-change-requests.store'), [
            'first_name' => 'Анна', 'last_name' => 'Иванова', 'birth_date' => '2000-05-20',
            'gender' => 'female', 'reason' => 'Исправляю ошибку в своих данных.',
        ])->assertRedirect(route('account.profile'));

        return ProfileChangeRequest::query()->latest('id')->firstOrFail();
    }

    private function grant(User $admin, User $superadmin): void
    {
        app(AdminUpdateUserOperationalPermissionsHandler::class)
            ->handle($superadmin, $admin->id, [Permission::REVIEW_PROFILE_CHANGES]);
    }

    public function test_new_admin_reviewer_baseline_does_not_expose_requests_before_role_acquisition(): void
    {
        $applicant = $this->account();
        $old = $this->change($applicant);
        $admin = $this->account(UserSystemRoleEnum::ADMIN);
        $this->actingAs($admin)->get(route('account.notifications.threads.profile', $old))->assertForbidden();

        $new = $this->change($this->account());
        $this->actingAs($admin)->get(route('account.notifications.threads.profile', $old))->assertForbidden();
        $this->get(route('account.notifications'))->assertOk()
            ->assertDontSee('Изменение данных №'.$old->id);
        $this->get(route('account.notifications.threads.profile', $new))->assertOk();
    }

    public function test_lead_invites_late_admin_and_transfers_lead_then_removes_predecessor(): void
    {
        $applicant = $this->account();
        $superadmin = $this->account(UserSystemRoleEnum::SUPERADMIN);
        $admin = $this->account(UserSystemRoleEnum::ADMIN);
        $old = $this->change($applicant);
        $this->grant($admin, $superadmin);

        $this->actingAs($superadmin)->post(route('account.notifications.threads.profile.claim', $old))->assertRedirect();
        // Repeating the same HTTP POST must not duplicate audit records or notices.
        $this->post(route('account.notifications.threads.profile.claim', $old))->assertRedirect();
        $this->assertSame(1, UserNotification::query()->where('user_id', $applicant->id)
            ->where('payload->source', 'identity.profile_change.claimed')->count());
        $thread = RequestThread::query()->where('subject_id', $old->id)->sole();
        $this->assertSame(1, $thread->events()->where('action', 'lead_assigned')->count());
        $this->assertSame($superadmin->id, $thread->fresh()->lead_user_id);
        $this->post(route('account.notifications.threads.profile.invite', $old), ['user_id' => $admin->id])->assertRedirect();
        $this->assertSame(1, UserNotification::query()->where('user_id', $admin->id)
            ->where('payload->source', 'identity.profile_change.invited')->count());
        $this->actingAs($admin)->get(route('account.notifications.threads.profile', $old))->assertOk()
            ->assertSee('Заявка отправлена на рассмотрение.');
        $this->actingAs($superadmin)->post(route('account.notifications.threads.profile.transfer', $old),
            ['user_id' => $admin->id])->assertRedirect();
        $this->assertSame($admin->id, $thread->fresh()->lead_user_id);
        $this->assertSame(1, UserNotification::query()->where('user_id', $admin->id)
            ->where('payload->source', 'identity.profile_change.lead_transferred')->count());
        $this->actingAs($admin)->post(route('account.notifications.threads.profile.remove', $old),
            ['user_id' => $superadmin->id])->assertRedirect();
        $this->assertSame(1, UserNotification::query()->where('user_id', $superadmin->id)
            ->where('payload->source', 'identity.profile_change.excluded')->count());
        $this->actingAs($superadmin)->get(route('account.notifications.threads.profile', $old))->assertOk()
            ->assertSee('Ты был исключён')->assertDontSee('Исправляю ошибку в своих данных.');
        $this->assertTrue(UserNotification::query()->where('user_id', $superadmin->id)
            ->where('payload->source', 'identity.profile_change.excluded')
            ->whereNotNull('read_at')->exists(), 'Открытие закрытого треда должно погасить уведомление.');
        $this->get(route('account.notifications'))->assertOk()->assertSee('Изменение данных №'.$old->id);
        $this->post(route('account.notifications.threads.profile.messages', $old),
            ['body' => 'Нельзя писать после исключения'])->assertForbidden();
    }

    public function test_revocation_keeps_tombstone_even_after_new_grant(): void
    {
        $applicant = $this->account();
        $superadmin = $this->account(UserSystemRoleEnum::SUPERADMIN);
        $admin = $this->account(UserSystemRoleEnum::ADMIN);
        $this->grant($admin, $superadmin);
        $request = $this->change($applicant);
        $this->actingAs($admin)->post(route('account.notifications.threads.profile.claim', $request))->assertRedirect();
        $this->assertDatabaseHas('request_thread_memberships', ['user_id' => $admin->id, 'removed_at' => null]);
        app(AdminUpdateUserSystemRoleHandler::class)
            ->handle($superadmin, $admin->id, UserSystemRoleEnum::MANAGER);
        $admin->refresh();
        $this->assertSame('permission_revoked', RequestThreadMembership::query()->where('user_id', $admin->id)->sole()->removal_reason);
        $this->actingAs($admin)->get(route('account.notifications.threads.profile', $request))->assertOk()->assertSee('Доступ к обсуждению прекращён');
        $this->get(route('account.notifications'))->assertOk()->assertSee('Изменение данных №'.$request->id);
        app(AdminUpdateUserSystemRoleHandler::class)
            ->handle($superadmin, $admin->id, UserSystemRoleEnum::ADMIN);
        $admin->refresh();
        $this->get(route('account.notifications.threads.profile', $request))->assertOk()->assertSee('Доступ к обсуждению прекращён');
        $this->post(route('account.notifications.threads.profile.messages', $request), ['body' => 'Доступ запрещён'])->assertForbidden();
    }

    public function test_regrant_resets_history_boundary_for_requests_not_joined_before_revocation(): void
    {
        $superadmin = $this->account(UserSystemRoleEnum::SUPERADMIN);
        $admin = $this->account(UserSystemRoleEnum::ADMIN);
        $this->grant($admin, $superadmin);
        $visibleBeforeRevoke = $this->change($this->account());
        $this->actingAs($admin)->get(route('account.notifications.threads.profile', $visibleBeforeRevoke))
            ->assertOk();

        app(AdminUpdateUserSystemRoleHandler::class)
            ->handle($superadmin, $admin->id, UserSystemRoleEnum::MANAGER);
        $admin->refresh();
        $submittedWhileRevoked = $this->change($this->account());
        $this->actingAs($admin)->get(route('account.notifications.threads.profile', $submittedWhileRevoked))
            ->assertForbidden();

        app(AdminUpdateUserSystemRoleHandler::class)
            ->handle($superadmin, $admin->id, UserSystemRoleEnum::ADMIN);
        $admin->refresh();
        $this->get(route('account.notifications.threads.profile', $visibleBeforeRevoke))
            ->assertForbidden();
        $this->get(route('account.notifications.threads.profile', $submittedWhileRevoked))
            ->assertForbidden();
        $this->assertDatabaseHas('review_permission_cursors', [
            'user_id' => $admin->id,
            'after_request_id' => $submittedWhileRevoked->id,
        ]);
        $visibleAfterRegrant = $this->change($this->account());
        $this->get(route('account.notifications.threads.profile', $visibleAfterRegrant))
            ->assertOk();
    }

    public function test_messages_private_and_applicant_can_reply_but_not_manage_members(): void
    {
        $applicant = $this->account();
        $outsider = $this->account();
        $superadmin = $this->account(UserSystemRoleEnum::SUPERADMIN);
        $request = $this->change($applicant);
        $this->actingAs($superadmin)->post(route('account.notifications.threads.profile.claim', $request))->assertRedirect();
        $this->post(route('account.notifications.threads.profile.messages', $request),
            ['body' => 'Просьба уточнить причину изменений'])->assertRedirect();
        $this->actingAs($applicant)->get(route('account.notifications.threads.profile', $request))
            ->assertOk()->assertSee('Просьба уточнить причину изменений');
        $this->post(route('account.notifications.threads.profile.messages', $request),
            ['body' => 'Подтверждаю причину изменения'])->assertRedirect();
        $this->post(route('account.notifications.threads.profile.invite', $request), ['user_id' => $outsider->id])->assertForbidden();
        $this->actingAs($outsider)->get(route('account.notifications.threads.profile', $request))->assertForbidden();
        $this->post(route('account.notifications.threads.profile.messages', $request), ['body' => 'Несанкционированный ответ'])->assertForbidden();
    }

    public function test_granted_admin_can_review_new_request_and_search_only_eligible_reviewers(): void
    {
        $applicant = $this->account();
        $superadmin = $this->account(UserSystemRoleEnum::SUPERADMIN);
        $admin = $this->account(UserSystemRoleEnum::ADMIN);
        $ineligible = $this->account(UserSystemRoleEnum::MANAGER);
        $superadmin->forceFill(['username' => 'superpilot'])->save();
        $ineligible->forceFill(['username' => 'disabledpilot'])->save();
        $this->grant($admin, $superadmin);
        $request = $this->change($applicant);

        $this->actingAs($admin)->get(route('admin.profile-change-requests'))->assertOk()
            ->assertSee('Заявка №'.$request->id);
        $this->get(route('account.notifications.threads.profile', $request))->assertOk()
            ->assertSee('Взять заявку в работу');
        $this->post(route('account.notifications.threads.profile.claim', $request))->assertRedirect();
        $this->get(route('account.notifications.threads.profile.candidates', $request).'?q='.$ineligible->username)
            ->assertOk()->assertJsonMissing(['id' => $ineligible->id]);
        $this->get(route('account.notifications.threads.profile.candidates', $request).'?q='.$superadmin->username)
            ->assertOk()->assertJsonFragment(['id' => $superadmin->id]);
        $this->post(route('account.notifications.threads.profile.invite', $request),
            ['user_id' => $ineligible->id])->assertStatus(422);
        $this->post(route('admin.profile-change-requests.review', $request), ['action' => 'approve'])
            ->assertRedirect(route('admin.profile-change-requests'));
        $this->assertSame('Анна', $applicant->profile->fresh()->first_name);
    }

    public function test_failed_review_does_not_create_lead_or_membership(): void
    {
        $applicant = $this->account();
        $superadmin = $this->account(UserSystemRoleEnum::SUPERADMIN);
        $request = $this->change($applicant);
        // A verified profile changed since submission: approval must be rejected.
        $applicant->profile()->firstOrFail()->update(['first_name' => 'Другое имя']);
        $this->actingAs($superadmin)
            ->post(route('admin.profile-change-requests.review', $request), ['action' => 'approve'])
            ->assertSessionHasErrors('review_reason');
        $thread = RequestThread::query()->where('subject_id', $request->id)->sole();
        $this->assertNull($thread->lead_user_id);
        $this->assertSame(0, $thread->memberships()->count());
        $this->assertSame(0, $thread->events()->count());
        $this->assertSame(ProfileChangeRequest::PENDING, $request->fresh()->status);
    }

    public function test_emergency_superadmin_takeover_requires_disqualified_lead_and_is_audited(): void
    {
        $applicant = $this->account();
        $lead = $this->account(UserSystemRoleEnum::SUPERADMIN);
        $rescue = $this->account(UserSystemRoleEnum::SUPERADMIN);
        $request = $this->change($applicant);
        $this->actingAs($lead)->post(route('account.notifications.threads.profile.claim', $request))->assertRedirect();
        $this->actingAs($rescue)->post(route('account.notifications.threads.profile.rescue', $request))
            ->assertForbidden();
        // Account block removes effective reviewer authority, independent of role.
        $lead->forceFill(['status' => UserStatusEnum::BLOCKED])->save();
        $this->post(route('account.notifications.threads.profile.rescue', $request))->assertRedirect();
        $thread = RequestThread::query()->where('subject_id', $request->id)->sole();
        $this->assertSame($rescue->id, $thread->lead_user_id);
        $this->assertDatabaseHas('request_thread_events', ['request_thread_id' => $thread->id, 'action' => 'lead_rescued']);
        $this->assertSame(1, UserNotification::query()->where('user_id', $applicant->id)
            ->where('payload->source', 'identity.profile_change.lead_rescued')->count());
    }

    public function test_new_superadmin_does_not_inherit_history_from_before_role_existed(): void
    {
        $applicant = $this->account();
        $legacyReviewer = $this->account(UserSystemRoleEnum::SUPERADMIN);
        $oldRequest = $this->change($applicant);
        $newReviewer = $this->account(UserSystemRoleEnum::SUPERADMIN);

        $this->actingAs($newReviewer)->get(route('account.notifications.threads.profile', $oldRequest))
            ->assertForbidden();
        $this->get(route('account.notifications'))->assertOk()
            ->assertDontSee('Изменение данных №'.$oldRequest->id);

        // A new request captures the new reviewer's current boundary before insert.
        $otherApplicant = $this->account();
        $newRequest = $this->change($otherApplicant);
        $this->actingAs($newReviewer)->get(route('account.notifications.threads.profile', $newRequest))
            ->assertOk();
        $this->get(route('account.notifications.threads.profile', $oldRequest))
            ->assertForbidden();
        $this->assertDatabaseHas('review_permission_cursors', [
            'user_id' => $newReviewer->id,
            'after_request_id' => $oldRequest->id,
        ]);
    }

    public function test_multiple_profile_threads_use_bounded_query_count(): void
    {
        $viewer = $this->account(UserSystemRoleEnum::SUPERADMIN);
        $applicant = $this->account();
        // The domain service initializes a default grant before real submissions;
        // this fixture inserts test records directly, so initialize explicitly.
        app(ProfileRequestThreadAccess::class)->initializeNewSuperadminCursors();
        $original = ['first_name' => 'Мария', 'last_name' => 'Иванова', 'birth_date' => '2000-05-20', 'gender' => 'female'];
        for ($i = 0; $i < 24; $i++) {
            $request = ProfileChangeRequest::query()->create([
                'user_id' => $applicant->id, 'status' => ProfileChangeRequest::REJECTED,
                'original_data' => $original, 'proposed_data' => $original,
                'reason' => 'Тестовая архивная заявка '.$i,
            ]);
            RequestThread::query()->create(['type' => 'profile_change', 'subject_id' => $request->id]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($viewer)->get(route('account.notifications'))->assertOk()
            ->assertSee('Изменение данных №24');
        $queries = DB::getQueryLog();

        DB::disableQueryLog();
        $threadQueries = array_values(array_filter($queries, fn ($query) => str_contains($query['query'], 'profile_change_requests')
            || str_contains($query['query'], 'request_thread_memberships')
            || str_contains($query['query'], 'review_permission_cursors')
            || str_contains($query['query'], 'from "request_threads"')));
        $this->assertLessThanOrEqual(7, count($threadQueries), '24 треда должны загружаться пакетно.');
        $this->assertSame(0, count(array_filter($queries, fn ($query) => str_contains(strtolower($query['query']), ' join request_thread_memberships '))));
    }

    public function test_profile_timeline_is_cursor_paginated_and_hidden_after_reviewer_removal(): void
    {
        $applicant = $this->account();
        $superadmin = $this->account(UserSystemRoleEnum::SUPERADMIN);
        $outsider = $this->account();
        $request = $this->change($applicant);
        $thread = RequestThread::query()->where('subject_id', $request->id)->sole();
        foreach (range(1, 24) as $i) {
            $thread->messages()->create([
                'sender_user_id' => $applicant->id,
                'body' => 'Test message '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            ]);
        }
        $url = route('account.notifications.threads.profile.history', $request);
        $this->actingAs($outsider)->getJson($url)->assertForbidden();
        $this->actingAs($applicant)->getJson($url.'?before=invalid')->assertUnprocessable();
        $latest = $this->getJson($url)->assertOk();
        $this->assertSame(10, substr_count($latest->json('html'), 'data-chat-entry='));
        $this->assertStringContainsString('Test message 24', $latest->json('html'));
        $this->assertStringNotContainsString('Test message 14', $latest->json('html'));
        $older = $this->getJson($url.'?before='.urlencode($latest->json('next_before')))->assertOk();
        $this->assertSame(10, substr_count($older->json('html'), 'data-chat-entry='));
        $this->assertStringContainsString('Test message 05', $older->json('html'));
        $this->assertStringNotContainsString('Test message 15', $older->json('html'));
        $this->assertTrue(app(ConversationChannel::class)->profile($applicant, $thread->id));
        $this->actingAs($superadmin)->post(route('account.notifications.threads.profile.claim', $request))
            ->assertRedirect();
        $this->assertTrue(app(ConversationChannel::class)->profile($superadmin, $thread->id));
        $admin = $this->account(UserSystemRoleEnum::ADMIN);
        $this->grant($admin, $superadmin);
        $this->post(route('account.notifications.threads.profile.invite', $request), ['user_id' => $admin->id])
            ->assertRedirect();
        $this->assertTrue(app(ConversationChannel::class)->profile($admin, $thread->id));
        $this->post(route('account.notifications.threads.profile.remove', $request), ['user_id' => $admin->id])
            ->assertRedirect();
        $this->assertFalse(app(ConversationChannel::class)->profile($admin, $thread->id));
        $this->actingAs($admin)->getJson($url)->assertForbidden();
    }

    public function test_new_notification_after_opening_profile_thread_can_be_acknowledged_without_reloading(): void
    {
        $applicant = $this->account();
        $outsider = $this->account();
        $request = $this->change($applicant);
        $this->actingAs($applicant)->get(route('account.notifications.threads.profile', $request))->assertOk();

        $incoming = UserNotification::query()->create([
            'user_id' => $applicant->id, 'type' => 'system', 'status' => 'new',
            'title' => 'Новое сообщение по заявке',
            'body' => 'Новое сообщение поступило после открытия чата',
            'payload' => ['profile_change_request_id' => $request->id],
        ]);
        $unrelated = UserNotification::query()->create([
            'user_id' => $applicant->id, 'type' => 'system', 'status' => 'new',
            'title' => 'Другое уведомление', 'body' => 'Не относится к заявке',
        ]);
        $readUrl = route('account.notifications.threads.profile.read', $request);
        $this->actingAs($outsider)->patchJson($readUrl)->assertForbidden();
        $this->assertNull($incoming->fresh()->read_at);
        $this->actingAs($applicant)->patchJson($readUrl)->assertOk()
            ->assertJsonPath('unread_count', 1);
        $this->assertNotNull($incoming->fresh()->read_at);
        $this->assertNull($unrelated->fresh()->read_at);
        $this->patchJson($readUrl)->assertOk()->assertJsonPath('unread_count', 1);
        $this->get(route('account.notifications.threads.profile', $request))->assertOk()
            ->assertSee('data-chat-read-url="'.$readUrl.'"', false);
    }

    public function test_messages_sidebar_is_active_inside_profile_thread_and_preview_contains_last_message(): void
    {
        $applicant = $this->account();
        $reviewer = $this->account(UserSystemRoleEnum::SUPERADMIN);
        $request = $this->change($applicant);
        $this->actingAs($reviewer)->post(route('account.notifications.threads.profile.claim', $request))
            ->assertRedirect();
        $this->post(route('account.notifications.threads.profile.messages', $request), [
            'body' => 'Latest content from reviewer',
        ])->assertRedirect();
        $this->actingAs($applicant)->get(route('account.notifications'))->assertOk()
            ->assertSee('Latest content from reviewer')->assertSee($reviewer->username);
        $this->get(route('account.notifications.threads.profile', $request))->assertOk()
            ->assertSee('data-chat-root', false)
            ->assertSee('data-chat-history', false)
            ->assertSee('app-account-layout--compact-sidebar', false)
            ->assertSee('data-conversation-rail', false)
            ->assertSee('data-chat-date=', false)
            ->assertSee('app-context-quick app-context-actions__trigger app-messages__actions-trigger', false)
            ->assertSee('app-context-quick__label', false)
            ->assertSee('app-messages__compose-footer', false)
            ->assertSee('data-chat-send', false)
            ->assertSee('aria-current="page"', false);
    }

    public function test_profile_rail_preview_refreshes_from_last_persisted_message_and_title_starts_with_section(): void
    {
        $applicant = $this->account();
        $reviewer = $this->account(UserSystemRoleEnum::SUPERADMIN);
        $request = $this->change($applicant);
        $this->actingAs($reviewer)->post(route('account.notifications.threads.profile.claim', $request))
            ->assertRedirect();
        $this->actingAs($applicant)->get(route('account.notifications.threads.profile', $request))
            ->assertOk()->assertSee('<title>Сообщения — Изменение данных №'.$request->id.' · MSKBA</title>', false);
        $this->postJson(route('account.notifications.threads.profile.messages', $request),
            ['body' => 'Первый текст в переписке'])->assertCreated();
        $url = route('account.notifications.rail').'?active=profile-'.$request->id;
        $previous = $this->getJson($url)->assertOk()->json('html');
        $this->assertStringContainsString('Вы: Первый текст в переписке', $previous);
        $this->postJson(route('account.notifications.threads.profile.messages', $request),
            ['body' => 'Новый текст для колонки сообщений'])->assertCreated();
        $next = $this->getJson($url)->assertOk()->json('html');
        $this->assertStringContainsString('Вы: Новый текст для колонки сообщений', $next);
        $this->assertStringNotContainsString('Вы: Первый текст в переписке', $next);
    }

    public function test_visibility_uses_exists_instead_of_join_multiplication(): void
    {
        $admin = $this->account(UserSystemRoleEnum::ADMIN);
        $sql = app(ProfileRequestThreadAccess::class)->visibleRequests($admin)->toSql();
        $this->assertStringContainsString('exists', strtolower($sql));
        $this->assertStringNotContainsString(' join ', strtolower($sql));
    }
}
