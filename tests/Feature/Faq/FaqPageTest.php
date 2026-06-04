<?php

namespace Tests\Feature\Faq;

use Tests\TestCase;

class FaqPageTest extends TestCase
{
    public function test_faq_index_page_is_available(): void
    {
        $response = $this->get(route('faq.index'));

        $response->assertOk();
        $response->assertSee('FAQ');
        $response->assertSee('Первые шаги');
        $response->assertSee(route('faq.welcome'), false);
    }

    public function test_welcome_faq_page_is_available(): void
    {
        $response = $this->get(route('faq.welcome'));

        $response->assertOk();
        $response->assertSee('Первые шаги');
        $response->assertSee('Подтвердите контакт');
        $response->assertSee(route('account.contacts'), false);
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
