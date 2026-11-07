<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTransactionRequest extends FormRequest
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
            'wallet_id' => ['sometimes', 'required', 'uuid', $owned('wallets')],
            'category_id' => ['sometimes', 'nullable', 'uuid', $owned('categories')],
            'type' => ['sometimes', 'required', Rule::in(['income', 'expense'])],
            'amount' => ['sometimes', 'required', 'integer', 'min:1', 'max:9007199254740991'],
            'occurred_at' => ['sometimes', 'required', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
