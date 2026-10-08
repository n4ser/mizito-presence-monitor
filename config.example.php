<?php
declare(strict_types=1);

// Copy to config.php (gitignored). Keep OUTSIDE public_html and web-accessible folders.
return [
    'token' => 'PASTE_PRIVATE_TOKEN_HERE',
    'run_seconds' => 52, // 15..55; a 60-second cron with a short gap between runs

    // Tehran time; quiet intervals are inactive, [start, end).
    // Set [] for 24-hour monitoring (for temporary diagnostics).
    'quiet_hours' => [
        ['00:00', '07:00'],
        ['12:00', '12:30'],
    ],

    'bale_bot_token' => 'PASTE_BALE_BOT_TOKEN_HERE',
    'bale_chat_id' => 'PASTE_NUMERIC_CHAT_ID_HERE',
    'alert_after_failures' => 2,
    'alert_repeat_minutes' => 30,
];
