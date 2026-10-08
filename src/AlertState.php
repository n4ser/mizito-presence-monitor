<?php
declare(strict_types=1);

/** Stateless alert decision engine. It never sends network requests itself. */
final class AlertState
{
    public static function initial(): array
    {
        return [
            'failures' => 0,
            'incident_open' => false,
            'last_category' => '',
            'last_alert_at' => 0,
            'last_attempt_at' => 0,
            'last_success_at' => 0,
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: ?string} action: alert|recovered|null
     */
    public static function decide(
        array $previous, bool $healthy, string $category, int $now,
        int $threshold, int $repeatSeconds, int $retryCooldownSeconds = 180
    ): array {
        $state = array_merge(self::initial(), $previous);
        if ($healthy) {
            $state['failures'] = 0;
            $state['last_success_at'] = $now;
            // Recovery is not throttled; only retrying a FAILED recovery notice is.
            if ($state['incident_open'] &&
                ($state['last_recovery_failed_at'] ?? 0) <= $now - $retryCooldownSeconds) {
                return [$state, 'recovered'];
            }
            return [$state, null];
        }

        $state['failures'] = min(999, max(0, (int)$state['failures']) + 1);
        if ($state['failures'] < $threshold) {
            return [$state, null];
        }
        $categoryChanged = $category !== $state['last_category'];
        $needsNotice = !$state['incident_open'] || $categoryChanged ||
            $now - (int)$state['last_alert_at'] >= $repeatSeconds;
        if ($needsNotice && $now - (int)$state['last_attempt_at'] >= $retryCooldownSeconds) {
            return [$state, 'alert'];
        }
        return [$state, null];
    }

    public static function complete(array $state, string $action, bool $sent, string $category, int $now): array
    {
        $state['last_attempt_at'] = $now;
        if ($action === 'alert' && $sent) {
            $state['incident_open'] = true;
            $state['last_category'] = $category;
            $state['last_alert_at'] = $now;
        }
        if ($action === 'recovered') {
            if ($sent) {
                $state['incident_open'] = false;
                $state['last_recovery_failed_at'] = 0;
            } else {
                $state['last_recovery_failed_at'] = $now;
            }
        }
        return $state;
    }
}
