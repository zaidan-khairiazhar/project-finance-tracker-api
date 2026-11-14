<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('wallet_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20); // income | expense
            $table->bigInteger('amount'); // rupiah utuh
            $table->string('note', 255)->nullable();
            $table->string('frequency', 20); // daily | weekly | monthly
            $table->date('start_date'); // tanggal kalender Asia/Jakarta
            $table->date('end_date')->nullable(); // inklusif
            $table->date('next_due_date'); // kemunculan berikutnya yang belum dibuat
            $table->boolean('is_active')->default(true);
            $table->string('paused_reason', 40)->nullable(); // wallet_archived | wallet_deleted | category_invalid
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'next_due_date']);
            $table->index(['is_active', 'next_due_date']);
        });

        DB::statement('ALTER TABLE recurring_rules ADD CONSTRAINT recurring_rules_amount_positive CHECK (amount > 0)');
        DB::statement("ALTER TABLE recurring_rules ADD CONSTRAINT recurring_rules_type_check CHECK (type IN ('income', 'expense'))");
        DB::statement("ALTER TABLE recurring_rules ADD CONSTRAINT recurring_rules_frequency_check CHECK (frequency IN ('daily', 'weekly', 'monthly'))");
        DB::statement('ALTER TABLE recurring_rules ADD CONSTRAINT recurring_rules_dates_check CHECK (end_date IS NULL OR end_date >= start_date)');
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_rules');
    }
};
