<?php

namespace Tests\Feature\Legal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonalDataConsentTest extends TestCase
{
    use RefreshDatabase;

    public function test_personal_data_consent_is_publicly_available(): void
    {
        $this->get(route('personal-data.consent'))
            ->assertOk()
            ->assertSee('Согласие на обработку персональных данных')
            ->assertSee(config('legal.operator_name'))
            ->assertSee(config('legal.personal_data_consent_version'))
            ->assertSee(config('legal.privacy_email'))
            ->assertSee('не разрешает распространение');
    }

    public function test_registration_shows_standalone_consent_separately_from_privacy_policy(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertSee(route('personal-data.consent'))
            ->assertSee(route('privacy.policy'))
            ->assertSee('согласие на обработку персональных данных')
            ->assertSee('не является согласием')
            ->assertDontSee('согласие на обработку персональных данных и принимаю условия', false);
    }
}
