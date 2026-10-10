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
