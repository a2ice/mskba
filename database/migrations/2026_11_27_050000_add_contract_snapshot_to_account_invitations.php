<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_invitations', function (Blueprint $table): void {
            $table->json('proposed_permissions')->nullable();
            $table->foreignId('granted_membership_id')->nullable()->constrained('contract_memberships')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('account_invitations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('granted_membership_id');
            $table->dropColumn('proposed_permissions');
        });
    }
};
