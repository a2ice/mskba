<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_consents', function (Blueprint $table): void {
            $table->json('payload')->nullable()->after('user_agent');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('personal_data_distribution_required_at')->nullable()->after('first_logged_in_at');
            $table->timestampTz('personal_data_distribution_setup_completed_at')->nullable()->after('personal_data_distribution_required_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'personal_data_distribution_required_at',
                'personal_data_distribution_setup_completed_at',
            ]);
        });

        Schema::table('user_consents', function (Blueprint $table): void {
            $table->dropColumn('payload');
        });
    }
};
