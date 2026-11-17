<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_commission_policies', static function (Blueprint $table): void {
            $table->string('entity_type', 32)->default('venue');
            $table->string('action_key', 32)->default('rental');
            $table->unique(['entity_type', 'action_key'], 'portal_commission_scope_unique');
        });
        Schema::table('venue_commission_policies', static function (Blueprint $table): void {
            $table->boolean('disable_all_commissions')->default(false);
        });
        Schema::table('venue_court_commission_policies', static function (Blueprint $table): void {
            $table->boolean('disable_all_commissions_override')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('venue_court_commission_policies', static function (Blueprint $table): void {
            $table->dropColumn('disable_all_commissions_override');
        });
        Schema::table('venue_commission_policies', static function (Blueprint $table): void {
            $table->dropColumn('disable_all_commissions');
        });
        Schema::table('portal_commission_policies', static function (Blueprint $table): void {
            $table->dropUnique('portal_commission_scope_unique');
            $table->dropColumn(['entity_type', 'action_key']);
        });
    }
};
