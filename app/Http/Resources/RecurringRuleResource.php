<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\RecurringRule */
class RecurringRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'wallet_id' => $this->wallet_id,
            'wallet_name' => $this->wallet?->name,
            'category_id' => $this->category_id,
            'category_name' => $this->category?->name,
            'type' => $this->type->value,
            'amount' => $this->amount,
            'note' => $this->note,
            'frequency' => $this->frequency->value,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'next_due_date' => $this->next_due_date->toDateString(),
            'is_active' => $this->is_active,
            'paused_reason' => $this->paused_reason,
            'status' => $this->status,
        ];
    }
}
