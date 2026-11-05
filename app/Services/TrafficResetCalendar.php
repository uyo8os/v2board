<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Calendar boundaries are calculated from the original anchor, never a clipped month. */
final class TrafficResetCalendar
{
    private const MAX_TIMESTAMP = 253402300799;

    private DateTimeZone $timezone;

    public function __construct(string $timezone)
    {
        $this->timezone = new DateTimeZone($timezone);
    }

    public function boundaryAt(int $anchorAt, int $months): int
    {
        $anchor = $this->date($anchorAt);
        // Bound arithmetic before adding a caller-supplied month count.
        if ($months < -120000 || $months > 120000) {
            throw new InvalidArgumentException('Calendar month offset is out of range.');
        }
        if ($months === 0) {
            return $anchorAt;
        }
        $monthIndex = (int) $anchor->format('Y') * 12 + (int) $anchor->format('n') - 1 + $months;
        if ($monthIndex < 12 || $monthIndex > 9999 * 12 + 11) {
            throw new InvalidArgumentException('Calendar boundary is out of range.');
        }

        $year = intdiv($monthIndex, 12);
        $month = $monthIndex % 12 + 1;
        // Noon avoids inheriting a DST adjustment while determining the month's length.
        $first = $anchor->setDate($year, $month, 1)->setTime(12, 0, 0);
        $day = min((int) $anchor->format('j'), (int) $first->format('t'));
        $boundary = $first->setDate($year, $month, $day)->setTime(
            (int) $anchor->format('G'),
            (int) $anchor->format('i'),
            (int) $anchor->format('s')
        );

        return $this->checkedTimestamp($boundary);
    }

    /**
     * Return the first actual boundary strictly after $after.
     * Methods: 0 month start, 1 monthly anchor, 2 none, 3 year start, 4 annual anchor.
     * An advance translates the original calendar by the accumulated exact seconds.
     */
    public function nextBoundary(int $method, int $anchorAt, int $after, int $offsetSeconds = 0): ?int
    {
        if (!in_array($method, [0, 1, 2, 3, 4], true)) {
            throw new InvalidArgumentException('Unknown traffic reset method.');
        }
        $anchor = $this->date($anchorAt);
        $this->date($after);
        if ($offsetSeconds < 0 || $offsetSeconds > self::MAX_TIMESTAMP - $after) {
            throw new InvalidArgumentException('Traffic reset offset is out of range.');
        }
        if ($method === 2) {
            return null;
        }

        $nominalAfter = $after + $offsetSeconds;
        $at = $this->date($nominalAfter);
        if ($method === 0) {
            $base = $at->setDate((int) $at->format('Y'), (int) $at->format('n'), 1)->setTime(0, 0, 0);
            $candidate = $this->checkedTimestamp($base->getTimestamp() > $nominalAfter
                ? $base
                : $at->setDate((int) $at->format('Y'), (int) $at->format('n') + 1, 1)->setTime(0, 0, 0));
        } elseif ($method === 3) {
            $base = $at->setDate((int) $at->format('Y'), 1, 1)->setTime(0, 0, 0);
            $candidate = $this->checkedTimestamp($base->getTimestamp() > $nominalAfter
                ? $base
                : $at->setDate((int) $at->format('Y') + 1, 1, 1)->setTime(0, 0, 0));
        } else {
            $months = ((int) $at->format('Y') - (int) $anchor->format('Y')) * 12;
            $step = $method === 4 ? 12 : 1;
            if ($method === 1) {
                $months += (int) $at->format('n') - (int) $anchor->format('n');
            }
            $candidate = $this->boundaryAt($anchorAt, $months);
            if ($candidate <= $nominalAfter) {
                $candidate = $this->boundaryAt($anchorAt, $months + $step);
            }
        }

        return $candidate - $offsetSeconds;
    }

    /** Preserve the exact length of the already purchased next complete cycle. */
    public function previewAdvance(int $now, int $start, int $end, int $expiry): array
    {
        foreach ([$now, $start, $end, $expiry] as $timestamp) {
            $this->date($timestamp);
        }
        if (!($now < $start && $start < $end && $end <= $expiry)) {
            throw new InvalidArgumentException('A complete next traffic cycle is required.');
        }
        $delta = $start - $now;

        return [
            'delta' => $delta,
            'start' => $now,
            'end' => $end - $delta,
            'expires_at' => $expiry - $delta,
        ];
    }

    private function date(int $timestamp): DateTimeImmutable
    {
        if ($timestamp < 0 || $timestamp > self::MAX_TIMESTAMP) {
            throw new InvalidArgumentException('Calendar timestamp is out of range.');
        }
        $date = (new DateTimeImmutable('@' . $timestamp))->setTimezone($this->timezone);
        if ((int) $date->format('Y') < 1 || (int) $date->format('Y') > 9999) {
            throw new InvalidArgumentException('Calendar year is out of range.');
        }

        return $date;
    }

    private function checkedTimestamp(DateTimeImmutable $date): int
    {
        $timestamp = $date->getTimestamp();
        $this->date($timestamp);

        return $timestamp;
    }
}
