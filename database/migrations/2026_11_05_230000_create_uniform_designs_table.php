<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uniform_designs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('name', 100);
            $table->char('primary_color', 7)->default('#111924');
            $table->char('secondary_color', 7)->default('#f58220');
            $table->jsonb('sizes');
            $table->string('logo_source', 12)->default('none'); // none, team, custom
            $table->foreignId('logo_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->unsignedSmallInteger('editor_version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uniform_designs');
    }
};
