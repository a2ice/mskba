<?php

namespace Tests\Unit\Dashboard;

use App\Modules\Dashboard\Application\Rules\DashboardWidgetLinkRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DashboardWidgetLinkRuleTest extends TestCase
{
    public static function validUrls(): iterable
    {
        yield 'portal link' => ['/partners'];
        yield 'root' => ['/'];
        yield 'internal query and fragment' => ['/account/roles?tab=player#roles'];
        yield 'external HTTPS' => ['https://a2ice.ru/partners'];
        yield 'external HTTP' => ['http://mskba.ru/news'];
    }

    #[DataProvider('validUrls')]
    public function test_valid_internal_or_absolute_links(string $url): void
    {
        self::assertTrue(DashboardWidgetLinkRule::isValid($url));
    }

    public static function invalidUrls(): iterable
    {
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'data URI' => ['data:text/html,hi'];
        yield 'protocol-relative external' => ['//evil.example/x'];
        yield 'backslash authority' => ['/\\evil.example/x'];
        yield 'embedded backslash' => ['/partners\\else'];
        yield 'leading whitespace' => [' /partners'];
        yield 'newline control' => ["/partners\nx"];
        yield 'missing scheme' => ['partners'];
        yield 'FTP' => ['ftp://example.org/file'];
        yield 'userinfo URL' => ['https://user:password@example.com'];
        yield 'no host' => ['https://'];
    }

    #[DataProvider('invalidUrls')]
    public function test_rejects_unsafe_and_ambiguous_links(string $url): void
    {
        self::assertFalse(DashboardWidgetLinkRule::isValid($url));
    }
}
