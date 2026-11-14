<?php

namespace Tests\Feature\Legal;

use App\Support\Privacy\CookieConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_opt_in_is_persisted_as_server_cookie_and_audited(): void
    {
        $response = $this->post(route('cookie-consent.store'), ['analytics' => 'accepted']);

        $response->assertRedirect()
            ->assertCookie(CookieConsent::COOKIE_NAME);

        $this->assertDatabaseHas('cookie_consent_events', [
            'analytics_choice' => 'accepted',
            'notice_version' => CookieConsent::version(),
        ]);
    }

    public function test_rejection_is_persisted_and_does_not_block_site(): void
    {
        $response = $this->postJson(route('cookie-consent.store'), ['analytics' => 'rejected']);

        $response->assertOk()->assertJsonPath('analytics', 'rejected')
            ->assertCookie(CookieConsent::COOKIE_NAME);

        $this->assertDatabaseHas('cookie_consent_events', ['analytics_choice' => 'rejected']);
        $this->get(route('welcome'))->assertOk();
    }

    public function test_only_explicit_current_version_acceptance_enables_analytics(): void
    {
        config(['services.yandex_metrika.id' => '12345678']);
        $visitorId = (string) Str::uuid();

        $this->get(route('welcome'))
            ->assertDontSee('mc.yandex.ru/metrika/tag.js', false)
            ->assertDontSee('mc.yandex.ru/watch/', false);

        $this->withCookie(CookieConsent::COOKIE_NAME, CookieConsent::version().'|rejected|'.$visitorId)
            ->get(route('welcome'))
            ->assertDontSee('mc.yandex.ru/metrika/tag.js', false);

        $this->withCookie(CookieConsent::COOKIE_NAME, 'obsolete|accepted|'.$visitorId)
            ->get(route('welcome'))
            ->assertDontSee('mc.yandex.ru/metrika/tag.js', false);

        $this->withCookie(CookieConsent::COOKIE_NAME, CookieConsent::version().'|accepted|'.$visitorId)
            ->get(route('welcome'))
            ->assertSee('mc.yandex.ru/metrika/tag.js', false);
    }

    public function test_invalid_choice_is_rejected_without_audit_or_cookie(): void
    {
        $this->postJson(route('cookie-consent.store'), ['analytics' => 'unsupported'])
            ->assertUnprocessable()
            ->assertCookieMissing(CookieConsent::COOKIE_NAME);

        $this->assertSame(0, DB::table('cookie_consent_events')->count());
    }
}
