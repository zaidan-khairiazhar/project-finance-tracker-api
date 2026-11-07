<?php

namespace App\Http\Requests;

use App\Enums\WalletType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWalletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'type' => ['sometimes', 'required', Rule::enum(WalletType::class)],
            'initial_balance' => ['sometimes', 'integer', 'min:0', 'max:9007199254740991'],
            'is_archived' => ['sometimes', 'boolean'],
        ];
    }
}
