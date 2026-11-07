<?php

namespace App\Models;

use App\Enums\WalletType;
use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Wallet extends Model
{
    use BelongsToUser, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['name', 'type', 'initial_balance', 'currency', 'is_archived'];

    // Default di level model supaya nilainya langsung ada setelah create(), tanpa refresh().
    protected $attributes = [
        'initial_balance' => 0,
        'currency' => 'IDR',
        'is_archived' => false,
    ];

    protected function casts(): array
    {
        return [
            'type' => WalletType::class,
            'initial_balance' => 'integer',
            'is_archived' => 'boolean',
            'balance' => 'integer', // hanya ada jika query memakai withBalance()
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Menambahkan atribut `balance`:
     * saldo awal + (income + transfer_in) - (expense + transfer_out),
     * dihitung dari transaksi yang belum di-soft-delete. Saldo tidak pernah disimpan.
     */
    public function scopeWithBalance(Builder $query): Builder
    {
        return $query->select('wallets.*')->selectRaw(
            "(wallets.initial_balance + COALESCE((
                SELECT SUM(CASE WHEN t.type IN ('income', 'transfer_in') THEN t.amount ELSE -t.amount END)
                FROM transactions t
                WHERE t.wallet_id = wallets.id AND t.deleted_at IS NULL
            ), 0))::bigint AS balance"
        );
    }
}
