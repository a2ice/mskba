<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faq_sections', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 120);
            $table->unsignedInteger('sort_order')->default(100);
            $table->timestamps();
        });

        Schema::create('content_item_faq_section', function (Blueprint $table): void {
            $table->foreignId('content_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('faq_section_id')->constrained()->cascadeOnDelete();
            $table->primary(['content_item_id', 'faq_section_id']);
        });

        $sections = [
            'welcome' => 'Первые шаги',
            'venues' => 'Площадки',
            'events' => 'Мероприятия',
            'teams' => 'Команды',
            'tournaments' => 'Турниры',
            'sections' => 'Секции и тренировки',
            'coordination' => 'Координация',
            'account' => 'Аккаунт и профиль',
            'payments' => 'Оплата и бонусы',
            'other' => 'Другие вопросы',
        ];

        $order = 0;
        $timestamp = now();
        foreach ($sections as $code => $name) {
            DB::table('faq_sections')->insert([
                'code' => $code,
                'name' => $name,
                'sort_order' => $code === 'other' ? 1000 : ($order += 10),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('content_item_faq_section');
        Schema::dropIfExists('faq_sections');
    }
};
