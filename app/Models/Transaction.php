<?php

namespace App\Models;

use App\Enums\TransactionType;
use App\Models\Concerns\BelongsToUser;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    use BelongsToUser, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'wallet_id', 'category_id', 'type', 'amount',
        'occurred_at', 'note', 'transfer_group_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'amount' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * Selalu simpan sebagai waktu UTC. Tanpa ini, string ber-offset seperti
     * "2026-11-07T23:30:00+07:00" akan disimpan sebagai 23:30 UTC (jam dindingnya
     * dipakai, offset-nya dibuang), yaitu salah 7 jam.
     */
    protected function occurredAt(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value === null
                ? null
                : Carbon::parse($value)->utc()->format($this->getDateFormat()),
        );
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Filter: wallet_id, category_id, type, from, to, q.
     * `from`/`to` adalah tanggal kalender Asia/Jakarta (inklusif satu hari penuh),
     * dikonversi ke UTC sebelum masuk query.
     */
    public function scopeFilter(Builder $query, array $f): Builder
    {
        return $query
            ->when($f['wallet_id'] ?? null, fn ($q, $v) => $q->where('wallet_id', $v))
            ->when($f['category_id'] ?? null, fn ($q, $v) => $q->where('category_id', $v))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where(
                'occurred_at', '>=', Carbon::parse($v, 'Asia/Jakarta')->startOfDay()->utc()
            ))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where(
                'occurred_at', '<=', Carbon::parse($v, 'Asia/Jakarta')->endOfDay()->utc()
            ))
            ->when($f['q'] ?? null, function ($q, $v) {
                $escaped = addcslashes($v, '%_\\');

                return $q->where('note', 'ILIKE', "%{$escaped}%");
            });
    }
}
