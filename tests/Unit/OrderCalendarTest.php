<?php

declare(strict_types=1);

use App\Support\OrderCalendar;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->calendar = new OrderCalendar('Africa/Nairobi');
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('adds one calendar month, clamped to the month end', function (string $from, string $expected) {
    expect($this->calendar->addMonths($from)->format('Y-m-d'))->toBe($expected);
})->with([
    'ordinary day' => ['2026-11-05', '2026-12-05'],
    '31 Jan to a short Feb' => ['2026-01-31', '2026-02-28'],
    '31 Jan in a leap year' => ['2028-01-31', '2028-02-29'],
    '30 Nov to 30 Dec' => ['2026-11-30', '2026-12-30'],
    '31 Dec rolls the year' => ['2026-12-31', '2027-01-31'],
]);

it('derives the Rule 6 deemed-agreed date from delivery', function () {
    expect($this->calendar->deemedAgreedOn('2026-01-31')->format('Y-m-d'))->toBe('2026-02-28');
});

it('counts the Rule 11 objection window without its first day', function () {
    expect($this->calendar->objectionDueOn('2026-11-05')->format('Y-m-d'))->toBe('2026-11-19');
});

it('counts whole calendar days between two dates', function () {
    expect($this->calendar->daysBetween('2026-11-01', '2026-11-15'))->toBe(14)
        ->and($this->calendar->daysBetween('2026-11-15', '2026-11-01'))->toBe(-14)
        ->and($this->calendar->daysBetween('2026-11-15', '2026-11-15'))->toBe(0);
});

it('uses the Nairobi calendar day, not the UTC day', function () {
    // 22:30 UTC on 4 Nov is already 01:30 on 5 Nov in Nairobi (UTC+3).
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-04 22:30:00', 'UTC'));

    expect($this->calendar->todayString())->toBe('2026-11-05');
});

it('converts an instant to its Nairobi day', function () {
    $instant = CarbonImmutable::parse('2026-11-04 21:00:00', 'UTC'); // 00:00 on the 5th in Nairobi

    expect($this->calendar->format($instant))->toBe('2026-11-05');
});

it('does not treat a deadline falling today as passed', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-05 09:00:00', 'Africa/Nairobi'));

    expect($this->calendar->hasPassed('2026-11-05'))->toBeFalse()
        ->and($this->calendar->hasPassed('2026-11-04'))->toBeTrue()
        ->and($this->calendar->daysUntil('2026-11-05'))->toBe(0)
        ->and($this->calendar->daysUntil('2026-11-08'))->toBe(3)
        ->and($this->calendar->daysSince('2026-11-01'))->toBe(4)
        ->and($this->calendar->isFuture('2026-11-06'))->toBeTrue();
});