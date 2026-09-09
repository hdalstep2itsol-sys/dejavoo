<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trailer_load_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trailer_load_id')->constrained()->restrictOnDelete();
            $table->decimal('unit_delta', 20, 8);
            $table->string('reason', 1000);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['trailer_load_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trailer_load_adjustments');
    }
};
