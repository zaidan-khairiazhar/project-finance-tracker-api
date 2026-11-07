<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('wallet_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20); // income | expense | transfer_out | transfer_in
            $table->bigInteger('amount'); // rupiah utuh, selalu positif
            $table->timestampTz('occurred_at'); // disimpan UTC
            $table->string('note', 255)->nullable();
            $table->uuid('transfer_group_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'occurred_at']);
            $table->index(['wallet_id', 'occurred_at']);
            $table->index(['user_id', 'category_id', 'occurred_at']);
            $table->index('transfer_group_id');
        });

        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_amount_positive CHECK (amount > 0)');
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_type_check CHECK (type IN ('income', 'expense', 'transfer_out', 'transfer_in'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};