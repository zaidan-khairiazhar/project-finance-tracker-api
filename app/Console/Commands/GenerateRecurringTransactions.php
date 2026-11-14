<?php

namespace App\Console\Commands;

use App\Services\RecurringGenerator;
use Illuminate\Console\Command;

class GenerateRecurringTransactions extends Command
{
    protected $signature = 'recurring:generate';

    protected $description = 'Buat transaksi dari aturan berulang yang sudah jatuh tempo (semua user)';

    public function handle(RecurringGenerator $generator): int
    {
        // Kegagalan per aturan sudah dicatat generator; hanya kegagalan sistemik yang sampai ke sini dan menggagalkan command.
        $created = $generator->catchUpAll();

        $this->info("Transaksi dibuat: {$created}");

        return self::SUCCESS;
    }
}
