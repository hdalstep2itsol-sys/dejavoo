<?php

use App\Enums\DriverCommitmentSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trailer_loads', function (Blueprint $table) {
            $table->foreignId('committed_driver_id')
                ->nullable()
                ->after('created_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->enum('commitment_source', DriverCommitmentSource::values())
                ->nullable()
                ->after('committed_driver_id');
            $table->timestamp('committed_at')
                ->nullable()
                ->after('commitment_source');
            $table->foreignId('committed_by_user_id')
                ->nullable()
                ->after('committed_at')
                ->constrained('users')
                ->restrictOnDelete();
            $table->index(['status', 'committed_driver_id']);
        });
    }

    public function down(): void
    {
        Schema::table('trailer_loads', function (Blueprint $table) {
            $table->dropIndex(['status', 'committed_driver_id']);
            $table->dropConstrainedForeignId('committed_by_user_id');
            $table->dropColumn(['committed_at', 'commitment_source']);
            $table->dropConstrainedForeignId('committed_driver_id');
        });
    }
};
