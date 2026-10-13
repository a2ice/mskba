<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('direct_messages', function (Blueprint $table): void {
            $table->string('kind', 20)->default('message');
        });
    }

    public function down(): void
    {
        Schema::table('direct_messages', fn (Blueprint $table) => $table->dropColumn('kind'));
    }
};
