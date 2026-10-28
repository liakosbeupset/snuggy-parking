<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        // Added here, not in the users migration: PostgreSQL rejects a FK to a table that does not exist yet.
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('current_household_id')->nullable()
                ->constrained('households')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_household_id');
        });

        Schema::dropIfExists('households');
    }
};
