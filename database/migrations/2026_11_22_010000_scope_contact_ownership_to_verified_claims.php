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
            $table->boolean('ownership_reserved')->default(false);
        });

        DB::table('contacts')->where('contactable_type', 'user')->whereIn('type', ['email', 'phone'])
            ->whereNotNull('verified_at')->update(['ownership_reserved' => true]);

        // Preserve known historical ownership; old deletion cleared verified_at.
        DB::table('contacts')->where('contactable_type', 'user')
            ->whereIn('type', ['email', 'phone'])->whereNotNull('deleted_at')
            ->whereNull('verified_at')->orderBy('id')->chunkById(500, function ($contacts): void {
                $ids = $contacts->pluck('id')->all();
                $confirmed = DB::table('contact_verifications')->whereIn('contact_id', $ids)
                    ->where('status', 'confirmed')->pluck('contact_id')->all();
                $audits = DB::table('audit_logs')
                    ->where('auditable_type', 'App\\Modules\\Contact\\Domain\\Models\\Contact')
                    ->whereIn('auditable_id', $ids)->get(['auditable_id', 'old_values', 'new_values']);
                foreach ($audits as $audit) {
                    $old = json_decode((string) $audit->old_values, true) ?: [];
                    $new = json_decode((string) $audit->new_values, true) ?: [];
                    if (! empty($old['verified_at']) || ! empty($new['verified_at'])) {
                        $confirmed[] = $audit->auditable_id;
                    }
                }
                DB::table('contacts')->whereIn('id', array_unique($confirmed))->update(['ownership_reserved' => true]);
            });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropUnique(['user_email_key']);
        });
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropColumn('user_email_key');
        });
        $pg = DB::getDriverName() === 'pgsql';
        Schema::table('contacts', function (Blueprint $table) use ($pg): void {
            $email = "CASE WHEN contactable_type = 'user' AND type = 'email' AND (verified_at IS NOT NULL OR ownership_reserved) THEN lower(trim(value)) ELSE NULL END";
            $phone = "replace(replace(replace(replace(trim(value), ' ', ''), '-', ''), '(', ''), ')', '')";
            $normalized = "CASE WHEN substr($phone,1,2) = '00' AND length($phone) >= 9 THEN '+' || substr($phone,3) WHEN length($phone) = 11 AND substr($phone,1,1) IN ('7','8') THEN '+7' || substr($phone,2) ELSE $phone END";
            $phone = "CASE WHEN contactable_type = 'user' AND type = 'phone' THEN $normalized ELSE NULL END";
            $emailKey = $table->string('user_email_key')->nullable();
            $phoneKey = $table->string('user_phone_key')->nullable();
            if ($pg) {
                $emailKey->storedAs($email);
                $phoneKey->storedAs($phone);
            } else {
                $emailKey->virtualAs($email);
                $phoneKey->virtualAs($phone);
            }
            $table->unique('user_email_key');
            // Phones can have historical duplicates, including merged aliases. Do
            // not silently reassign or delete them. New proofs use ContactOwnership.
            $table->index('user_phone_key');
        });
    }

    public function down(): void
    {
        $duplicates = DB::table('contacts')->where('contactable_type', 'user')->where('type', 'email')
            ->selectRaw('lower(trim(value)) as normalized')->groupByRaw('lower(trim(value))')->havingRaw('COUNT(*) > 1')->exists();
        if ($duplicates) {
            throw new RuntimeException('Rollback would remove valid pending contact claims; resolve duplicates explicitly.');
        }
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropUnique(['user_email_key']);
            $table->dropIndex(['user_phone_key']);
        });
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropColumn(['user_phone_key', 'user_email_key']);
        });
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropColumn('ownership_reserved');
        });
        $pg = DB::getDriverName() === 'pgsql';
        Schema::table('contacts', function (Blueprint $table) use ($pg): void {
            $expression = "CASE WHEN contactable_type = 'user' AND type = 'email' THEN lower(trim(value)) ELSE NULL END";
            $key = $table->string('user_email_key')->nullable();
            $pg ? $key->storedAs($expression) : $key->virtualAs($expression);
            $table->unique('user_email_key');
        });
    }
};
