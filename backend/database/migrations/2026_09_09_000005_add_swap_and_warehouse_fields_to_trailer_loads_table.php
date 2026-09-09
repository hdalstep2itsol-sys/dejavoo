<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trailer_loads', function (Blueprint $table) {
            $table->timestamp('swapped_at')->nullable()->after('committed_by_user_id');
            $table->foreignId('swapped_by_user_id')
                ->nullable()
                ->after('swapped_at')
                ->constrained('users')
                ->restrictOnDelete();
            $table->unsignedInteger('warehouse_actual_count')
                ->nullable()
                ->after('swapped_by_user_id');
            $table->text('warehouse_notes')->nullable()->after('warehouse_actual_count');
            $table->timestamp('warehouse_confirmed_at')
                ->nullable()
                ->after('warehouse_notes');
            $table->foreignId('warehouse_confirmed_by_user_id')
                ->nullable()
                ->after('warehouse_confirmed_at')
                ->constrained('users')
                ->restrictOnDelete();
            $table->index(['status', 'swapped_at']);
        });
    }

    public function down(): void
    {
        Schema::table('trailer_loads', function (Blueprint $table) {
            $table->dropIndex(['status', 'swapped_at']);
            $table->dropConstrainedForeignId('warehouse_confirmed_by_user_id');
            $table->dropColumn([
                'warehouse_confirmed_at',
                'warehouse_notes',
                'warehouse_actual_count',
            ]);
            $table->dropConstrainedForeignId('swapped_by_user_id');
            $table->dropColumn('swapped_at');
        });
    }
};
