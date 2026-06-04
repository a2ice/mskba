<?php

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table): void {
            $table->id();
            $table->morphs('contactable');
            $table->enum('type', array_column(ContactTypeEnum::cases(), 'value'));
            $table->string('value');
            $table->string('label')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_public')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['contactable_type', 'contactable_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
