<?php

namespace Tests\Unit;

use App\Services\TrafficResetCalendar;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TrafficResetCalendarTest extends TestCase
{
    private function at(string $date): int
    {
        return (new DateTimeImmutable($date, new DateTimeZone('Asia/Shanghai')))->getTimestamp();
    }

    public function testNovemberAdvancePreservesThePurchasedThirtyDays(): void
    {
        $calendar = new TrafficResetCalendar('Asia/Shanghai');
        $now = $this->at('2026-11-05 00:00:00');
        $start = $calendar->nextBoundary(1, $this->at('2026-12-06 00:00:00'), $now);
        $end = $calendar->nextBoundary(1, $this->at('2026-12-06 00:00:00'), $start);
        $result = $calendar->previewAdvance($now, $start, $end, $end);

        self::assertSame(86400, $result['delta']);
        self::assertSame($now, $result['start']);
        self::assertSame($this->at('2026-12-05 00:00:00'), $result['end']);
        self::assertSame($result['end'], $result['expires_at']);
        self::assertSame($end - $start, $result['end'] - $result['start']);
    }

    public function testFebruaryAdvancePreservesOriginalShortMonthAndRestoresThirtyFirst(): void
    {
        $calendar = new TrafficResetCalendar('Asia/Shanghai');
        $anchor = $this->at('2027-01-31 00:00:00');
        $now = $this->at('2027-01-30 00:00:00');
        $february = $calendar->boundaryAt($anchor, 1);
        $result = $calendar->previewAdvance($now, $anchor, $february, $calendar->boundaryAt($anchor, 3));

        self::assertSame($this->at('2027-02-28 00:00:00'), $february);
        self::assertSame($this->at('2027-02-27 00:00:00'), $result['end']);
        self::assertSame($this->at('2027-03-30 00:00:00'), $calendar->nextBoundary(1, $anchor, $result['end'], $result['delta']));
        self::assertSame($this->at('2027-04-29 00:00:00'), $result['expires_at']);
        self::assertSame($this->at('2026-12-31 00:00:00'), $calendar->boundaryAt($anchor, -1));
    }

    public function testLeapFebruaryAndAnnualLeapDayKeepOriginalAnchor(): void
    {
        $calendar = new TrafficResetCalendar('Asia/Shanghai');
        $january = $this->at('2028-01-31 00:00:00');
        $result = $calendar->previewAdvance($this->at('2028-01-30'), $january, $calendar->boundaryAt($january, 1), $calendar->boundaryAt($january, 1));
        self::assertSame($this->at('2028-02-28 00:00:00'), $result['end']);

        $leapDay = $this->at('2024-02-29 13:14:15');
        self::assertSame($this->at('2025-02-28 13:14:15'), $calendar->nextBoundary(4, $leapDay, $leapDay));
        self::assertSame($this->at('2028-02-29 13:14:15'), $calendar->nextBoundary(4, $leapDay, $this->at('2027-02-28 13:14:15')));
    }

    public function testMonthAndYearStartAreMidnightAndUseExactOffset(): void
    {
        $calendar = new TrafficResetCalendar('Asia/Shanghai');
        $anchor = $this->at('2026-01-31 18:19:20');
        $offset = 36 * 3600;
        self::assertSame($this->at('2026-11-01'), $calendar->nextBoundary(0, $anchor, $this->at('2026-10-31 23:59:59')));
        self::assertSame($this->at('2026-11-29 12:00:00'), $calendar->nextBoundary(0, $anchor, $this->at('2026-10-30 12:00:00'), $offset));
        self::assertSame($this->at('2026-12-30 12:00:00'), $calendar->nextBoundary(3, $anchor, $this->at('2026-11-05'), $offset));
        self::assertNull($calendar->nextBoundary(2, $anchor, $this->at('2026-11-05')));
    }

    public function testSecondAdvanceAccumulatesOffsetWithoutRevivingOldReset(): void
    {
        $calendar = new TrafficResetCalendar('Asia/Shanghai');
        $anchor = $this->at('2027-01-31');
        $expiry = $calendar->boundaryAt($anchor, 3);
        $first = $calendar->previewAdvance($this->at('2027-01-30'), $anchor, $calendar->boundaryAt($anchor, 1), $expiry);
        $now = $this->at('2027-02-25 12:00:00');
        $start = $calendar->nextBoundary(1, $anchor, $now, $first['delta']);
        $end = $calendar->nextBoundary(1, $anchor, $start, $first['delta']);
        $second = $calendar->previewAdvance($now, $start, $end, $first['expires_at']);
        $offset = $first['delta'] + $second['delta'];

        self::assertSame($this->at('2027-02-27'), $start);
        self::assertSame($this->at('2027-03-28 12:00:00'), $second['end']);
        self::assertSame($end - $start, $second['end'] - $now);
        self::assertSame($second['end'], $calendar->nextBoundary(1, $anchor, $this->at('2027-02-28'), $offset));
        self::assertSame($this->at('2027-04-27 12:00:00'), $second['expires_at']);
    }

    public function testRejectsIncompletePurchasedCycle(): void
    {
        $calendar = new TrafficResetCalendar('Asia/Shanghai');
        $this->expectException(InvalidArgumentException::class);
        $calendar->previewAdvance($this->at('2027-01-30'), $this->at('2027-01-31'), $this->at('2027-02-28'), $this->at('2027-02-27'));
    }

    public function testBoundaryAtKeepsWallClockAcrossDaylightSavingTime(): void
    {
        $calendar = new TrafficResetCalendar('America/New_York');
        $zone = new DateTimeZone('America/New_York');
        $january = (new DateTimeImmutable('2027-01-31 12:34:56', $zone))->getTimestamp();
        $march = (new DateTimeImmutable('2027-03-31 12:34:56', $zone))->getTimestamp();
        self::assertSame($march, $calendar->boundaryAt($january, 2));
    }

    public function testRejectsOverflowBeforeTimestampArithmetic(): void
    {
        $calendar = new TrafficResetCalendar('UTC');
        $this->expectException(InvalidArgumentException::class);
        $calendar->nextBoundary(1, 0, 1, PHP_INT_MAX);
    }

    public function testRejectsUnboundedMonthOffset(): void
    {
        $calendar = new TrafficResetCalendar('UTC');
        $this->expectException(InvalidArgumentException::class);
        $calendar->boundaryAt(0, PHP_INT_MAX);
    }
}
