<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_notification_settings', function (Blueprint $table): void {
            $table->string('email_notifications', 32)->default('all')->after('messenger_notifications');
        });
    }

    public function down(): void
    {
        Schema::table('user_notification_settings', function (Blueprint $table): void {
            $table->dropColumn('email_notifications');
        });
    }
};
