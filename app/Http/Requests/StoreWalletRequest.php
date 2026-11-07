<?php

namespace App\Http\Requests;

use App\Enums\WalletType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWalletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::enum(WalletType::class)],
            // Batas atas = bilangan bulat aman di JavaScript (2^53 - 1), supaya frontend tidak kehilangan presisi.
            'initial_balance' => ['sometimes', 'integer', 'min:0', 'max:9007199254740991'],
        ];
    }
}
