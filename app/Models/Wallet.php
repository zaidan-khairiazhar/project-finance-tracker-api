<?php

namespace App\Models;

use App\Enums\WalletType;
use App\Models\Concerns\BelongsToUser;
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
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
