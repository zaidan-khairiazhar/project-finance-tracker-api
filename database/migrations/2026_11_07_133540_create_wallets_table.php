<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('type', 20); // cash | bank | ewallet
            $table->bigInteger('initial_balance')->default(0); // rupiah utuh
            $table->char('currency', 3)->default('IDR');
            $table->boolean('is_archived')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'is_archived']);
        });

        DB::statement("ALTER TABLE wallets ADD CONSTRAINT wallets_type_check CHECK (type IN ('cash', 'bank', 'ewallet'))");
        DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallets_initial_balance_check CHECK (initial_balance >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};