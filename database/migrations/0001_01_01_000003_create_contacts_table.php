<?php

use App\Modules\Contact\Domain\Enums\ContactStatusEnum;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->enum('contact_type', array_column(ContactTypeEnum::cases(), 'value'));
            $table->string('value');
            $table->enum('status', array_column(ContactStatusEnum::cases(), 'value'))
                ->default(ContactStatusEnum::UNVERIFIED->value);
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['status']);
            $table->index(['contact_type', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
