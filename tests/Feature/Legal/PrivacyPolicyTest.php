<?php

namespace Tests\Feature\Legal;

use Tests\TestCase;

class PrivacyPolicyTest extends TestCase
{
    public function test_privacy_policy_is_publicly_available(): void
    {
        $this->get(route('privacy.policy'))
            ->assertOk()
            ->assertSee('Политика обработки персональных данных')
            ->assertSee(config('legal.operator_name'))
            ->assertSee(config('legal.privacy_email'));
    }

    public function test_public_page_contains_privacy_link_and_portal_footer(): void
    {
        $this->get(route('welcome'))
            ->assertOk()
            ->assertSee(route('privacy.policy'))
            ->assertSee('Материалы портала носят информационный характер');
    }
}
