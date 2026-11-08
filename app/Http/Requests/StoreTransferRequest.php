<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $ownedWallet = Rule::exists('wallets', 'id')->where(
            fn ($q) => $q->where('user_id', $this->user()->id)->whereNull('deleted_at')
        );

        return [
            'from_wallet_id' => ['required', 'uuid', $ownedWallet],
            'to_wallet_id' => ['required', 'uuid', 'different:from_wallet_id', $ownedWallet],
            'amount' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
            'occurred_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
