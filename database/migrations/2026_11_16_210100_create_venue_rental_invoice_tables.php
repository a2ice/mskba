<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_rental_invoice_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('scope', 16)->default('global'); // global, venue, agent
            $table->foreignId('venue_id')->nullable()->constrained('venues')->cascadeOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('title', 190);
            $table->text('description')->nullable();
            $table->json('payment_methods');
            $table->text('payment_instructions')->nullable();
            $table->unsignedSmallInteger('due_minutes')->default(30);
            $table->boolean('auto_send')->default(false);
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('priority')->default(100);
            $table->timestamps();
            $table->index(['venue_id', 'scope', 'enabled']);
        });

        Schema::create('venue_rental_invoices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('number', 40)->unique();
            $table->foreignId('venue_booking_id')->constrained('venue_bookings')->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('venue_rental_invoice_templates')->nullOnDelete();
            $table->string('kind', 24); // buyer, agent_portal, portal_venue
            $table->string('issuer_kind', 16); // agent, portal, venue
            $table->string('payer_kind', 16); // buyer, agent, portal
            $table->string('document_kind', 24)->default('invoice'); // invoice or internal_settlement
            $table->foreignId('issuer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('payer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('issued_by_actor_id')->nullable()->constrained('actors')->nullOnDelete();
            $table->foreignId('reviewed_by_actor_id')->nullable()->constrained('actors')->nullOnDelete();
            $table->string('status', 24)->default('draft'); // draft, issued, claimed, paid, postpay, void
            $table->string('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('title', 190);
            $table->text('description')->nullable();
            $table->json('payment_methods');
            $table->text('payment_instructions')->nullable();
            $table->string('settlement_basis', 32)->nullable(); // received, postpay, self_attested
            $table->text('settlement_note')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->json('quote_snapshot'); // immutable price evidence at issue
            $table->timestamps();
            $table->unique(['venue_booking_id', 'kind']);
            $table->index(['payer_user_id', 'status']);
        });

        Schema::create('venue_rental_invoice_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('venue_rental_invoices')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('actors')->nullOnDelete();
            $table->string('action', 40);
            $table->string('old_status', 24)->nullable();
            $table->string('new_status', 24);
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['invoice_id', 'created_at']);
        });

        Schema::create('venue_rental_invoice_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('venue_rental_invoices')->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('actors')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('method', 32);
            $table->string('reference', 190)->nullable();
            $table->text('note')->nullable();
            $table->string('verification', 24); // claimed, confirmed, self_attested
            $table->timestamp('recorded_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_rental_invoice_payments');
        Schema::dropIfExists('venue_rental_invoice_events');
        Schema::dropIfExists('venue_rental_invoices');
        Schema::dropIfExists('venue_rental_invoice_templates');
    }
};
