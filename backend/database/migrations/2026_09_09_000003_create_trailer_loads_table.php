<?php

use App\Enums\TrailerLoadStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trailer_loads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')
                ->constrained()
                ->restrictOnDelete();
            $table->enum('status', TrailerLoadStatus::values());
            $table->timestamp('started_at');
            $table->foreignId('created_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();

            // NULL values can repeat, while an active location id cannot. This
            // makes the one-active-load invariant atomic at the database layer.
            $table->unsignedBigInteger('active_location_guard')
                ->nullable()
                ->storedAs("CASE WHEN status = 'active' THEN location_id ELSE NULL END")
                ->unique();

            $table->timestamps();
            $table->index(['location_id', 'status']);
            $table->index(['location_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trailer_loads');
    }
};
