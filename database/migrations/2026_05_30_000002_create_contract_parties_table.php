<?php

use App\Modules\Contract\Domain\Enums\ContractPartyRoleEnum;
use App\Modules\Contract\Domain\Enums\ContractPartyTypeEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_parties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->string('party_type');
            $table->unsignedBigInteger('party_id');
            $table->string('role')->default(ContractPartyRoleEnum::HOLDER->value);
            $table->timestamps();

            $table->unique(['contract_id', 'party_type', 'party_id', 'role']);
            $table->index(['party_type', 'party_id']);
        });

        DB::table('contracts')
            ->select(['id', 'user_id'])
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->get()
            ->each(function (object $contract): void {
                DB::table('contract_parties')->insert([
                    'contract_id' => $contract->id,
                    'party_type' => ContractPartyTypeEnum::USER->value,
                    'party_id' => $contract->user_id,
                    'role' => ContractPartyRoleEnum::HOLDER->value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        Schema::table('contracts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        DB::table('contract_parties')
            ->where('party_type', ContractPartyTypeEnum::USER->value)
            ->where('role', ContractPartyRoleEnum::HOLDER->value)
            ->orderBy('id')
            ->get()
            ->each(function (object $party): void {
                DB::table('contracts')
                    ->where('id', $party->contract_id)
                    ->update(['user_id' => $party->party_id]);
            });

        Schema::dropIfExists('contract_parties');
    }
};
