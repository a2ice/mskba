<?php

namespace Tests\Feature\Venue;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VenueCatalogPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_category_catalog_uses_breadcrumbs_sidebar_accordion_and_icon_only_create_action(): void
    {
        $this
            ->get(route('venues'))
            ->assertOk()
            ->assertSee('class="page-breadcrumbs"', false)
            ->assertSee('data-default-category-sidebar-accordion', false)
            ->assertSee('aria-controls="venues-sidebar-navigation"', false)
            ->assertSee('aria-controls="venues-sidebar-filters"', false)
            ->assertSee('default-category-toolbar__action-button', false)
            ->assertSee('title="Добавить площадку"', false)
            ->assertDontSee('catalog-toolbar__button-text">Добавить', false);
    }
}
