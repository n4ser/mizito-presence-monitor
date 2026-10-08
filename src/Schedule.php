<?php
declare(strict_types=1);

/** Repeated quiet intervals in Asia/Tehran. Intervals are [start, end). */
final class Schedule
{
    /**
     * @param list<array{0:string,1:string}> $quietHours
     * Returns the next quiet period's start, or null when currently quiet.
     */
    public static function activeUntil(DateTimeImmutable $now, array $quietHours): ?DateTimeImmutable
    {
        if ($quietHours === []) {
            return $now->modify('+1 day');
        }

        $today = $now->setTime(0, 0);
        $intervals = [];
        // Yesterday handles quiet periods that cross midnight.
        // Tomorrow handles the next scheduled stop.
        foreach ([-1, 0, 1, 2] as $dayOffset) {
            $day = $today->modify(($dayOffset >= 0 ? '+' : '') . $dayOffset . ' days');
            foreach ($quietHours as $range) {
                if (!is_array($range) || count($range) !== 2) {
                    throw new InvalidArgumentException('Invalid quiet-hours interval');
                }
                [$start, $end] = $range;
                $startMins = self::minutes($start);
                $endMins = self::minutes($end);
                if ($startMins === $endMins) {
                    throw new InvalidArgumentException('A zero-length quiet interval is not allowed');
                }
                $startsAt = $day->modify('+' . $startMins . ' minutes');
                $endsAt = $day->modify('+' . $endMins . ' minutes');
                if ($endMins < $startMins) {
                    $endsAt = $endsAt->modify('+1 day');
                }
                $intervals[] = [$startsAt, $endsAt];
            }
        }

        $nextStop = null;
        foreach ($intervals as [$start, $end]) {
            if ($now >= $start && $now < $end) {
                return null;
            }
            if ($start > $now && ($nextStop === null || $start < $nextStop)) {
                $nextStop = $start;
            }
        }
        return $nextStop ?? $now->modify('+1 day');
    }

    private static function minutes(mixed $value): int
    {
        if (!is_string($value) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $value)) {
            throw new InvalidArgumentException('Quiet hours must use HH:MM (00:00-23:59)');
        }
        [$hour, $minute] = array_map('intval', explode(':', $value));
        return $hour * 60 + $minute;
    }
}
