<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_dashboard_widgets', function (Blueprint $table): void {
            // NULL inherits the unchanged MSKBA design-system corner glow.
            $table->string('gradient_color', 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('account_dashboard_widgets', function (Blueprint $table): void {
            $table->dropColumn('gradient_color');
        });
    }
};
