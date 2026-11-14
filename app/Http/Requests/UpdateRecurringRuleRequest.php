<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRecurringRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(
            fn ($q) => $q->where('user_id', $this->user()?->id)->whereNull('deleted_at')
        );
        $startDate = $this->route('rule')?->start_date?->toDateString() ?? now()->toDateString();

        return [
            'wallet_id' => ['sometimes', 'uuid', $owned('wallets')],
            'category_id' => ['sometimes', 'nullable', 'uuid', $owned('categories')],
            // Batas atas = bilangan bulat aman JavaScript (2^53 - 1).
            'amount' => ['sometimes', 'integer', 'min:1', 'max:9007199254740991'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', "after_or_equal:{$startDate}"],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Jadwal tidak bisa diubah: hapus lalu buat ulang. Ditolak berdasarkan keberadaan kunci (bukan nilainya),
     * karena `prohibited` meloloskan null/"" dan nilai itu akan menulis NULL ke kolom NOT NULL. Kunci ini
     * tidak punya aturan sehingga tidak pernah masuk ke validated().
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $input = $this->all();
            $messages = [
                'frequency' => 'Frekuensi tidak bisa diubah. Hapus lalu buat aturan baru.',
                'start_date' => 'Tanggal mulai tidak bisa diubah. Hapus lalu buat aturan baru.',
            ];
            foreach ($messages as $key => $message) {
                if (array_key_exists($key, $input)) {
                    $validator->errors()->add($key, $message);
                }
            }
        });
    }
}
