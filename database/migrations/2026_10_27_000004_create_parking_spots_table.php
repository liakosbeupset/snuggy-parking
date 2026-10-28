<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('parking_spots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->decimal('accuracy', 8, 2);
            $table->text('note')->nullable();
            $table->string('photo_path')->nullable();
            $table->timestamp('parked_at');
            // Null means active.
            $table->timestamp('retrieved_at')->nullable();
            $table->timestamps();

            $table->index(['household_id', 'parked_at']);
        });

        // At most one active spot per household (I2). Partial index syntax shared by PostgreSQL and SQLite.
        DB::statement('CREATE UNIQUE INDEX parking_spots_one_active_per_household ON parking_spots (household_id) WHERE retrieved_at IS NULL');
    }

    /**
     * Reverse the migrations. Dropping the table drops the partial index with it.
     */
    public function down(): void
    {
        Schema::dropIfExists('parking_spots');
    }
};
