<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Validation\ValidationException;

class TransactionService
{
    public function create(User $user, array $data): Transaction
    {
        WalletGuard::assertUsable(Wallet::findOrFail($data['wallet_id']), 'wallet_id');
        $this->assertCategoryMatches($data['category_id'] ?? null, $data['type']);

        $transaction = new Transaction(array_merge($data, ['occurred_at' => $data['occurred_at'] ?? now()]));
        $transaction->user_id = $user->id;
        $transaction->save();

        return $transaction;
    }

    public function update(Transaction $transaction, array $data): Transaction
    {
        $this->assertNotTransfer($transaction);
        // Dompet asal harus aktif, dan dompet tujuan juga jika dipindah.
        WalletGuard::assertUsable($transaction->wallet, 'wallet_id');
        if (isset($data['wallet_id'])) {
            WalletGuard::assertUsable(Wallet::findOrFail($data['wallet_id']), 'wallet_id');
        }

        $type = $data['type'] ?? $transaction->type->value;
        $categoryId = array_key_exists('category_id', $data) ? $data['category_id'] : $transaction->category_id;
        $this->assertCategoryMatches($categoryId, $type);

        $transaction->update($data);

        return $transaction;
    }

    public function delete(Transaction $transaction): void
    {
        $this->assertNotTransfer($transaction);
        WalletGuard::assertUsable($transaction->wallet, 'wallet_id');

        $transaction->delete();
    }

    private function assertNotTransfer(Transaction $transaction): void
    {
        if (in_array($transaction->type, [TransactionType::TransferOut, TransactionType::TransferIn], true)) {
            throw ValidationException::withMessages([
                'transaction' => 'Transaksi transfer hanya bisa dihapus lewat /transfers.',
            ]);
        }
    }

    public function assertCategoryMatches(?string $categoryId, string $type): void
    {
        if ($categoryId === null) {
            return;
        }

        if (Category::findOrFail($categoryId)->type->value !== $type) {
            throw ValidationException::withMessages([
                'category_id' => 'Tipe kategori tidak sesuai dengan tipe transaksi.',
            ]);
        }
    }
}
