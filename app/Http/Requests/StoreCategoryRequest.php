<?php

namespace App\Http\Requests;

use App\Enums\CategoryType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::enum(CategoryType::class)],
            'icon' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/'],
            // Induk: kategori akar, milik user, belum dihapus, tipe sama.
            'parent_id' => ['nullable', 'uuid', Rule::exists('categories', 'id')->where(
                fn ($q) => $q->where('user_id', $this->user()->id)
                    ->whereNull('parent_id')
                    ->whereNull('deleted_at')
                    ->where('type', $this->input('type'))
            )],
        ];
    }
}
