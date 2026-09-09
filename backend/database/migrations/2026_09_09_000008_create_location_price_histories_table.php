<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_price_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->decimal('unit_price', 15, 2);
            $table->timestamp('effective_from', 6);
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamps();

            $table->unique(['location_id', 'effective_from']);
        });

        DB::table('locations')
            ->orderBy('id')
            ->each(function (object $location): void {
                DB::table('location_price_histories')->insert([
                    'location_id' => $location->id,
                    'unit_price' => $location->unit_price,
                    'effective_from' => $location->created_at ?? now(),
                    'created_by_user_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_price_histories');
    }
};
