<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** Tidak bergantung pada route binding agar aman dipakai Scribe. */
    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'max:5120', 'mimetypes:image/jpeg,image/png,image/webp'],
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'image' => [
                'description' => 'Foto struk (JPEG, PNG, atau WebP), maksimal 5 MB.',
            ],
        ];
    }
}
