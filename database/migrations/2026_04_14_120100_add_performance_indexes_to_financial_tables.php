<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->index(['group_id', 'created_at'], 'expenses_group_created_idx');
        });

        Schema::table('expense_splits', function (Blueprint $table) {
            $table->index(['expense_id', 'user_id'], 'expense_splits_expense_user_idx');
            $table->index(['expense_id', 'guest_id'], 'expense_splits_expense_guest_idx');
            $table->index('created_at', 'expense_splits_created_idx');
        });

        Schema::table('settlements', function (Blueprint $table) {
            $table->index(['group_id', 'created_at'], 'settlements_group_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex('expenses_group_created_idx');
        });

        Schema::table('expense_splits', function (Blueprint $table) {
            $table->dropIndex('expense_splits_expense_user_idx');
            $table->dropIndex('expense_splits_expense_guest_idx');
            $table->dropIndex('expense_splits_created_idx');
        });

        Schema::table('settlements', function (Blueprint $table) {
            $table->dropIndex('settlements_group_created_idx');
        });
    }
};
