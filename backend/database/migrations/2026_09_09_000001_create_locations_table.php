<?php

use App\Enums\LocationRouteType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('haul_threshold', 10, 2);
            $table->enum('route_type', LocationRouteType::values());
            $table->foreignId('dedicated_driver_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
