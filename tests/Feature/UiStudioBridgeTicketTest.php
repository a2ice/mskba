<?php

namespace Tests\Feature;

use App\Presentation\UiStudio\UiStudioBridgeTicket;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class UiStudioBridgeTicketTest extends TestCase
{
    public function test_one_time_ticket_has_short_lifetime_and_is_bound_to_allowed_admin_origin(): void
    {
        config()->set([
            'ui-studio.bridge_secret' => str_repeat('b', 48),
            'ui-studio.parent_origins' => ['https://mskba.ru'],
        ]);

        $tickets = app(UiStudioBridgeTicket::class);
        $ticket = $tickets->issue(123, 'https://mskba.ru');
        $data = $tickets->consume($ticket);

        $this->assertSame(123, $data['sub']);
        $this->assertSame('https://mskba.ru', $data['origin']);
        $this->assertLessThanOrEqual(60, $data['exp'] - $data['iat']);

        try {
            $tickets->consume($ticket);
            $this->fail('A redeemed ticket must not be usable twice.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_modified_ticket_and_untrusted_origin_are_rejected(): void
    {
        config()->set([
            'ui-studio.bridge_secret' => str_repeat('b', 48),
            'ui-studio.parent_origins' => ['https://mskba.ru'],
        ]);

        $tickets = app(UiStudioBridgeTicket::class);
        $this->expectException(HttpException::class);
        $tickets->consume($tickets->issue(12, 'https://mskba.ru').'corrupt');
    }

    public function test_untrusted_issuer_origin_is_rejected(): void
    {
        config()->set([
            'ui-studio.bridge_secret' => str_repeat('b', 48),
            'ui-studio.parent_origins' => ['https://mskba.ru'],
        ]);

        $this->expectException(HttpException::class);
        app(UiStudioBridgeTicket::class)->issue(123, 'https://evil.example');
    }
}
