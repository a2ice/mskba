<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_rental_daily_metrics', function (Blueprint $table): void {
            $table->date('day');
            $table->string('event', 32);
            // Zero represents the ordinary catalogue, without a landing.
            $table->unsignedBigInteger('landing_id')->default(0);
            $table->string('source', 32);
            $table->unsignedBigInteger('hits')->default(0);
            $table->primary(['day', 'event', 'landing_id', 'source'], 'seo_rental_daily_metrics_pk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_rental_daily_metrics');
    }
};
