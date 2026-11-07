<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Wallet */
class WalletResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'initial_balance' => $this->initial_balance,
            'currency' => $this->currency,
            'is_archived' => $this->is_archived,
            'balance' => $this->balance,
        ];
    }
}
