<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dejavoo_terminals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')
                ->constrained()
                ->restrictOnDelete();
            $table->string('tpn', 64)->nullable()->unique();
            $table->string('term_id', 64)->nullable()->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dejavoo_terminals');
    }
};
