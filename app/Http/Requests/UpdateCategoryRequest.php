<?php

namespace App\Http\Requests;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    // `type` sengaja tidak bisa diubah: transaksi dan anak kategori bergantung padanya.
    public function rules(): array
    {
        // Saat Scribe membangun dokumentasi, request dibuat tanpa route binding ($category = null).
        $category = $this->route('category');
        $category = $category instanceof Category ? $category : null;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:50'],
            'color' => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/'],
            'parent_id' => ['sometimes', 'nullable', 'uuid', Rule::notIn($category ? [$category->id] : []), Rule::exists('categories', 'id')->where(
                fn ($q) => $q->where('user_id', $this->user()->id)
                    ->whereNull('parent_id')
                    ->whereNull('deleted_at')
                    ->where('type', $category?->type->value)
            )],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $category = $this->route('category');

                if ($category instanceof Category && $this->filled('parent_id') && $category->children()->exists()) {
                    $validator->errors()->add('parent_id', 'Kategori yang punya subkategori tidak boleh diberi induk.');
                }
            },
        ];
    }
}
