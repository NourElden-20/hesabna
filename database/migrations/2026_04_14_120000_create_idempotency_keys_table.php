<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('endpoint');
            $table->string('idempotency_key');
            $table->unsignedSmallInteger('response_status');
            $table->longText('response_body');
            $table->timestamps();

            $table->unique(['user_id', 'endpoint', 'idempotency_key'], 'idem_user_endpoint_key_unique');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
