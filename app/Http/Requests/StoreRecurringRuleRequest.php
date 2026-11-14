<?php

namespace App\Http\Requests;

use App\Enums\RecurrenceFrequency;
use App\Support\MonthRange;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRecurringRuleRequest extends FormRequest
{
    /** Batas mundur tanggal mulai (hari), supaya satu permintaan tidak membuat ribuan transaksi sekaligus. */
    public const MAX_BACKFILL_DAYS = 366;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(
            fn ($q) => $q->where('user_id', $this->user()?->id)->whereNull('deleted_at')
        );
        $earliest = CarbonImmutable::now(MonthRange::TIMEZONE)->subDays(self::MAX_BACKFILL_DAYS)->toDateString();

        return [
            'wallet_id' => ['required', 'uuid', $owned('wallets')],
            'category_id' => ['nullable', 'uuid', $owned('categories')],
            'type' => ['required', Rule::in(['income', 'expense'])],
            // Batas atas = bilangan bulat aman JavaScript (2^53 - 1).
            'amount' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
            'note' => ['nullable', 'string', 'max:255'],
            'frequency' => ['required', Rule::enum(RecurrenceFrequency::class)],
            'start_date' => ['required', 'date_format:Y-m-d', "after_or_equal:{$earliest}"],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ];
    }
}
