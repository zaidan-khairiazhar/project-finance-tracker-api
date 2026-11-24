<?php

namespace App\Http\Requests;

use App\Support\MonthRange;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $today = CarbonImmutable::now(MonthRange::TIMEZONE)->toDateString();

        return [
            'name' => ['required', 'string', 'max:100'],
            'wallet_id' => [
                'bail', 'required', 'uuid',
                Rule::exists('wallets', 'id')->where(
                    fn ($q) => $q->where('user_id', $this->user()?->id)->whereNull('deleted_at')
                ),
                // Satu dompet hanya untuk satu target aktif.
                Rule::unique('goals', 'wallet_id')->whereNull('deleted_at'),
            ],
            // Batas atas = bilangan bulat aman JavaScript (2^53 - 1).
            'target_amount' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
            'deadline' => ['nullable', 'date_format:Y-m-d', "after_or_equal:{$today}"],
        ];
    }

    public function messages(): array
    {
        return ['wallet_id.unique' => 'Dompet ini sudah dipakai target lain.'];
    }
}
