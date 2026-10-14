<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_dashboard_widgets', function (Blueprint $table): void {
            $table->longText('status_confirmed_html')->nullable();
            $table->longText('status_unconfirmed_html')->nullable();
            $table->longText('status_blocked_html')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('account_dashboard_widgets', function (Blueprint $table): void {
            $table->dropColumn(['status_confirmed_html', 'status_unconfirmed_html', 'status_blocked_html']);
        });
    }
};
