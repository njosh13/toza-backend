<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The ONE place that does legal-deadline arithmetic. Business dates are
 * Africa/Nairobi calendar days (UTC+3, no DST); every method returns a
 * CarbonImmutable at 00:00 Nairobi time and every date goes out as `Y-m-d`.
 *
 *  - "One month after D" is the same day next calendar month, clamped to the
 *    month end (31 Jan -> 28/29 Feb). Contract Q1: an advocate must confirm.
 *  - A deadline that falls today has NOT yet passed (see hasPassed()).
 *  - A "within N days" window does not count its first day (Rule 11).
 */
final class OrderCalendar
{
    public function __construct(private readonly string $timezone = 'Africa/Nairobi') {}

    public static function make(): self
    {
        return new self((string) config('toza.timezone', 'Africa/Nairobi'));
    }

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone);
    }

    public function today(): CarbonImmutable
    {
        return $this->now()->startOfDay();
    }

    /** A `Y-m-d` string or any date/instant as a Nairobi calendar day. */
    public function day(string|DateTimeInterface $date): CarbonImmutable
    {
        if ($date instanceof DateTimeInterface) {
            return CarbonImmutable::instance($date)->setTimezone($this->timezone)->startOfDay();
        }

        return CarbonImmutable::parse($date, $this->timezone)->startOfDay();
    }

    public function format(string|DateTimeInterface $date): string
    {
        return $this->day($date)->format('Y-m-d');
    }

    public function todayString(): string
    {
        return $this->today()->format('Y-m-d');
    }

    public function addDays(string|DateTimeInterface $date, int $days): CarbonImmutable
    {
        return $this->day($date)->addDays($days);
    }

    /** Same day next calendar month(s), clamped to the month end. */
    public function addMonths(string|DateTimeInterface $date, int $months = 1): CarbonImmutable
    {
        return $this->day($date)->addMonthsNoOverflow($months);
    }

    /** Whole calendar days from $from to $to (negative when $to is earlier). */
    public function daysBetween(string|DateTimeInterface $from, string|DateTimeInterface $to): int
    {
        return (int) $this->day($from)->diffInDays($this->day($to), false);
    }

    /** True once the Nairobi day is strictly after $date (today is not past). */
    public function hasPassed(string|DateTimeInterface $date): bool
    {
        return $this->today()->greaterThan($this->day($date));
    }

    public function isFuture(string|DateTimeInterface $date): bool
    {
        return $this->day($date)->greaterThan($this->today());
    }

    /** Whole days left until $date from today (0 on the day, negative after). */
    public function daysUntil(string|DateTimeInterface $date): int
    {
        return $this->daysBetween($this->today(), $date);
    }

    /** Whole days elapsed since $date (0 on the day). */
    public function daysSince(string|DateTimeInterface $date): int
    {
        return $this->daysBetween($date, $this->today());
    }

    /** Rule 6 / Rule 7: a bill is deemed agreed one month after delivery. */
    public function deemedAgreedOn(string|DateTimeInterface $deliveredOn): CarbonImmutable
    {
        return $this->addMonths($deliveredOn, 1);
    }

    /** Rule 11: 14 days to object; the first day is not counted. */
    public function objectionDueOn(string|DateTimeInterface $windowOpenedOn): CarbonImmutable
    {
        return $this->addDays($windowOpenedOn, 14);
    }
}