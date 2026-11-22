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
        $response = $this->get(route('homepage.original'));

        $response->assertOk()
            ->assertSee('data-cookie-consent-banner', false)
            ->assertSee('Только необходимые')
            ->assertSee('Принять все')
            ->assertDontSee('Разрешить аналитику')
            ->assertSee(route('privacy.policy'));
    }

    public function test_signed_in_decision_is_persisted_on_account_not_browser_cookie(): void
    {
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
        $this->actingAs($user)->get(route('homepage.original'))
            ->assertCookieNotExpired('mskba_browser_fp');
    }

    public function test_guest_choice_survives_new_session_in_same_browser(): void
    {

        $response = $this->postJson(route('cookie-consent.store'), ['analytics' => 'accepted'])
            ->assertOk()->assertJsonPath('analytics', 'accepted')
            ->assertCookie(CookieConsent::COOKIE_NAME);

        $cookie = $response->getCookie(CookieConsent::COOKIE_NAME);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertNull($cookie->getDomain());
        $this->assertSame('/', $cookie->getPath());
        $this->assertEqualsWithDelta(now()->addDays(365)->timestamp, $cookie->getExpiresTime(), 5);

        Session::flush();
        $page = $this->withCookie(CookieConsent::COOKIE_NAME, $cookie->getValue())
            ->get(route('homepage.original'))
            ->assertCookieNotExpired('mskba_browser_fp');
        $this->assertMatchesRegularExpression('/<section\\b[^>]*data-cookie-consent-banner[^>]*\\bhidden\\b[^>]*>/s', $page->getContent());

        $this->assertDatabaseCount('user_cookie_consent_preferences', 0);
        $this->assertDatabaseHas('cookie_consent_events', [
            'user_id' => null,
            'analytics_choice' => 'accepted',
        ]);

        // A different browser with no persistent cookie must consent separately.
        $this->assertDatabaseCount('user_fingerprints', 1);
        $this->withCookie(CookieConsent::COOKIE_NAME, '')
            ->get(route('homepage.original'))
            ->assertCookieMissing('mskba_browser_fp');
    }

    public function test_guest_rejection_and_revocation_survive_new_session(): void
    {
        $this->withCookie(CookieConsent::COOKIE_NAME, CookieConsent::guestCookie('accepted')->getValue());
        $response = $this->postJson(route('cookie-consent.store'), ['analytics' => 'rejected'])
            ->assertOk()->assertCookie(CookieConsent::COOKIE_NAME);

        Session::flush();
        $page = $this->withCookie(CookieConsent::COOKIE_NAME, $response->getCookie(CookieConsent::COOKIE_NAME)->getValue())
            ->get(route('homepage.original'))
            ->assertCookieMissing('mskba_browser_fp');
        $this->assertMatchesRegularExpression('/<section\\b[^>]*data-cookie-consent-banner[^>]*\\bhidden\\b[^>]*>/s', $page->getContent());
    }

    public function test_guest_expiry_and_notice_version_require_new_choice(): void
    {
        $cookie = CookieConsent::guestCookie('accepted')->getValue();

        $this->withCookie(CookieConsent::COOKIE_NAME, CookieConsent::version().'|accepted|'.now()->subSecond()->timestamp)
            ->get(route('homepage.original'))
            ->assertCookieMissing('mskba_browser_fp');

        config(['legal.cookie_consent_version' => '2027-01-01']);
        $this->withCookie(CookieConsent::COOKIE_NAME, $cookie)
            ->get(route('homepage.original'))
            ->assertCookieMissing('mskba_browser_fp');
    }

    public function test_guest_choice_never_grants_analytics_consent_to_an_account(): void
    {
        $guest = $this->postJson(route('cookie-consent.store'), ['analytics' => 'accepted'])->assertOk();
        $user = User::factory()->create();

        $this->withCookie(CookieConsent::COOKIE_NAME, $guest->getCookie(CookieConsent::COOKIE_NAME)->getValue())
            ->actingAs($user)->get(route('homepage.original'))
            ->assertCookieMissing('mskba_browser_fp');
        $this->assertDatabaseMissing('user_cookie_consent_preferences', [
            'user_id' => $user->id,
        ]);

        $this->postJson(route('cookie-consent.store'), ['analytics' => 'rejected'])
            ->assertOk()->assertCookieMissing(CookieConsent::COOKIE_NAME);
        $this->assertDatabaseHas('user_cookie_consent_preferences', [
            'user_id' => $user->id,
            'analytics_choice' => 'rejected',
        ]);
    }

    public function test_merged_alias_uses_the_canonical_account_consent(): void
    {
        $canonical = User::factory()->create();
        $alias = User::factory()->create();
        $alias->forceFill(['canonical_user_id' => $canonical->id])->save();

        $this->actingAs($alias)->postJson(route('cookie-consent.store'), ['analytics' => 'accepted'])
            ->assertOk();
        $this->assertDatabaseHas('user_cookie_consent_preferences', [
            'user_id' => $canonical->id,
            'analytics_choice' => 'accepted',
        ]);
        $this->actingAs($canonical)->get(route('homepage.original'))
            ->assertCookieNotExpired('mskba_browser_fp');
    }

    public function test_preference_isolation_and_rejection_are_respected_across_sessions(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->actingAs($first)->postJson(route('cookie-consent.store'), ['analytics' => 'accepted'])
            ->assertOk();
        $this->actingAs($second)->get(route('homepage.original'))
            ->assertCookieMissing('mskba_browser_fp');
        $this->actingAs($first)->get(route('homepage.original'))
            ->assertCookieNotExpired('mskba_browser_fp');

        $this->postJson(route('cookie-consent.store'), ['analytics' => 'rejected'])
            ->assertOk()->assertJsonPath('analytics', 'rejected');
        Session::flush();
        $this->actingAs($first)->get(route('homepage.original'))
            ->assertCookieMissing('mskba_browser_fp');
        $this->assertDatabaseHas('user_cookie_consent_preferences', [
            'user_id' => $first->id,
            'analytics_choice' => 'rejected',
        ]);
        $this->assertDatabaseCount('cookie_consent_events', 2);
    }

    public function test_updated_notice_version_requires_fresh_account_choice(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson(route('cookie-consent.store'), ['analytics' => 'accepted'])
            ->assertOk();

        config(['legal.cookie_consent_version' => '2027-01-01']);
        $this->get(route('homepage.original'))->assertCookieMissing('mskba_browser_fp');
        $this->postJson(route('cookie-consent.store'), ['analytics' => 'accepted'])->assertOk();
        $this->get(route('homepage.original'))->assertCookieNotExpired('mskba_browser_fp');
        $this->assertDatabaseHas('user_cookie_consent_preferences', [
            'user_id' => $user->id,
            'notice_version' => '2027-01-01',
        ]);
    }

    public function test_only_explicit_current_version_choice_enables_internal_analytics(): void
    {
        $visitorId = (string) Str::uuid();

        $this->get(route('homepage.original'))
            ->assertCookieMissing('mskba_browser_fp');

        // A legacy year-long browser cookie must not be treated as fresh
        // guest or user consent after the storage-policy change.
        $this->withCookie(CookieConsent::LEGACY_COOKIE_NAME, CookieConsent::version().'|accepted|'.$visitorId)
            ->get(route('homepage.original'))
            ->assertCookieMissing('mskba_browser_fp');

        $this->withCookie(CookieConsent::COOKIE_NAME, 'obsolete|accepted|'.now()->addDay()->timestamp)->get(route('homepage.original'))
            ->assertCookieMissing('mskba_browser_fp');

        $this->withCookie(CookieConsent::COOKIE_NAME, CookieConsent::guestCookie('rejected')->getValue())->get(route('homepage.original'))
            ->assertCookieMissing('mskba_browser_fp');

        $this->withCookie(CookieConsent::COOKIE_NAME, CookieConsent::guestCookie('accepted')->getValue())->get(route('homepage.original'))
            ->assertCookieNotExpired('mskba_browser_fp');
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
