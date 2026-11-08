<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransferService
{
    /** Membuat dua baris (keluar + masuk) dalam satu transaksi DB; mengembalikan transfer_group_id. */
    public function create(
        User $user,
        string $fromWalletId,
        string $toWalletId,
        int $amount,
        CarbonInterface $occurredAt,
        ?string $note,
    ): string {
        $from = Wallet::findOrFail($fromWalletId);
        $to = Wallet::findOrFail($toWalletId);
        WalletGuard::assertUsable($from, 'from_wallet_id');
        WalletGuard::assertUsable($to, 'to_wallet_id');

        $groupId = (string) Str::uuid();

        DB::transaction(function () use ($user, $from, $to, $amount, $occurredAt, $note, $groupId) {
            foreach ([[$from, TransactionType::TransferOut], [$to, TransactionType::TransferIn]] as [$wallet, $type]) {
                $row = new Transaction([
                    'wallet_id' => $wallet->id,
                    'category_id' => null,
                    'type' => $type,
                    'amount' => $amount,
                    'occurred_at' => $occurredAt,
                    'note' => $note,
                    'transfer_group_id' => $groupId,
                ]);
                $row->user_id = $user->id;
                $row->save();
            }
        });

        return $groupId;
    }

    /** Soft delete kedua baris. 404 jika grup tidak ada atau bukan milik user. */
    public function delete(User $user, string $groupId): void
    {
        $rows = Transaction::with('wallet')->where('transfer_group_id', $groupId)->get();

        if ($rows->isEmpty()) {
            throw (new ModelNotFoundException)->setModel(Transaction::class);
        }

        foreach ($rows as $row) {
            WalletGuard::assertUsable($row->wallet, 'transfer');
        }

        DB::transaction(fn () => $rows->each->delete());
    }
}
