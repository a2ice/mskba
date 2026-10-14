<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_dashboard_widgets', function (Blueprint $table): void {
            $table->json('actions')->nullable();
        });

        Schema::create('dashboard_button_styles', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('class_name', 50)->unique();
            $table->text('css_code');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        foreach (DB::table('account_dashboard_widgets')->get() as $widget) {
            $actions = match ($widget->kind) {
                'account_status' => [[
                    'label' => 'Подтвердить аккаунт', 'url' => '/account/confirmation',
                    'appearance' => 'secondary', 'classes' => [], 'visibility' => 'unconfirmed',
                ]],
                'getting_started' => [[
                    'label' => 'Мои роли', 'url' => '/account/roles',
                    'appearance' => 'secondary', 'classes' => [], 'visibility' => 'always',
                ]],
                'message_summary' => [[
                    'label' => 'Открыть сообщения', 'url' => '/account/notifications',
                    'appearance' => 'secondary', 'classes' => [], 'visibility' => 'always',
                ]],
                'advertisement', 'information' => filled($widget->link_url) ? [[
                    'label' => $widget->link_label ?: 'Подробнее',
                    'url' => $widget->link_url,
                    'appearance' => $widget->kind === 'advertisement' ? 'primary' : 'secondary',
                    'classes' => [], 'visibility' => 'always',
                ]] : [],
                default => [],
            };
            DB::table('account_dashboard_widgets')->where('id', $widget->id)
                ->update(['actions' => json_encode($actions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_button_styles');
        Schema::table('account_dashboard_widgets', fn (Blueprint $table) => $table->dropColumn('actions'));
    }
};
