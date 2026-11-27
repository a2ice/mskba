<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->boolean('is_rental_contact')->default(false);
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('CREATE UNIQUE INDEX contacts_one_rental_destination ON contacts (contactable_type, contactable_id) WHERE is_rental_contact AND deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS contacts_one_rental_destination');
        }

        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropColumn('is_rental_contact');
        });
    }
};
