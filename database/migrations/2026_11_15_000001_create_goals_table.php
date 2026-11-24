<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('wallet_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->bigInteger('target_amount'); // rupiah utuh, 1..9007199254740991
            $table->date('deadline')->nullable(); // tanggal kalender Asia/Jakarta
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
        });

        // Satu dompet hanya boleh punya satu target aktif; target yang dihapus membebaskan dompet.
        DB::statement('CREATE UNIQUE INDEX goals_wallet_id_active_unique ON goals (wallet_id) WHERE deleted_at IS NULL');
        DB::statement('ALTER TABLE goals ADD CONSTRAINT goals_target_amount_range CHECK (target_amount BETWEEN 1 AND 9007199254740991)');
    }

    public function down(): void
    {
        Schema::dropIfExists('goals');
    }
};
