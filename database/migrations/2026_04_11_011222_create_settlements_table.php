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
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('from_user_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('from_guest_id')
                ->nullable()
                ->constrained('group_guests')
                ->cascadeOnDelete();
            $table->foreignId('to_user_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('to_guest_id')
                ->nullable()
                ->constrained('group_guests')
                ->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};
