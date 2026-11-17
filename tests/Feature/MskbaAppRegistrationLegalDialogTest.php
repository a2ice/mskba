<?php

namespace Tests\Feature;

use App\Presentation\Theming\ThemeResolver;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class MskbaAppRegistrationLegalDialogTest extends TestCase
{
    private string $previousTheme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTheme = config('themes.active');
    }

    protected function tearDown(): void
    {
        $this->useTheme($this->previousTheme);
        parent::tearDown();
    }

    private function useTheme(string $theme): void
    {
        config()->set('themes.active', $theme);
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/'.$theme.'/views'));
    }

    private function documentArticle(string $html): string
    {
        $this->assertSame(1, preg_match('~<article class="legal-document">.*?</article>~s', $html, $matches));

        return trim($matches[0]);
    }

    public function test_both_public_documents_and_fragments_share_exactly_one_legal_text_source(): void
    {
        foreach ([
            [route('personal-data.consent'), route('legal.fragment.consent'), 'Согласие на обработку персональных данных'],
            [route('privacy.policy'), route('legal.fragment.privacy'), 'Политика обработки персональных данных'],
        ] as [$pageUrl, $fragmentUrl, $title]) {
            $fragment = $this->get($fragmentUrl)
                ->assertOk()
                ->assertSee($title)
                ->assertSee(config('legal.operator_name'))
                ->assertSee(config('legal.privacy_email'))
                ->getContent();

            foreach (['mskba_app', 'mskba_dark'] as $theme) {
                $this->useTheme($theme);
                $fullPage = $this->get($pageUrl)->assertOk()
                    ->assertSee($title)
                    ->assertDontSee('Новая тема находится в разработке.')
                    ->getContent();

                $this->assertSame($this->documentArticle($fragment), $this->documentArticle($fullPage));
            }
        }
    }

    public function test_new_theme_registration_exports_only_local_legal_document_urls(): void
    {
        $this->useTheme('mskba_app');

        $html = $this->get(route('register'))->assertOk()->getContent();
        $this->assertSame(1, preg_match("~data-options='([^']+)'~", $html, $match));

        $options = json_decode(html_entity_decode($match[1], ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(route('legal.fragment.consent', [], false), $options['legalFragments']['consent']);
        $this->assertSame(route('legal.fragment.privacy', [], false), $options['legalFragments']['privacy']);
        $this->assertSame(route('privacy.policy', [], false), $options['privacyPolicy']);
        $this->assertSame(route('personal-data.consent', [], false), $options['consent']);
    }
}
