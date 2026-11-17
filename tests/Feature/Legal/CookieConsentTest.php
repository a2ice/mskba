<?php

namespace Tests\Feature\Legal;

use App\Modules\Identity\Domain\Models\User;
use App\Support\Privacy\CookieConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Tests\TestCase;

class CookieConsentTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitor_sees_cookie_notice_with_real_privacy_link(): void
    {
        $response = $this->get(route('welcome'));

        $response->assertOk()
            ->assertSee('data-cookie-consent-banner', false)
            ->assertSee('Только необходимые')
            ->assertSee('Разрешить аналитику')
            ->assertSee(route('privacy.policy'));
    }

    public function test_signed_in_decision_is_persisted_on_account_not_browser_cookie(): void
    {
        config(['services.yandex_metrika.id' => '12345678']);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('cookie-consent.store'), ['analytics' => 'accepted'])
            ->assertOk()->assertJsonPath('analytics', 'accepted');

        $this->assertDatabaseHas('user_cookie_consent_preferences', [
            'user_id' => $user->id,
            'analytics_choice' => 'accepted',
            'notice_version' => CookieConsent::version(),
        ]);
        $this->assertDatabaseHas('cookie_consent_events', [
            'user_id' => $user->id,
            'analytics_choice' => 'accepted',
            'notice_version' => CookieConsent::version(),
        ]);

        // Revisit from a fresh session: account preference is durable.
        Session::flush();
        $this->actingAs($user)->get(route('welcome'))
            ->assertSee('mc.yandex.ru/metrika/tag.js', false);
    }

    public function test_guest_choice_is_saved_only_for_current_session(): void
    {
        config(['services.yandex_metrika.id' => '12345678']);

        $this->postJson(route('cookie-consent.store'), ['analytics' => 'accepted'])
            ->assertOk()->assertJsonPath('analytics', 'accepted');
        $this->assertSame([
            'version' => CookieConsent::version(),
            'analytics' => 'accepted',
        ], Session::get(CookieConsent::SESSION_CHOICE_KEY));
        $this->get(route('welcome'))->assertSee('mc.yandex.ru/metrika/tag.js', false);
        $this->assertDatabaseCount('user_cookie_consent_preferences', 0);
        $this->assertDatabaseHas('cookie_consent_events', [
            'user_id' => null,
            'analytics_choice' => 'accepted',
        ]);

        // A new guest session cannot silently inherit a past analytics opt-in.
        Session::flush();
        $this->get(route('welcome'))
            ->assertDontSee('mc.yandex.ru/metrika/tag.js', false);
    }

    public function test_guest_choice_never_grants_analytics_consent_to_an_account(): void
    {
        config(['services.yandex_metrika.id' => '12345678']);
        $this->postJson(route('cookie-consent.store'), ['analytics' => 'accepted'])->assertOk();
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('welcome'))
            ->assertDontSee('mc.yandex.ru/metrika/tag.js', false);
        $this->assertDatabaseMissing('user_cookie_consent_preferences', [
            'user_id' => $user->id,
        ]);

        $this->postJson(route('cookie-consent.store'), ['analytics' => 'rejected'])->assertOk();
        $this->assertDatabaseHas('user_cookie_consent_preferences', [
            'user_id' => $user->id,
            'analytics_choice' => 'rejected',
        ]);
    }

    public function test_merged_alias_uses_the_canonical_account_consent(): void
    {
        config(['services.yandex_metrika.id' => '12345678']);
        $canonical = User::factory()->create();
        $alias = User::factory()->create();
        $alias->forceFill(['canonical_user_id' => $canonical->id])->save();

        $this->actingAs($alias)->postJson(route('cookie-consent.store'), ['analytics' => 'accepted'])
            ->assertOk();
        $this->assertDatabaseHas('user_cookie_consent_preferences', [
            'user_id' => $canonical->id,
            'analytics_choice' => 'accepted',
        ]);
        $this->actingAs($canonical)->get(route('welcome'))
            ->assertSee('mc.yandex.ru/metrika/tag.js', false);
    }

    public function test_preference_isolation_and_rejection_are_respected_across_sessions(): void
    {
        config(['services.yandex_metrika.id' => '12345678']);
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->actingAs($first)->postJson(route('cookie-consent.store'), ['analytics' => 'accepted'])
            ->assertOk();
        $this->actingAs($second)->get(route('welcome'))
            ->assertDontSee('mc.yandex.ru/metrika/tag.js', false);
        $this->actingAs($first)->get(route('welcome'))
            ->assertSee('mc.yandex.ru/metrika/tag.js', false);

        $this->postJson(route('cookie-consent.store'), ['analytics' => 'rejected'])
            ->assertOk()->assertJsonPath('analytics', 'rejected');
        Session::flush();
        $this->actingAs($first)->get(route('welcome'))
            ->assertDontSee('mc.yandex.ru/metrika/tag.js', false);
        $this->assertDatabaseHas('user_cookie_consent_preferences', [
            'user_id' => $first->id,
            'analytics_choice' => 'rejected',
        ]);
        $this->assertDatabaseCount('cookie_consent_events', 2);
    }

    public function test_updated_notice_version_requires_fresh_account_choice(): void
    {
        config(['services.yandex_metrika.id' => '12345678']);
        $user = User::factory()->create();
        $this->actingAs($user)->postJson(route('cookie-consent.store'), ['analytics' => 'accepted'])
            ->assertOk();

        config(['legal.cookie_consent_version' => '2027-01-01']);
        $this->get(route('welcome'))->assertDontSee('mc.yandex.ru/metrika/tag.js', false);
        $this->postJson(route('cookie-consent.store'), ['analytics' => 'accepted'])->assertOk();
        $this->get(route('welcome'))->assertSee('mc.yandex.ru/metrika/tag.js', false);
        $this->assertDatabaseHas('user_cookie_consent_preferences', [
            'user_id' => $user->id,
            'notice_version' => '2027-01-01',
        ]);
    }

    public function test_only_explicit_current_version_choice_enables_analytics(): void
    {
        config(['services.yandex_metrika.id' => '12345678']);
        $visitorId = (string) Str::uuid();

        $this->get(route('welcome'))
            ->assertDontSee('mc.yandex.ru/metrika/tag.js', false)
            ->assertDontSee('mc.yandex.ru/watch/', false);

        // A legacy year-long browser cookie must not be treated as fresh
        // guest or user consent after the storage-policy change.
        $this->withCookie(CookieConsent::COOKIE_NAME, CookieConsent::version().'|accepted|'.$visitorId)
            ->get(route('welcome'))
            ->assertDontSee('mc.yandex.ru/metrika/tag.js', false);

        $this->withSession([
            CookieConsent::SESSION_CHOICE_KEY => [
                'version' => 'obsolete',
                'analytics' => 'accepted',
            ],
        ])->get(route('welcome'))
            ->assertDontSee('mc.yandex.ru/metrika/tag.js', false);

        $this->withSession([
            CookieConsent::SESSION_CHOICE_KEY => [
                'version' => CookieConsent::version(),
                'analytics' => 'rejected',
            ],
        ])->get(route('welcome'))
            ->assertDontSee('mc.yandex.ru/metrika/tag.js', false);

        $this->withSession([
            CookieConsent::SESSION_CHOICE_KEY => [
                'version' => CookieConsent::version(),
                'analytics' => 'accepted',
            ],
        ])->get(route('welcome'))
            ->assertSee('mc.yandex.ru/metrika/tag.js', false);
    }

    public function test_invalid_choice_is_rejected_without_audit_or_cookie(): void
    {
        $this->postJson(route('cookie-consent.store'), ['analytics' => 'unsupported'])
            ->assertUnprocessable()
            ->assertCookieMissing(CookieConsent::COOKIE_NAME);

        $this->assertSame(0, DB::table('cookie_consent_events')->count());
        $this->assertDatabaseCount('user_cookie_consent_preferences', 0);
    }
}
