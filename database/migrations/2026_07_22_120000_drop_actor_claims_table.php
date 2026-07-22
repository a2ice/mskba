<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('actor_claims');
    }

    public function down(): void
    {
        // ActorClaim intentionally has no rollback path: guest ownership was
        // removed from the domain model during development.
    }
};
