<?php

namespace App\Services;

use App\Models\RecurringRule;
use App\Models\Scopes\UserScope;
use App\Models\User;
use App\Support\MonthRange;
use App\Support\RecurrenceSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Membuat transaksi dari aturan berulang yang sudah jatuh tempo. Dipanggil oleh command terjadwal
 * (catchUpAll), saat login dan POST /recurring-rules/run (catchUp), dan saat aturan dibuat (catchUpRule).
 * Semua kemunculan yang terlewat disusul dengan tanggalnya sendiri; indeks unik
 * (recurring_rule_id, occurrence_date) menjaga agar tidak ada duplikat.
 */
class RecurringGenerator
{
    public function __construct(private TransactionService $transactions) {}

    /** Susul semua aturan milik satu user. Mengembalikan jumlah transaksi yang dibuat. */
    public function catchUp(User $user): int
    {
        return $this->run($this->dueRules()->where('user_id', $user->id));
    }

    /** Susul semua aturan semua user (command terjadwal). */
    public function catchUpAll(): int
    {
        return $this->run($this->dueRules());
    }

    /** Susul satu aturan. Kegagalan sistem dibiarkan naik ke pemanggil. */
    public function catchUpRule(RecurringRule $rule): int
    {
        return DB::transaction(function () use ($rule): int {
            // Kunci baris supaya command dan catchUp yang berjalan bersamaan menunggu giliran,
            // lalu baca ulang next_due_date yang mungkin sudah dimajukan pemanggil lain.
            $locked = RecurringRule::withoutGlobalScope(UserScope::class)->lockForUpdate()->find($rule->id);
            if ($locked === null || ! $locked->is_active) {
                return 0;
            }

            $today = $this->today();
            $due = $this->day($locked->next_due_date);
            $start = $this->day($locked->start_date);
            $end = $locked->end_date === null ? null : $this->day($locked->end_date);

            if (! $this->isDue($due, $today, $end)) {
                return 0;
            }

            if ($problem = $this->problemWith($locked)) {
                $locked->forceFill(['is_active' => false, 'paused_reason' => $problem])->save();
                Log::warning('Aturan berulang dijeda.', ['rule' => $locked->id, 'reason' => $problem]);

                return 0;
            }

            $created = 0;
            while ($this->isDue($due, $today, $end)) {
                $created += $this->createOccurrence($locked, $due) ? 1 : 0;
                $due = RecurrenceSchedule::nextAfter($locked->frequency, $start, $due);
            }

            $locked->forceFill(['next_due_date' => $due->toDateString()])->save();

            return $created;
        });
    }

    private function dueRules(): Builder
    {
        return RecurringRule::withoutGlobalScope(UserScope::class)
            ->where('is_active', true)
            ->where('next_due_date', '<=', $this->today()->toDateString())
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereColumn('next_due_date', '<=', 'end_date'));
    }

    /** Satu aturan yang gagal tidak menghentikan aturan lain. */
    private function run(Builder $rules): int
    {
        $created = 0;

        $rules->chunkById(100, function ($chunk) use (&$created): void {
            foreach ($chunk as $rule) {
                try {
                    $created += $this->catchUpRule($rule);
                } catch (Throwable $e) {
                    Log::error('Gagal membuat transaksi berulang.', ['rule' => $rule->id, 'error' => $e->getMessage()]);
                }
            }
        });

        return $created;
    }

    /** Buat satu transaksi; false bila kemunculan itu sudah pernah ada (termasuk yang dihapus user). */
    private function createOccurrence(RecurringRule $rule, CarbonImmutable $date): bool
    {
        try {
            // Transaksi bersarang = savepoint, supaya pelanggaran unik tidak membatalkan transaksi luar di PostgreSQL.
            DB::transaction(fn () => $this->transactions->create($rule->user, [
                'wallet_id' => $rule->wallet_id,
                'category_id' => $rule->category_id,
                'type' => $rule->type->value,
                'amount' => $rule->amount,
                'note' => $rule->note,
                'occurred_at' => CarbonImmutable::parse($date->toDateString(), MonthRange::TIMEZONE)->startOfDay(),
                'recurring_rule_id' => $rule->id,
                'occurrence_date' => $date->toDateString(),
            ]));

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    private function problemWith(RecurringRule $rule): ?string
    {
        $wallet = $rule->wallet;
        if ($wallet === null || $wallet->trashed()) {
            return 'wallet_deleted';
        }
        if ($wallet->is_archived) {
            return 'wallet_archived';
        }
        if ($rule->category_id !== null && ($rule->category === null || $rule->category->trashed())) {
            return 'category_invalid';
        }

        return null;
    }

    private function isDue(CarbonImmutable $due, CarbonImmutable $today, ?CarbonImmutable $end): bool
    {
        return $due->lte($today) && ($end === null || $due->lte($end));
    }

    /** Hari ini menurut kalender Asia/Jakarta, sebagai tanggal tanpa jam. */
    private function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now(MonthRange::TIMEZONE)->toDateString());
    }

    private function day(\DateTimeInterface $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->format('Y-m-d'));
    }
}
