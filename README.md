# Mizito Presence Monitor — PHP Socket.IO Cron & Bale Alerts

![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php) ![License: MIT](https://img.shields.io/badge/license-MIT-blue) ![Dependencies: 0](https://img.shields.io/badge/runtime_dependencies-0-success)

**A lightweight, dependency-free PHP 8.1+ WebSocket client for Mizito, built for cPanel shared hosting.** Schedule connection attempts in Tehran time, track transport health, and receive incident/recovery notifications via a Bale Messenger bot—without Node.js, Docker, or a VPS.

[راهنمای فارسی و نصب سریع](README.fa.md) · [Configuration](config.example.php) · [Security](#security--privacy) · [Limitations](#important-limitations)

> **Unofficial community project.** Not developed or endorsed by Mizito or Bale. The WebSocket protocol behavior is reverse-engineered and may change. Follow your provider's usage policies. Connecting a socket **does not prove that Mizito considers the account authenticated or displays a green online indicator**.

## Features

- **cPanel + PHP + Cron:** one scheduled job; no Composer packages required.
- **Socket.IO / Engine.IO over secure WebSocket:** TLS verification, client-side masked frames, PING/PONG and reconnect attempts within each run.
- **Configurable quiet hours:** Tehran timezone by default; 00:00–07:00 and 12:00–12:30 are excluded. Cross-midnight quiet intervals supported.
- **Bale bot alerts:** first alert after *N* consecutive failures; repeat every 30 minutes; recovery notice immediately after a healthy run.
- **Low-noise diagnostics:** token contents, messages, chat data, and remote payloads are never written to project logs.
- **Safe shared-host defaults:** CLI-only entrypoints, lock to prevent overlapping runs, private runtime state and log rotation.
- **Fast start:** a single `config.php` + one Cron Job.

## Requirements

PHP **8.1+** with OpenSSL and `stream_socket_client`; an outbound TLS connection to `service.mizito.ir:443`; cPanel Cron Jobs or any compatible scheduler; a Mizito session token you are authorized to use. To send notifications, you also need a Bale bot token and numeric chat ID. A shared host may kill long-running PHP CLI jobs—ask your provider if 52-second Cron runs are permitted.

## Quick start on cPanel

1. On GitHub, select **Code → Download ZIP** (or clone this repository). Upload its contents into a **private directory outside `public_html` and outside every addon/subdomain document root**, such as `/home/CPANEL_USER/mizito-private/`.
2. Copy `config.example.php` to `config.php` and edit the latter with your **own** Mizito token, Bale bot token and numeric chat ID. Never commit or paste tokens into issues. The example configuration has the requested Tehran schedule.
3. In Bale, open the bot chat and send `/start` once. Optionally run the [Bale test script](#optional-one-time-tests).
4. Add **one** cPanel Cron Job: **Once Per Minute** (`* * * * *`). In the Command field, use the *path appropriate to your host*:

   ```bash
   /usr/local/bin/php /home/CPANEL_USER/mizito-private/presence.php >> /home/CPANEL_USER/mizito-private/cron-error.log 2>&1
   ```

5. Inspect private logs at `runtime/presence.log` and `cron-error.log`. For a connectivity-only check, use `scripts/probe.php` as described below.

**Upgrading from V3:** back up the existing `config.php`, then deploy the refactored files. The Cron command still points to `presence.php`. Existing tokens are retained. Add `quiet_hours` to `config.php` to customize scheduling. Logs/alert state are now stored in `runtime/`; the older root-level `alert-state.json` is intentionally **not** imported, so the first incident can produce a new alert.

## Configuration

Edit `config.php` (copied from [config.example.php](config.example.php)):

```php
'run_seconds' => 52,
'quiet_hours' => [
    ['00:00', '07:00'],
    ['12:00', '12:30'],
],
'alert_after_failures' => 2,
'alert_repeat_minutes' => 30,
```

`quiet_hours` defines **inactive** intervals in Tehran time. Set it to `[]` for a temporary 24/7 test, not for a planned break. A Cron run already in progress stops before the upcoming quiet period. Time settings are based on `Asia/Tehran`, regardless of cPanel server timezone. The `run_seconds` setting is clamped between 15 and 55.

## Optional one-time tests

Run on the server via SSH/Terminal if available. Otherwise make a **temporary** `Once Per Minute` Cron Job for the command and delete it as soon as the result is collected:

```bash
# Checks outbound WebSocket and Engine.IO without any account token
/usr/local/bin/php /home/CPANEL_USER/mizito-private/scripts/probe.php

# Sends one Bale test message; writes a runtime/bale-test.done marker to prevent repeats
/usr/local/bin/php /home/CPANEL_USER/mizito-private/scripts/test-bale.php
```

To re-test Bale, remove only `runtime/bale-test.done` and run the test once again. Never keep test Cron Jobs permanently.

## Alert behavior

| Condition | Behavior |
| --- | --- |
| Failed connection attempts | Bale warning after 2 consecutive failed Cron runs (configurable) |
| Continued same incident | Reminder at most once every 30 minutes (configurable) |
| Socket.IO `terminated` / connect error | "Possible session rejection" warning; **not proof of token expiry** |
| Transport becomes stable again | Recovery notice, without an extra 3-minute delay |
| Quiet hours | No connection attempt or routine notification |
| Entire host / Cron dies | **No Bale alert from this host**; external uptime monitoring is required |

A session token may expire or be revoked. There is **no automatic refresh** in this project because no supported refresh contract has been verified. It is safer to rotate the token manually than to automate a credential flow that might expose your account.

## Run offline tests

```bash
php tests/run.php
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

GitHub Actions tests PHP 8.1–8.3 for syntax and offline behavior. Tests do not use real credentials or contact Mizito/Bale.

## Security & privacy

- Keep **all code and `config.php` outside your web roots**. All entrypoints also reject HTTP requests, but directory isolation is essential.
- `config.php`, `runtime/`, `*.log`, and temporary state files are excluded by `.gitignore`. Confirm your `git status` before publishing.
- Treat Mizito session tokens and Bale bot tokens as passwords. If one leaks, revoke/rotate it immediately.
- WebSocket `authenticate` is sent using your session token. The code does not read or log full incoming chat messages, user IDs, or API responses.
- The bot token is used only for requests to Bale's Bot API over HTTPS; message bodies include status and timestamps, not session credentials.
- Avoid running an almost-continuous PHP process without permission on shared hosting. A 1-minute Cron with a 52-second job may violate provider policies or consume process quotas.

## Important limitations

**Transport ≠ authenticated presence.** A successful TLS/WebSocket/Engine.IO handshake and a sent Socket.IO `authenticate` event do not establish that the server accepted the token or that teammates see you as online. Test the actual status from a second authorized account. A malformed token may not trigger a clear authentication error; you may therefore see a "stable transport" log without being online.

The 52-second Cron run ends before the next scheduled invocation, creating a short **gap** between connections. This architecture is best-effort, not a 24/7 uninterrupted session. Background scheduling can also be constrained or suspended by your host. Neither browser activity nor a permanent user presence can be guaranteed by this project.

## Project layout

```text
presence.php              # main CLI cron entrypoint
config.example.php        # safe template; copy to config.php
src/                      # Socket.IO transport, scheduling, alerts, Bale notification
scripts/probe.php         # WebSocket connectivity test
scripts/test-bale.php     # one-shot Bale delivery test
tests/run.php              # offline unit tests
runtime/                  # created on first run; NEVER commit
README.fa.md              # Persian setup guide
```

## Contributing & license

Bug reports and pull requests are welcome. Never attach session tokens, signed URLs, or unredacted logs. Licensed under [MIT](LICENSE). This is **not an official Mizito integration**.

**Suggested GitHub topics:** `mizito`, `php`, `websocket`, `socket-io`, `cron`, `cpanel`, `bale-messenger`, `uptime-monitoring`, `shared-hosting`, `iran`.
