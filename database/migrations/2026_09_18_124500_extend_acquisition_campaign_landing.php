<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acquisition_campaigns', function (Blueprint $table): void {
            $table->string('landing_type', 40)->default('onboarding')->after('channel');
            $table->unsignedBigInteger('landing_target_id')->nullable()->after('landing_type');
            $table->boolean('location_verification_enabled')->default(false)->after('verification_radius_m');
            $table->index(['landing_type', 'landing_target_id']);
        });

        DB::table('acquisition_campaigns')
            ->where('channel', 'qr')
            ->whereNotNull('venue_id')
            ->update(['location_verification_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('acquisition_campaigns', function (Blueprint $table): void {
            $table->dropIndex(['landing_type', 'landing_target_id']);
            $table->dropColumn(['landing_type', 'landing_target_id', 'location_verification_enabled']);
        });
    }
};
