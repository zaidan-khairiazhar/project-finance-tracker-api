<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(
            fn ($q) => $q->where('user_id', $this->user()->id)->whereNull('deleted_at')
        );

        return [
            'wallet_id' => ['required', 'uuid', $owned('wallets')],
            'category_id' => ['nullable', 'uuid', $owned('categories')],
            'type' => ['required', Rule::in(['income', 'expense'])],
            // Batas atas = bilangan bulat aman JavaScript (2^53 - 1), supaya frontend tidak kehilangan presisi.
            'amount' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
            'occurred_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
