<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ipospays_feed_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider_event_id', 191)->nullable()->unique();
            $table->string('provider_transaction_id', 191)->nullable()->index();
            $table->string('event_type', 64)->nullable();
            $table->string('sub_event_type', 64)->nullable();
            $table->string('request_type', 64)->nullable();
            $table->string('tpn', 64)->nullable();
            $table->string('term_id', 64)->nullable();
            $table->string('transaction_type', 32)->nullable();
            $table->decimal('business_amount', 15, 2)->nullable();
            $table->decimal('base_amount', 15, 2)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->string('status', 64)->index();
            $table->string('error_code', 64)->nullable()->index();
            $table->char('payload_fingerprint', 64)->nullable();
            $table->char('financial_fingerprint', 64)->nullable();
            $table->unsignedInteger('delivery_count')->default(1);
            $table->timestamp('last_received_at');
            $table->timestamp('authenticated_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('dejavoo_terminal_id')
                ->nullable()
                ->constrained('dejavoo_terminals')
                ->nullOnDelete();
            $table->foreignId('location_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->foreignId('normalized_transaction_id')
                ->nullable()
                ->constrained('normalized_transactions')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['provider_transaction_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ipospays_feed_events');
    }
};
