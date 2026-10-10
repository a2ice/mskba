<?php
namespace Tests\Unit\Infrastructure;
use PHPUnit\Framework\TestCase;
final class DevMaintenanceFallbackTest extends TestCase
{
    public function test_html_is_independent_and_contains_official_contacts(): void
    {
        $html = file_get_contents(dirname(__DIR__, 3).'/public/maintenance.html');
        $this->assertIsString($html);
        foreach (['<html lang="ru">', 'mailto:support@mskba.ru', 'https://t.me/mskbaofficial', 'https://vk.ru/mskba_official', 'noindex, nofollow', 'Попробовать снова'] as $value) {
            $this->assertStringContainsString($value, $html);
        }
        foreach (['<script', '<link ', 'src="/'] as $value) {
            $this->assertStringNotContainsString($value, $html);
        }
        // The logo is the exact image used in the mskba_app header, not an
        // approximation and not a URL that would require Docker to be alive.
        $this->assertSame(1, preg_match('~<img src="data:image/png;base64,([A-Za-z0-9+/=]+)"~', $html, $matches));
        $this->assertSame(
            file_get_contents(dirname(__DIR__, 3).'/resources/themes/mskba_app/assets/images/logo-mark-100.png'),
            base64_decode($matches[1], true),
        );

    }
    public function test_only_dev_host_nginx_fallback_is_defined(): void
    {
        $config = file_get_contents(dirname(__DIR__, 3).'/ops/nginx/mskba-dev-maintenance.inc');
        $this->assertIsString($config);
        foreach (['proxy_intercept_errors on;', 'error_page 502 503 504 =503', 'internal;', '/var/www/mskba-dev-next/public/maintenance.html', 'Retry-After 120'] as $value) {
            $this->assertStringContainsString($value, $config);
        }
    }
}
