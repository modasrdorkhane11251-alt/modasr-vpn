<div align="center">

# MODASR Bot
### Automated VPN service sales platform — from payment to config delivery

**One sales engine, three messengers, one database**

[![Telegram](https://img.shields.io/badge/Telegram-@modasrpanel-2CA5E0?style=flat-square&logo=telegram&logoColor=white)](https://t.me/modasrpanel)
[![Rubika](https://img.shields.io/badge/Rubika-@modasrpanel-FF6B35?style=flat-square)](https://rubika.ir/joing/BBIHBDHFD0NAYIDLGBSQYPOZBROKCTBQ)
[![Bale](https://img.shields.io/badge/Bale-@modasrpanel-1F8A70?style=flat-square)](https://ble.ir/modasrpanel)
[![PHP](https://img.shields.io/badge/PHP-8.1+-777BB4?style=flat-square&logo=php&logoColor=white)]()

[فارسی](README.md) · [English](README.en.md) · [Русский](README.ru.md) · [中文](README.zh.md)

</div>

---

## Overview

MODASR Bot is an end-to-end sales engine for panel-based subscription services (Marzban and compatible panels). The complete sales cycle — plan browsing, location selection, payment, automatic account provisioning on the panel, and config delivery — runs with zero human intervention.

Design principles:

1. **One database, any number of frontends** — a Rubika user and a Telegram user share the same tables; a service bought on one platform is manageable on the others.
2. **Full transport abstraction** — business logic never talks to a messenger API directly; the `platform/` layer translates every request.
3. **Real operability** — monitoring crons, automated backups, structured reporting, and a one-command installer.

## Architecture

```
   Inbound webhook (index.php?platform=telegram|bale|rubika)
                        │
        Normalization layer (platform/)  →  standard Telegram update shape
                        │
        Business core (index.php / admin.php)
                        │
      ┌─────────────────┼──────────────────┐
      │                 │                  │
  MySQL (shared)   Panel drivers      Reporting engine
                   (Marzban, …)      (Telegram forum topics)
```

Outbound messages are routed by the user's origin platform; administrative reports always go to Telegram regardless of where the event happened.

## Features

- **Sales** — categorized catalog, locations, volume/duration plans, test accounts, one-tap renewal, subscription links, per-tier pricing (user / agent / pro agent), discount codes
- **Finance** — built-in wallet shared across platforms, card-to-card with receipt review queue (receipt photos forwarded even from Rubika to the Telegram admin), online gateways (ZarinPal et al.), agent commission settlement
- **Operations** — Telegram forum-topic report group with **10 auto-created topics** and per-event platform tags, panel health monitoring, expiry warnings, nightly database backups, web admin panel, Telegram mini-app

## Requirements

| Component | Minimum | Recommended |
|---|---|---|
| Server | 1 vCPU / 1 GB | 2 vCPU / 4 GB |
| OS | Ubuntu 20.04 | Ubuntu 24.04 |
| PHP | 8.1 | 8.2+ |
| Database | MySQL 5.7 / MariaDB 10.3 | MySQL 8 |
| Domain | A record → server IP | — |

## Installation

```bash
bash <(curl -Ls https://raw.githubusercontent.com/modasrdorkhane11251-alt/modasr-vpn/main/install.sh)
```

The installer interactively asks for your domain, bot tokens (Telegram required; Bale/Rubika optional), and admin ID — then provisions TLS, registers and validates all webhooks (including both Rubika endpoints), and installs the `modasr` server management command:

```bash
modasr install    # install / reinstall
modasr backup     # database backup to admin
modasr update     # update from repository
```

## Configuration

`config.php` lives only on the server; the repository ships placeholders:

```php
$APIKEY = '...';                 // primary bot token
$PLATFORM_TOKENS = [
    'telegram' => '...',
    'bale'     => '...',
    'rubika'   => '...',
];
$REPORTS_BOT_TOKEN = '...';      // reports bot (defaults to the Telegram token)
```

## Multi-platform layer

Every inbound update is normalized to the standard Telegram update shape before reaching the core:

- **Bale** — Telegram-compatible API; passthrough.
- **Rubika** — full translation: text messages, inline keypads (button_id → callback_data), attachments (`file` → photo/document with resolved CDN download URLs), GUID mapping (`u0…` → `b0…` persisted), and reliable message editing (delete + resend to defeat the Rubika client-side keypad cache).

The `platform` column on each user drives outbound routing for replies and cron notifications.

## Topic-based report group

1. Create a Telegram supergroup with **Topics** enabled; make the bot an admin.
2. In the bot: `/panel` → «📣 Reports» → «Select report group» → send the group ID.
3. The bot auto-creates 10 topics: purchases, payments, errors, test accounts, commissions, nightly reports, cron, backups, and more.

Every report carries its origin tag: `📱 Platform: Telegram | Bale | Rubika`

## Project structure

```
modasr-vpn/
├── index.php            # bot core — user flows
├── admin.php            # in-bot admin panel
├── botapi.php           # API layer + outbound platform routing
├── platform/            # multi-platform abstraction
├── panels/              # service panel drivers
├── cronbot/             # cron scheduler and jobs
├── lang/                # fa · en · ru · zh
├── panel/               # web admin panel
├── app/                 # Telegram mini-app
└── install.sh           # installer + server management tool
```

## Security

- Tokens live only in the server-side `config.php`; the repository is secret-free.
- Telegram webhook IP validation with Cloudflare support (`CF-Connecting-IP`) and a webhook secret.
- If any token leaks: revoke it, then update `config.php`.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Rubika unresponsive | endpoint not registered | re-run `modasr install` |
| Stale Rubika buttons | client-side cache | built-in delete/resend behavior — keep code updated |
| Slow checkout | service panel offline | check panel health |
| Telegram webhook rejected | IP/proxy | check `getWebhookInfo` and Cloudflare config |

---

<div align="center">
<b>MODASR</b> · Official channels:
<a href="https://t.me/modasrpanel">Telegram</a> ·
<a href="https://ble.ir/modasrpanel">Bale</a> ·
<a href="https://rubika.ir/joing/BBIHBDHFD0NAYIDLGBSQYPOZBROKCTBQ">Rubika</a>
</div>
