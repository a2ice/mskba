<?php
namespace Tests\Unit\Infrastructure;
use PHPUnit\Framework\TestCase;
final class DevMaintenanceFallbackTest extends TestCase
{
    public function test_html_is_independent_and_contains_no_stale_contacts(): void
    {
        $html = file_get_contents(dirname(__DIR__, 3).'/public/maintenance.html');
        $this->assertIsString($html);
        foreach (['<html lang="ru">', 'noindex, nofollow', 'Попробовать снова'] as $value) {
            $this->assertStringContainsString($value, $html);
        }
        foreach (['<script', '<link ', 'src="/', 'mailto:', 'tel:', 'https://t.me/', 'https://vk.ru/', 'https://vk.com/'] as $value) {
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
    public function test_dev_host_nginx_fallback_is_isolated(): void
    {
        $config = file_get_contents(dirname(__DIR__, 3).'/ops/nginx/mskba-dev-maintenance.inc');
        $this->assertIsString($config);
        foreach (['proxy_intercept_errors on;', 'error_page 502 503 504 =503', 'internal;', '/var/www/mskba-dev-next/public/maintenance.html', 'Retry-After 120'] as $value) {
            $this->assertStringContainsString($value, $config);
        }
    }
    public function test_production_fallback_is_opt_in_and_does_not_intercept_500(): void
    {
        $root = dirname(__DIR__, 3);
        $config = file_get_contents($root.'/ops/nginx/mskba-prod-maintenance.inc');
        $this->assertStringContainsString('error_page 502 503 504 =503', $config);
        $this->assertStringContainsString('/var/www/mskba/public/maintenance.html', $config);
        $this->assertStringNotContainsString('mskba-dev', $config);
        $this->assertStringContainsString('Retry-After 120', $config);
        $this->assertStringContainsString('Cache-Control', $config);
        $vhost = file_get_contents($root.'/ops/nginx/mskba-prod.conf');
        $this->assertStringContainsString('# include /etc/nginx/snippets/mskba-prod-maintenance.conf;', $vhost);
    }

    public function test_dynamic_and_offline_error_pages_share_visual_contract(): void
    {
        $root = dirname(__DIR__, 3);
        $static = file_get_contents($root.'/public/maintenance.html');
        $dynamic = file_get_contents($root.'/resources/views/errors/page.blade.php');
        $this->assertSame(1, preg_match('~<style>(.*?)</style>~s', $static, $a));
        $this->assertSame(1, preg_match('~<style>(.*?)</style>~s', $dynamic, $b));
        $this->assertSame($a[1], str_replace('@@media', '@media', $b[1]));
        $this->assertSame(1, preg_match('~<img src="data:image/png;base64,([A-Za-z0-9+/=]+)"~', $dynamic, $logo));
        $this->assertSame(file_get_contents($root.'/resources/themes/mskba_app/assets/images/logo-mark-100.png'), base64_decode($logo[1], true));
        $this->assertStringContainsString('HTTP {{ $status }}', $dynamic);
        $this->assertStringContainsString('->publicByChannel()', $dynamic);
        $this->assertStringContainsString('catch (\\Throwable)', $dynamic);
    }
}
