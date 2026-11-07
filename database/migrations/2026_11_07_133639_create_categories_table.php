<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->string('name', 100);
            $table->string('type', 10); // income | expense
            $table->string('icon', 50)->nullable();
            $table->string('color', 9)->nullable(); // #RRGGBB atau #RRGGBBAA
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'type']);
            $table->index('parent_id');
        });

        // Foreign key self-reference harus dibuat SETELAH tabel dan primary key-nya ada.
        // Di PostgreSQL, ->primary() pada kolom dijalankan sebagai ALTER terpisah, setelah
        // foreign key yang dideklarasikan di dalam Schema::create (lihat error 42830).
        Schema::table('categories', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('categories')->nullOnDelete();
        });

        DB::statement("ALTER TABLE categories ADD CONSTRAINT categories_type_check CHECK (type IN ('income', 'expense'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};