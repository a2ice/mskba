<?php

namespace Tests\Unit;

use App\Modules\Venue\Application\DTO\VenueListItemDTO;
use PHPUnit\Framework\TestCase;

final class VenueCatalogDescriptionTest extends TestCase
{
    public function test_catalog_description_preserves_words_and_removes_trailing_punctuation(): void
    {
        self::assertSame('', VenueListItemDTO::catalogDescription(null));
        self::assertSame('Короткий текст.', VenueListItemDTO::catalogDescription('  Короткий   текст. '));
        $text = 'Школьная территория с двумя залами — уличным, крытым и дополнительным залом.';
        $result = VenueListItemDTO::catalogDescription($text);
        self::assertStringEndsWith('…', $result);
        $prefix = mb_substr($result, 0, -1);
        self::assertTrue(mb_strlen($prefix) >= 40 && mb_strlen($prefix) <= 58);
        self::assertStringStartsWith($prefix, $text);
        self::assertDoesNotMatchRegularExpression('/[\p{P}\s]$/u', $prefix);
        self::assertMatchesRegularExpression('/^[\p{P}\s]/u', mb_substr($text, mb_strlen($prefix)));
        self::assertSame(str_repeat('а', 60).'…', VenueListItemDTO::catalogDescription(str_repeat('а', 60).' слово'));
    }
}
