<?php

use App\Support\GoalProgress;
use Carbon\CarbonImmutable;

function goalDate(?string $date): ?CarbonImmutable
{
    return $date === null ? null : CarbonImmutable::parse($date)->startOfDay();
}

dataset('goal progress cases', [
    'reached exactly' => [1_000_000, 1_000_000, null, ['remaining' => 0, 'percent' => 100, 'status' => 'reached', 'monthly_needed' => null]],
    'exceeded is clamped to 100' => [1_500_000, 1_000_000, null, ['remaining' => 0, 'percent' => 100, 'status' => 'reached', 'monthly_needed' => null]],
    'negative balance shows full remaining' => [-50_000, 1_000_000, null, ['remaining' => 1_050_000, 'percent' => 0, 'status' => 'in_progress', 'monthly_needed' => null]],
    'percent is floored' => [333_333, 1_000_000, null, ['remaining' => 666_667, 'percent' => 33, 'status' => 'in_progress', 'monthly_needed' => null]],
    'deadline this month counts as one month' => [400_000, 1_000_000, '2026-11-30', ['remaining' => 600_000, 'percent' => 40, 'status' => 'in_progress', 'monthly_needed' => 600_000]],
    'deadline across the year boundary counts two months' => [400_000, 1_000_000, '2027-01-10', ['remaining' => 600_000, 'percent' => 40, 'status' => 'in_progress', 'monthly_needed' => 300_000]],
    'monthly needed rounds up' => [0, 1_000_001, '2027-01-10', ['remaining' => 1_000_001, 'percent' => 0, 'status' => 'in_progress', 'monthly_needed' => 500_001]],
    'past deadline is overdue' => [0, 1_000_000, '2026-11-14', ['remaining' => 1_000_000, 'percent' => 0, 'status' => 'overdue', 'monthly_needed' => null]],
    'deadline today is not yet overdue' => [0, 1_000_000, '2026-11-15', ['remaining' => 1_000_000, 'percent' => 0, 'status' => 'in_progress', 'monthly_needed' => 1_000_000]],
    'reached beats overdue' => [1_000_000, 1_000_000, '2026-11-14', ['remaining' => 0, 'percent' => 100, 'status' => 'reached', 'monthly_needed' => null]],
    'huge amounts do not overflow' => [9007199254740990, 9007199254740991, null, ['remaining' => 1, 'percent' => 99, 'status' => 'in_progress', 'monthly_needed' => null]],
]);

it('calculates goal progress', function (int $current, int $target, ?string $deadline, array $expected) {
    $result = GoalProgress::calculate($current, $target, goalDate($deadline), goalDate('2026-11-15'));

    expect($result)->toBe($expected);
})->with('goal progress cases');
