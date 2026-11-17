<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_dashboard_widgets', function (Blueprint $table): void {
            // Existing widgets remain movable until an editor locks them.
            $table->boolean('can_move')->default(true)->after('can_hide');
        });
    }

    public function down(): void
    {
        Schema::table('account_dashboard_widgets', function (Blueprint $table): void {
            $table->dropColumn('can_move');
        });
    }
};
