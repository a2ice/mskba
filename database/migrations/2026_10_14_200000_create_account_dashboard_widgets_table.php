<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('account_dashboard_widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 32);
            $table->string('title', 150);
            $table->text('body')->nullable();
            $table->string('link_url', 500)->nullable();
            $table->string('link_label', 100)->nullable();
            $table->longText('html_code')->nullable();
            $table->longText('css_code')->nullable();
            $table->longText('js_code')->nullable();
            $table->unsignedSmallInteger('iframe_height')->default(260);
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->string('size', 16)->default('half');
            $table->boolean('enabled')->default(true);
            $table->boolean('can_hide')->default(true);
            $table->string('contact_filter', 20)->default('any');
            $table->json('account_statuses')->nullable();
            $table->json('system_roles')->nullable();
            $table->json('participation_roles')->nullable();
            $table->json('include_user_ids')->nullable();
            $table->json('exclude_user_ids')->nullable();
            $table->timestamps();
            $table->index(['enabled', 'sort_order']);
        });
        $now = now();
        foreach ([
            ['account_status', 'Статус пользователя', 10, false],
            ['getting_started', 'Возможности MSKBA', 20, true],
            ['message_summary', 'Мои сообщения', 30, true],
        ] as [$kind, $title, $order, $canHide]) {
            DB::table('account_dashboard_widgets')->insert([
                'kind' => $kind, 'title' => $title, 'sort_order' => $order,
                'size' => 'half', 'enabled' => true, 'can_hide' => $canHide,
                'contact_filter' => 'any', 'iframe_height' => 260,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
    public function down(): void { Schema::dropIfExists('account_dashboard_widgets'); }
};
