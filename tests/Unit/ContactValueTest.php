<?php

namespace Tests\Unit;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\ValueObjects\ContactValue;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ContactValueTest extends TestCase
{
    public function test_it_normalizes_supported_contact_values(): void
    {
        $this->assertSame(
            'contact@example.test',
            (new ContactValue(ContactTypeEnum::EMAIL, ' contact@example.test '))->value(),
        );

        $this->assertSame(
            '+79990000000',
            (new ContactValue(ContactTypeEnum::PHONE, '+7 (999) 000-00-00'))->value(),
        );

        $this->assertSame(
            '@mskba_team',
            (new ContactValue(ContactTypeEnum::TELEGRAM, 'https://t.me/mskba_team'))->value(),
        );

        $this->assertSame(
            'mskba.club',
            (new ContactValue(ContactTypeEnum::VK, 'https://vk.com/mskba.club'))->value(),
        );

        $this->assertSame(
            'Связь через администратора',
            (new ContactValue(ContactTypeEnum::OTHER, ' Связь через администратора '))->value(),
        );
    }

    public function test_it_rejects_invalid_contact_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ContactValue(ContactTypeEnum::EMAIL, 'not-email');
    }
}
