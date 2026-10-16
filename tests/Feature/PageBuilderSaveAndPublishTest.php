<?php

namespace Tests\Feature;

use App\Filament\Backoffice\Resources\PageBuilder\Pages\EditBuilderComponent;
use App\Filament\Backoffice\Resources\PageBuilder\Pages\EditBuilderPage;
use App\Filament\Backoffice\Resources\PageBuilder\Pages\EditBuilderTemplate;
use App\Modules\Content\Domain\Models\PageBuilderComponent;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use App\Modules\Content\Domain\Models\PageBuilderRevision;
use App\Modules\Content\Domain\Models\PageBuilderTemplate;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class PageBuilderSaveAndPublishTest extends TestCase
{
    use RefreshDatabase;

    private function loginAsSuperadmin(): User
    {
        $user = User::factory()->create([
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        return $user;
    }

    public function test_combined_action_saves_current_unsaved_page_form_state_before_publishing(): void
    {
        $this->loginAsSuperadmin();
        $page = PageBuilderPage::query()->create([
            'name' => 'Старая страница',
            'path' => '/combined-old',
            'draft_html' => '<h1>Старый текст</h1>',
        ]);

        Livewire::test(EditBuilderPage::class, ['record' => $page->getRouteKey()])
            ->assertSee('Сохранить и опубликовать')
            ->fillForm([
                'name' => 'Новая страница',
                'path' => '/combined-new',
                'draft_html' => '<h1>Актуальная публикация</h1>',
                'draft_css' => '.intro { padding: 24px; }',
                'meta_title' => 'Обновлённый заголовок',
            ])
            ->call('saveAndPublish')
            ->assertHasNoFormErrors();

        $page->refresh();
        $this->assertSame('Новая страница', $page->published_name);
        $this->assertSame('/combined-new', $page->published_path);
        $this->assertSame('<h1>Актуальная публикация</h1>', $page->published_html);
        $this->assertSame('.intro { padding: 24px; }', $page->published_css);
        $this->assertSame('Обновлённый заголовок', $page->published_meta_title);
        $this->assertNotNull($page->published_at);
        $this->assertSame(1, PageBuilderRevision::query()->where('entity_type', 'page')
            ->where('entity_id', $page->getKey())->where('action', 'publish')->count());
    }

    public function test_combined_action_also_publishes_template_and_component_edits(): void
    {
        $this->loginAsSuperadmin();
        $template = PageBuilderTemplate::query()->create([
            'slug' => 'combined-template',
            'name' => 'Шаблон',
            'draft_html' => '<section>Старый шаблон [[page_content]]</section>',
        ]);
        $component = PageBuilderComponent::query()->create([
            'slug' => 'combined-component',
            'name' => 'Компонент',
            'draft_html' => '<section>Старый компонент</section>',
        ]);

        Livewire::test(EditBuilderTemplate::class, ['record' => $template->getRouteKey()])
            ->assertSee('Сохранить и опубликовать')
            ->fillForm(['draft_html' => '<section>Новый шаблон [[page_content]]</section>'])
            ->call('saveAndPublish')
            ->assertHasNoFormErrors();
        Livewire::test(EditBuilderComponent::class, ['record' => $component->getRouteKey()])
            ->assertSee('Сохранить и опубликовать')
            ->fillForm(['draft_html' => '<section>Новый компонент</section>'])
            ->call('saveAndPublish')
            ->assertHasNoFormErrors();

        $this->assertSame('<section>Новый шаблон [[page_content]]</section>',
            $template->refresh()->published_html);
        $this->assertSame('<section>Новый компонент</section>', $component->refresh()->published_html);
    }

    public function test_editor_with_edit_but_no_publish_rights_cannot_call_combined_method_directly(): void
    {
        $super = $this->loginAsSuperadmin();
        $editor = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::EDITOR,
        ]);
        app(BackofficePermissionManager::class)->sync($super, $editor, [
            Permission::BACKOFFICE_ACCESS->value,
            Permission::BACKOFFICE_BUILDER_VIEW->value,
            Permission::BACKOFFICE_BUILDER_EDIT->value,
        ]);
        $page = PageBuilderPage::query()->create([
            'name' => 'Не опубликована',
            'path' => '/not-published',
            'draft_html' => '<h1>Черновик</h1>',
        ]);

        $this->actingAs($editor);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        Livewire::test(EditBuilderPage::class, ['record' => $page->getRouteKey()])
            ->assertDontSee('Сохранить и опубликовать')
            ->call('saveAndPublish')
            ->assertForbidden();

        $this->assertNull($page->refresh()->published_at);
    }

    public function test_legacy_slider_slides_are_shown_as_enabled_when_field_is_missing(): void
    {
        $this->loginAsSuperadmin();
        $slider = PageBuilderComponent::query()->where('slug', 'hero-slider')->firstOrFail();
        $storedSlides = $slider->draft_slides;
        $this->assertArrayNotHasKey('enabled', $storedSlides[0]);

        $form = Livewire::test(EditBuilderComponent::class, ['record' => $slider->getRouteKey()])
            ->get('data');
        $formSlides = array_values($form['draft_slides']);

        $this->assertTrue($formSlides[0]['enabled']);
        $this->assertSame('legacy', $formSlides[0]['layout']);
        $this->assertArrayNotHasKey('enabled', $slider->refresh()->draft_slides[0]);
    }

    public function test_empty_universal_slide_cannot_be_published_via_combined_action(): void
    {
        $this->loginAsSuperadmin();
        $slider = PageBuilderComponent::query()->where('slug', 'hero-slider')->firstOrFail();
        $slides = $slider->draft_slides;
        $slides[] = [
            'layout' => 'layers',
            'name' => 'Новый универсальный слайд',
            'enabled' => true,
            'elements' => [],
        ];

        Livewire::test(EditBuilderComponent::class, ['record' => $slider->getRouteKey()])
            ->fillForm(['draft_slides' => $slides])
            ->call('saveAndPublish')
            ->assertHasErrors();

        $this->assertNull($slider->refresh()->published_at);
        $this->assertNull($slider->published_slides);
    }

    public function test_invalid_html_prevents_publication_of_unsaved_changes(): void
    {
        $this->loginAsSuperadmin();
        $page = PageBuilderPage::query()->create([
            'name' => 'Публикация',
            'path' => '/combined-validation',
            'draft_html' => '<h1>Исходная версия</h1>',
        ]);
        app(\App\Modules\Content\Application\Services\PageBuilderPublisher::class)->publish($page);

        Livewire::test(EditBuilderPage::class, ['record' => $page->getRouteKey()])
            ->fillForm(['draft_html' => '<script>alert(1)</script>'])
            ->call('saveAndPublish')
            ->assertHasErrors();

        $this->assertSame('<h1>Исходная версия</h1>', $page->refresh()->published_html);
    }
}
