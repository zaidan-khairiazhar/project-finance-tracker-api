<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Transaction */
class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'wallet_id' => $this->wallet_id,
            'category_id' => $this->category_id,
            'type' => $this->type->value,
            'amount' => $this->amount,
            // Selalu UTC dengan akhiran Z; frontend yang menampilkannya di Asia/Jakarta.
            'occurred_at' => $this->occurred_at->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'note' => $this->note,
            'transfer_group_id' => $this->transfer_group_id,
            'recurring_rule_id' => $this->recurring_rule_id,
        ];
    }
}
