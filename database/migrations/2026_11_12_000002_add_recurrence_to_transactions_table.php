<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignUuid('recurring_rule_id')->nullable()->constrained('recurring_rules')->restrictOnDelete();
            $table->date('occurrence_date')->nullable();

            // Unique index biasa (bukan partial): baris soft-deleted ikut dihitung,
            // jadi transaksi otomatis yang sudah dihapus user tidak dibuat ulang.
            $table->unique(['recurring_rule_id', 'occurrence_date']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['recurring_rule_id', 'occurrence_date']);
            $table->dropConstrainedForeignId('recurring_rule_id');
            $table->dropColumn('occurrence_date');
        });
    }
};
