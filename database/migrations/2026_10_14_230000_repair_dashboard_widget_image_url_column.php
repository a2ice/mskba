<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historical fix: image_url was added to the original widget CREATE migration
 * after that migration had already been applied on dev. Existing installations
 * must receive the column via a NEW migration. A new database already has it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('account_dashboard_widgets', 'image_url')) {
            Schema::table('account_dashboard_widgets', function (Blueprint $table): void {
                $table->string('image_url', 500)->nullable();
            });
        }
    }

    public function down(): void
    {
        // Deliberately no-op: on fresh installations image_url belongs to the
        // original table migration and must not be dropped by rolling back
        // this repair. Preserving existing advertisement data is paramount.
    }
};
