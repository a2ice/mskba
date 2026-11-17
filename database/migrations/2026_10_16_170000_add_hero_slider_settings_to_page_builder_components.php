<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_builder_components', function (Blueprint $table): void {
            $table->json('draft_slides')->nullable();
            $table->json('published_slides')->nullable();
            $table->json('draft_options')->nullable();
            $table->json('published_options')->nullable();
        });

        // An editable draft only. Neither the component nor the home page is published.
        if (! DB::table('page_builder_components')->where('slug', 'hero-slider')->exists()) {
            $now = now();
            $highlights = [
                ['icon' => 'court', 'label' => 'Площадки', 'url' => '/venues'],
                ['icon' => 'trophy', 'label' => 'Турниры', 'url' => '/tournaments'],
                ['icon' => 'users', 'label' => 'Команды', 'url' => '/teams'],
                ['icon' => 'video', 'label' => 'Медиа', 'url' => '/news'],
                ['icon' => 'chart', 'label' => 'Рейтинги', 'url' => '/participants'],
            ];
            $slides = [
                [
                    'eyebrow' => 'БАСКЕТБОЛ ОБЪЕДИНЯЕТ',
                    'title' => "ИГРАЙ.\nРАЗВИВАЙСЯ.",
                    'accent_title' => 'БУДЬ В КОМЬЮНИТИ.',
                    'description' => 'Площадки, турниры, команды, игроки и медиа — всё о баскетболе в Москве на одной платформе.',
                    'cta_label' => 'О портале',
                    'cta_url' => '/about',
                    'background_desktop' => null,
                    'background_mobile' => null,
                    'far_player' => null,
                    'near_player' => null,
                    'animation_far' => 'slide-up',
                    'animation_near' => 'zoom',
                    'animation_copy' => 'slide-up',
                    'highlights' => $highlights,
                ],
                [
                    'eyebrow' => 'ВРЕМЕННЫЙ ДЕМО-СЛАЙД',
                    'title' => 'ТВОЯ ИГРА.',
                    'accent_title' => 'ТВОЯ ПЛОЩАДКА.',
                    'description' => 'Второй слайд предназначен для проверки переключения. Замени его перед публичной публикацией.',
                    'cta_label' => 'Найти площадку',
                    'cta_url' => '/venues',
                    'background_desktop' => null,
                    'background_mobile' => null,
                    'far_player' => null,
                    'near_player' => null,
                    'animation_far' => 'fade',
                    'animation_near' => 'slide-up',
                    'animation_copy' => 'fade',
                    'highlights' => [],
                ],
            ];
            DB::table('page_builder_components')->insert([
                'name' => 'Hero Slider MSKBA',
                'slug' => 'hero-slider',
                'description' => 'Системный многослойный слайдер с адаптивностью, анимациями и независимыми версиями черновика/публикации.',
                'draft_html' => '<div class="mskba-hero-placeholder"></div>',
                'draft_slides' => json_encode($slides, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'draft_options' => json_encode([
                    'autoplay' => false,
                    'interval_ms' => 7000,
                    'show_arrows' => true,
                    'show_dots' => true,
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Do not delete the component record or user media on rollback.
        Schema::table('page_builder_components', function (Blueprint $table): void {
            $table->dropColumn(['draft_slides', 'published_slides', 'draft_options', 'published_options']);
        });
    }
};
