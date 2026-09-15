<?php

namespace Tests\Feature\Faq;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FaqPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_faq_index_page_is_available(): void
    {
        $response = $this->get(route('faq.index'));

        $response->assertOk();
        $response->assertSee('FAQ');
        $response->assertSee('Первые шаги');
        $response->assertSee(route('faq.welcome'), false);

        foreach (config('creation-guides') as $topic => $guide) {
            $url = route('faq.creation', ['topic' => $topic]);
            $response->assertSee($url, false);

            $guideResponse = $this->get($url)
                ->assertOk()
                ->assertSee($guide['intro'])
                ->assertSee('Условия создания');

            foreach ($guide['requirements'] ?? [] as $requirement) {
                $guideResponse->assertSee($requirement['text']);

                foreach ($requirement['links'] ?? [] as $link) {
                    $faqUrl = route($link['route']).(! empty($link['anchor']) ? '#'.$link['anchor'] : '');
                    $guideResponse
                        ->assertSee($faqUrl, false)
                        ->assertSee('target="_blank"', false);
                }
            }
        }
        $this->get('/faq/creation/unknown')->assertNotFound();
    }

    public function test_welcome_faq_page_contains_creation_requirement_help_sections(): void
    {
        $response = $this->get(route('faq.welcome'));

        $response->assertOk();
        $response->assertSee('Первые шаги');
        $response->assertSee('id="contact-confirmation"', false);
        $response->assertSee('Как подтвердить контакт');
        $response->assertSee(route('account.contacts'), false);
        $response->assertSee('id="participation-role"', false);
        $response->assertSee('Как выбрать роль участия');
        $response->assertSee(route('account.roles'), false);
        $response->assertSee('id="account-confirmation"', false);
        $response->assertSee('Как подтвердить аккаунт');
        $response->assertSee(route('account.confirmation'), false);
        $response->assertSee('id="creation-permissions"', false);
        $response->assertSee('Как работают права на создание');
    }

    public function test_faq_link_is_visible_in_main_menu_more_group(): void
    {
        $response = $this->get(route('welcome'));

        $response->assertOk();
        $response->assertSee('Еще');
        $response->assertSee('FAQ');
        $response->assertSee(route('faq.index'), false);
    }
}
