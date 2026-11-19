<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->decimal('early_warning_threshold', 10, 2)->nullable()->after('haul_threshold');
        });

        Schema::table('trailer_loads', function (Blueprint $table) {
            $table->timestamp('early_warning_notified_at')->nullable()->after('threshold_notified_at');
            $table->timestamp('warehouse_confirmed_notified_at')->nullable()->after('warehouse_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('trailer_loads', function (Blueprint $table) {
            $table->dropColumn(['early_warning_notified_at', 'warehouse_confirmed_notified_at']);
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('early_warning_threshold');
        });
    }
};
