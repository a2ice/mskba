<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_attributions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referrer_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('referred_user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('source', 64)->default('referral_link');
            $table->timestamp('captured_at');
            $table->timestamp('linked_at');
            $table->timestamps();

            $table->index(['referrer_user_id', 'linked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_attributions');
    }
};
