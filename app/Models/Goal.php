<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Goal extends Model
{
    use BelongsToUser, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['name', 'wallet_id', 'target_amount', 'deadline'];

    protected function casts(): array
    {
        return [
            'target_amount' => 'integer',
            'deadline' => 'date',
        ];
    }

    /** Dompet yang diarsipkan atau di-soft-delete tetap terbaca supaya target tetap tampil. */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class)->withTrashed();
    }
}
