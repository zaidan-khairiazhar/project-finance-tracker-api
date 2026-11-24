<?php

namespace App\Http\Requests;

use App\Models\Goal;
use App\Support\MonthRange;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** UUID tidak peka huruf besar/kecil; samakan agar perbandingan dengan wallet_id tersimpan konsisten. */
    protected function prepareForValidation(): void
    {
        $walletId = $this->input('wallet_id');
        if (is_string($walletId)) {
            $this->merge(['wallet_id' => strtolower($walletId)]);
        }
    }

    public function rules(): array
    {
        // Scribe membuat request ini tanpa route binding, jadi target tidak selalu tersedia.
        $goal = $this->route('goal');
        $goal = $goal instanceof Goal ? $goal : null;

        $walletRules = ['sometimes', 'bail', 'uuid'];
        $unique = Rule::unique('goals', 'wallet_id')->whereNull('deleted_at');
        if ($goal !== null) {
            $unique->ignore($goal->id);
        }
        $walletId = $this->input('wallet_id');
        $walletUnchanged = $goal !== null && is_string($walletId) && $walletId === $goal->wallet_id;
        if (! $walletUnchanged) {
            // Dompet yang sudah dipakai target ini tidak divalidasi ulang (boleh sudah dihapus), supaya target
            // tetap bisa diubah namanya walau klien ikut mengirim wallet_id yang sama.
            $walletRules[] = Rule::exists('wallets', 'id')->where(
                fn ($q) => $q->where('user_id', $this->user()?->id)->whereNull('deleted_at')
            );
        }
        $walletRules[] = $unique;

        // Tenggat hanya diperiksa bila nilainya berubah, supaya target yang sudah lewat tenggat tetap bisa diubah.
        $deadlineRules = ['sometimes', 'nullable', 'date_format:Y-m-d'];
        $stored = $goal?->deadline?->toDateString();
        if ($goal === null || $this->input('deadline') !== $stored) {
            $deadlineRules[] = 'after_or_equal:'.CarbonImmutable::now(MonthRange::TIMEZONE)->toDateString();
        }

        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'wallet_id' => $walletRules,
            // Batas atas = bilangan bulat aman JavaScript (2^53 - 1).
            'target_amount' => ['sometimes', 'integer', 'min:1', 'max:9007199254740991'],
            'deadline' => $deadlineRules,
        ];
    }

    public function messages(): array
    {
        return ['wallet_id.unique' => 'Dompet ini sudah dipakai target lain.'];
    }
}
