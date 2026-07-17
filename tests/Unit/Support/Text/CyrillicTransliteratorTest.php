<?php

namespace Tests\Unit\Support\Text;

use App\Support\Text\CyrillicTransliterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CyrillicTransliteratorTest extends TestCase
{
    #[DataProvider('russianWords')]
    public function test_it_transliterates_russian_text(string $source, string $expected): void
    {
        $this->assertSame($expected, (new CyrillicTransliterator)->transliterate($source));
    }

    /** @return array<string, array{string, string}> */
    public static function russianWords(): array
    {
        return [
            'sh' => ['Школа', 'shkola'],
            'ch' => ['Чайка', 'chayka'],
            'shch' => ['Щукино', 'shchukino'],
            'zh' => ['Жуковка', 'zhukovka'],
            'yu and ya' => ['Южная', 'yuzhnaya'],
            'ya' => ['Ясенево', 'yasenevo'],
            'y and sh' => ['Йошкар-Ола', 'yoshkar-ola'],
            'kh, y and ts' => ['Хоккейный центр', 'khokkeynyy tsentr'],
        ];
    }
}
