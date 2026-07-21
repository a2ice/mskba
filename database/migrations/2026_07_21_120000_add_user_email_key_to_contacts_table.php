<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $contacts = DB::table('contacts')
            ->where('contactable_type', 'user')
            ->where('type', 'email')
            ->orderBy('id')
            ->get(['id', 'value']);

        $duplicates = $contacts
            ->groupBy(fn (object $contact): string => mb_strtolower(trim((string) $contact->value)))
            ->filter(fn ($group): bool => $group->count() > 1);

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Нельзя закрепить уникальность email пользователей: найдены дубли в contacts.');
        }

        foreach ($contacts as $contact) {
            $normalizedEmail = mb_strtolower(trim((string) $contact->value));

            DB::table('contacts')
                ->where('id', $contact->id)
                ->update(['value' => $normalizedEmail]);
        }

        $isPostgres = DB::getDriverName() === 'pgsql';

        Schema::table('contacts', function (Blueprint $table) use ($isPostgres): void {
            $userEmailKey = $table->string('user_email_key')->nullable()->after('value');
            $expression = "CASE WHEN contactable_type = 'user' AND type = 'email' THEN lower(trim(value)) ELSE NULL END";

            if ($isPostgres) {
                $userEmailKey->storedAs($expression);
            } else {
                $userEmailKey->virtualAs($expression);
            }

            $table->unique('user_email_key');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropUnique(['user_email_key']);
            $table->dropColumn('user_email_key');
        });
    }
};
