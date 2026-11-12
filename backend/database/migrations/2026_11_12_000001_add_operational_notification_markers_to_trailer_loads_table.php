<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trailer_loads', function (Blueprint $table) {
            $table->timestamp('threshold_notified_at')->nullable()->after('warehouse_confirmed_by_user_id');
            $table->timestamp('warehouse_notified_at')->nullable()->after('threshold_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('trailer_loads', function (Blueprint $table) {
            $table->dropColumn(['threshold_notified_at', 'warehouse_notified_at']);
        });
    }
};
