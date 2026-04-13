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

        Schema::create('group_guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->foreignId('converted_to_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('group_guests');
    }
};
