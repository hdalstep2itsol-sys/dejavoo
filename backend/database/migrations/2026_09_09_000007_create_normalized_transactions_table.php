<?php

use App\Enums\NormalizedTransactionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('normalized_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('trailer_load_id')->constrained()->restrictOnDelete();
            $table->foreignId('dejavoo_terminal_id')
                ->nullable()
                ->constrained('dejavoo_terminals')
                ->restrictOnDelete();
            $table->string('source', 64);
            $table->string('external_transaction_id', 191)->nullable();
            $table->enum('transaction_type', NormalizedTransactionType::values());
            $table->decimal('business_amount', 15, 2);
            $table->decimal('unit_price_snapshot', 15, 2);
            $table->decimal('unit_delta', 20, 8);
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['source', 'external_transaction_id']);
            $table->index(['location_id', 'occurred_at']);
            $table->index(['trailer_load_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('normalized_transactions');
    }
};
