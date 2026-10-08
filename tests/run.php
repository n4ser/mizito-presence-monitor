<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Schedule.php';
require_once dirname(__DIR__) . '/src/AlertState.php';
require_once dirname(__DIR__) . '/src/BaleNotifier.php';
require_once dirname(__DIR__) . '/src/MizitoSocket.php';

$failures = 0;
$passed = 0;
function check(bool $condition, string $message): void
{
    global $failures, $passed;
    $condition ? $passed++ : $failures++;
    echo ($condition ? 'PASS' : 'FAIL') . ': ' . $message . "\n";
}

$tz = new DateTimeZone('Asia/Tehran');
$quiet = [['00:00', '07:00'], ['12:00', '12:30']];
foreach ([
    '00:00' => false, '06:59' => false, '07:00' => true,
    '11:59' => true, '12:00' => false, '12:29' => false,
    '12:30' => true, '23:59' => true,
] as $time => $expected) {
    $end = Schedule::activeUntil(new DateTimeImmutable('2026-10-09 ' . $time, $tz), $quiet);
    check(($end !== null) === $expected, 'Tehran schedule at ' . $time);
}
$end = Schedule::activeUntil(new DateTimeImmutable('2026-10-09 23:59', $tz), $quiet);
check($end?->format('Y-m-d H:i') === '2026-10-10 00:00', 'schedule never overruns midnight');
$end = Schedule::activeUntil(new DateTimeImmutable('2026-10-09 11:00', $tz), $quiet);
check($end?->format('H:i') === '12:00', 'next quiet period begins at noon');
$end = Schedule::activeUntil(new DateTimeImmutable('2026-10-09 15:00', $tz), []);
check($end?->format('Y-m-d H:i') === '2026-10-10 15:00', 'empty intervals allow 24/7');
$cross = [['23:00', '01:30']];
foreach (['23:00' => false, '00:59' => false, '01:30' => true, '22:59' => true] as $time => $expected) {
    check((Schedule::activeUntil(new DateTimeImmutable('2026-10-09 ' . $time, $tz), $cross) !== null) === $expected,
        'overnight quiet interval ' . $time);
}
try {
    Schedule::activeUntil(new DateTimeImmutable('now', $tz), [['25:00','26:00']]);
    check(false, 'invalid schedule rejected');
} catch (InvalidArgumentException $e) {
    check(true, 'invalid schedule rejected');
}

$t = 2000000000;
$s = AlertState::initial();
[$s, $action] = AlertState::decide($s, false, 'socket', $t, 2, 1800);
check($action === null && $s['failures'] === 1, 'first failed run is quiet');
[$s, $action] = AlertState::decide($s, false, 'socket', $t+60, 2, 1800);
check($action === 'alert', 'second failure triggers Bale notice');
$s = AlertState::complete($s, 'alert', true, 'socket', $t+60);
[$s, $action] = AlertState::decide($s, false, 'socket', $t+120, 2, 1800);
check($action === null, 'repeated error does not flood');
[$s, $action] = AlertState::decide($s, false, 'socket', $t+1861, 2, 1800);
check($action === 'alert', '30-minute reminder');
$s = AlertState::complete($s, 'alert', true, 'socket', $t+1861);
[$s, $action] = AlertState::decide($s, true, 'socket', $t+1921, 2, 1800);
check($action === 'recovered', 'recovery notice is immediate');
$s = AlertState::complete($s, 'recovered', true, 'socket', $t+1921);
check($s['incident_open'] === false && $s['failures'] === 0, 'recovery closes incident');
[$s, $action] = AlertState::decide($s, false, 'config', $t+2000, 1, 1800);
check($action === null, 'new failure respects notification cooldown');
[$s, $action] = AlertState::decide($s, false, 'config', $t+2200, 1, 1800);
check($action === 'alert', 'new failure can alert after cooldown');
$s = AlertState::complete($s, 'alert', false, 'config', $t+2200);
check($s['incident_open'] === false, 'failed Bale send does not claim notified');
[$s, $action] = AlertState::decide($s, false, 'config', $t+2390, 1, 1800);
check($action === 'alert', 'failed Bale message is retried');

$notifier = new BaleNotifier('YOUR_BALE_BOT_TOKEN', 'x');
check(!$notifier->isConfigured(), 'invalid Bale placeholders never send');

if (function_exists('stream_socket_pair')) {
    $client = new MizitoSocket(static function (string $line): void {});
    [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, SOCK_STREAM, 0);
    $reflect = new ReflectionClass($client);
    $reflect->getProperty('socket')->setValue($client, $a);
    $packet = static function (string $payload): string {
        $len = strlen($payload);
        return chr(0x81) . ($len < 126 ? chr($len) : chr(126) . pack('n', $len)) . $payload;
    };
    $reflect->getProperty('buffer')->setValue($client, $packet('40') . $packet('42["terminated"]'));
    $terminated = false;
    try {
        $client->runUntil(microtime(true) + 2, 'TEST_FAKE_TOKEN_NOT_A_SECRET');
    } catch (RuntimeException $error) {
        $terminated = $error->getMessage() === 'MIZITO_SESSION_TERMINATED';
    }
    check($terminated && $client->wasAuthSent(), 'server termination detected after authentication send');
    $client->close();
    fclose($b);
}

echo "RESULT: {$passed} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
