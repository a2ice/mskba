<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 96)->unique();
            $table->string('type', 32)->default('rewardable');
            $table->foreignId('reward_id')->nullable()->unique()->constrained('rewards')->restrictOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('cta_label', 120)->nullable();
            $table->string('cta_url', 1000)->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['is_enabled', 'starts_at', 'ends_at']);
        });

        Schema::create('promotion_placements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->string('placement_code', 96);
            $table->string('title_override', 200)->nullable();
            $table->text('description_override')->nullable();
            $table->timestamps();
            $table->unique(['promotion_id', 'placement_code']);
        });

        Schema::create('venue_reward_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->constrained('venues')->cascadeOnDelete();
            $table->foreignId('reward_id')->constrained('rewards')->cascadeOnDelete();
            $table->string('mode', 24)->default('inherit');
            $table->bigInteger('amount_minor')->nullable();
            $table->timestamps();
            $table->unique(['venue_id', 'reward_id']);
        });

        $now = now();
        $defaults = [
            'referral_user_confirmed' => [
                'Пригласи друга — получи бонусы',
                'Приглашённый друг подтверждает аккаунт — после этого вам начисляются бонусы.',
                'Пригласить друга',
            ],
            'referral_second_level_user_confirmed' => [
                'Бонусы за приглашения друзей второго уровня',
                'Если приглашённый вами участник приглашает друга, после подтверждения нового аккаунта вы получаете бонусы.',
                'Пригласить друга',
            ],
            'venue_first_approval' => [
                'Добавь площадку — получи бонусы',
                'Добавьте новую площадку. Бонусы начисляются после её первой успешной модерации.',
                'Добавить площадку',
            ],
        ];

        foreach ($defaults as $rewardCode => [$title, $description, $cta]) {
            $rewardId = DB::table('rewards')->where('code', $rewardCode)->value('id');
            if ($rewardId === null) {
                continue;
            }

            $id = DB::table('promotions')->insertGetId([
                'code' => 'reward_'.$rewardCode,
                'type' => 'rewardable',
                'reward_id' => $rewardId,
                'title' => $title,
                'description' => $description,
                'cta_label' => $cta,
                'cta_url' => null,
                'is_enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach (['avatar_topup', 'account_wallet'] as $placement) {
                DB::table('promotion_placements')->insert([
                    'promotion_id' => $id,
                    'placement_code' => $placement,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_reward_overrides');
        Schema::dropIfExists('promotion_placements');
        Schema::dropIfExists('promotions');
    }
};
