<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Hanya kategori pengeluaran milik user yang belum dihapus.
            'category_id' => ['required', 'uuid', Rule::exists('categories', 'id')->where(
                fn ($q) => $q->where('user_id', $this->user()->id)
                    ->where('type', 'expense')
                    ->whereNull('deleted_at')
            )],
            // Batas atas = bilangan bulat aman JavaScript (2^53 - 1).
            'amount' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
        ];
    }
}
