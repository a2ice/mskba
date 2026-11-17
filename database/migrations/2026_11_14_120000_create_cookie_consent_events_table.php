<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cookie_consent_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('visitor_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('analytics_choice', 16);
            $table->string('notice_version', 40);
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cookie_consent_events');
    }
};
