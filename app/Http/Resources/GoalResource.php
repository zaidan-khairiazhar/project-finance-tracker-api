<?php

namespace App\Http\Resources;

use App\Support\GoalProgress;
use App\Support\MonthRange;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Progres dihitung saat dibaca dari saldo dompet; relasi `wallet` harus dimuat dengan `withBalance()`.
 *
 * @mixin \App\Models\Goal
 */
class GoalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $wallet = $this->wallet;
        $current = (int) ($wallet?->balance ?? 0);
        $today = CarbonImmutable::parse(CarbonImmutable::now(MonthRange::TIMEZONE)->toDateString());
        $deadline = $this->deadline ? CarbonImmutable::parse($this->deadline->toDateString()) : null;

        $progress = GoalProgress::calculate($current, $this->target_amount, $deadline, $today);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'wallet_id' => $this->wallet_id,
            'wallet_name' => $wallet?->name,
            'wallet_status' => match (true) {
                $wallet === null || $wallet->trashed() => 'deleted',
                (bool) $wallet->is_archived => 'archived',
                default => 'ok',
            },
            'target_amount' => $this->target_amount,
            'current_amount' => $current,
            'remaining' => $progress['remaining'],
            'percent' => $progress['percent'],
            'status' => $progress['status'],
            'deadline' => $this->deadline?->toDateString(),
            'monthly_needed' => $progress['monthly_needed'],
        ];
    }
}
