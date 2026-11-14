<?php

namespace App\Models;

use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionType;
use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RecurringRule extends Model
{
    use BelongsToUser, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'wallet_id', 'category_id', 'type', 'amount', 'note', 'frequency',
        'start_date', 'end_date', 'next_due_date', 'is_active', 'paused_reason',
    ];

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'frequency' => RecurrenceFrequency::class,
            'amount' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'next_due_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /** Dompet yang di-soft-delete tetap bisa dibaca supaya generator bisa menjeda aturan dengan alasan yang jelas. */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class)->withTrashed();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class)->withTrashed();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** Dihitung, tidak disimpan: paused, finished, atau active. */
    protected function status(): Attribute
    {
        return Attribute::get(function (): string {
            if (! $this->is_active) {
                return 'paused';
            }

            if ($this->end_date !== null && $this->next_due_date->gt($this->end_date)) {
                return 'finished';
            }

            return 'active';
        });
    }
}
