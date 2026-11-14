<?php

use App\Enums\RecurrenceFrequency as F;
use App\Support\RecurrenceSchedule;
use Carbon\CarbonImmutable;

function d(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date)->startOfDay();
}

it('moves a daily rule one day', function () {
    expect(RecurrenceSchedule::nextAfter(F::Daily, d('2026-11-01'), d('2026-11-01'))->toDateString())->toBe('2026-11-02');
});

it('moves a weekly rule seven days', function () {
    expect(RecurrenceSchedule::nextAfter(F::Weekly, d('2026-11-02'), d('2026-11-02'))->toDateString())->toBe('2026-11-09');
});

it('clamps monthly day 31 to month end without drifting', function () {
    $start = d('2026-01-31');
    $feb = RecurrenceSchedule::nextAfter(F::Monthly, $start, $start);
    $mar = RecurrenceSchedule::nextAfter(F::Monthly, $start, $feb);

    expect($feb->toDateString())->toBe('2026-02-28')
        ->and($mar->toDateString())->toBe('2026-03-31');
});

it('uses 29 February in a leap year', function () {
    $start = d('2028-01-31');

    expect(RecurrenceSchedule::nextAfter(F::Monthly, $start, $start)->toDateString())->toBe('2028-02-29');
});

it('keeps day 30 after clamping February', function () {
    $start = d('2026-01-30');
    $feb = RecurrenceSchedule::nextAfter(F::Monthly, $start, $start);
    $mar = RecurrenceSchedule::nextAfter(F::Monthly, $start, $feb);

    expect($feb->toDateString())->toBe('2026-02-28')
        ->and($mar->toDateString())->toBe('2026-03-30');
});

it('finds the first monthly occurrence on or after a date', function () {
    $start = d('2026-01-25');

    expect(RecurrenceSchedule::firstOnOrAfter(F::Monthly, $start, d('2026-11-12'))->toDateString())->toBe('2026-11-25')
        ->and(RecurrenceSchedule::firstOnOrAfter(F::Monthly, $start, d('2026-11-25'))->toDateString())->toBe('2026-11-25');
});

it('returns the start when the date is not after it', function () {
    expect(RecurrenceSchedule::firstOnOrAfter(F::Monthly, d('2026-11-25'), d('2026-11-01'))->toDateString())->toBe('2026-11-25')
        ->and(RecurrenceSchedule::firstOnOrAfter(F::Daily, d('2026-11-25'), d('2026-11-25'))->toDateString())->toBe('2026-11-25');
});

it('returns the date itself for a daily rule', function () {
    expect(RecurrenceSchedule::firstOnOrAfter(F::Daily, d('2026-01-01'), d('2026-11-12'))->toDateString())->toBe('2026-11-12');
});

it('rounds a weekly rule up to the next weekday occurrence', function () {
    // 2026-11-02 adalah hari Senin; 2026-11-12 hari Kamis.
    expect(RecurrenceSchedule::firstOnOrAfter(F::Weekly, d('2026-11-02'), d('2026-11-12'))->toDateString())->toBe('2026-11-16')
        ->and(RecurrenceSchedule::firstOnOrAfter(F::Weekly, d('2026-11-02'), d('2026-11-16'))->toDateString())->toBe('2026-11-16');
});
