<?php

use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table): void {
            $table->boolean('requires_payment')->nullable()->default(null)->change();
        });

        DB::table('venues')
            ->where('type', '!=', VenueTypeEnum::STREET_COURT->value)
            ->where('requires_payment', false)
            ->update(['requires_payment' => null]);
    }

    public function down(): void
    {
        DB::table('venues')->whereNull('requires_payment')->update(['requires_payment' => false]);

        Schema::table('venues', function (Blueprint $table): void {
            $table->boolean('requires_payment')->nullable(false)->default(false)->change();
        });
    }
};
