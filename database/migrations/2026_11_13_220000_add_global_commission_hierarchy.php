<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_commission_policies', static function (Blueprint $table): void {
            $table->string('mode', 16)->nullable()->default(null)->change();
            $table->unsignedBigInteger('value')->nullable()->default(null)->change();
        });

        // The former "global" setting was stored under venue/rental. Preserve
        // the actual rate and its version when introducing a true site-wide rate.
        $legacy = DB::table('portal_commission_policies')
            ->where('entity_type', 'venue')->where('action_key', 'rental')->first();
        $globalExists = DB::table('portal_commission_policies')
            ->where('entity_type', 'global')->where('action_key', 'default')->exists();

        if ($legacy !== null && ! $globalExists) {
            DB::table('portal_commission_policies')->insert([
                'entity_type' => 'global',
                'action_key' => 'default',
                'mode' => $legacy->mode ?? 'none',
                'value' => $legacy->value ?? 0,
                'currency' => $legacy->currency,
                'version' => $legacy->version,
                'updated_by_user_id' => $legacy->updated_by_user_id,
                'created_at' => $legacy->created_at,
                'updated_at' => $legacy->updated_at,
            ]);
            DB::table('portal_commission_policies')->where('id', $legacy->id)
                ->update(['mode' => null, 'value' => null, 'version' => $legacy->version + 1]);
        }
    }

    public function down(): void
    {
        $global = DB::table('portal_commission_policies')
            ->where('entity_type', 'global')->where('action_key', 'default')->first();
        if ($global !== null) {
            DB::table('portal_commission_policies')
                ->where('entity_type', 'venue')->where('action_key', 'rental')
                ->whereNull('mode')
                ->update(['mode' => $global->mode ?? 'none', 'value' => $global->value ?? 0]);
            DB::table('portal_commission_policies')->where('id', $global->id)->delete();
        }

        DB::table('portal_commission_policies')->whereNull('mode')
            ->update(['mode' => 'none', 'value' => 0]);
        Schema::table('portal_commission_policies', static function (Blueprint $table): void {
            $table->string('mode', 16)->default('none')->nullable(false)->change();
            $table->unsignedBigInteger('value')->default(0)->nullable(false)->change();
        });
    }
};
