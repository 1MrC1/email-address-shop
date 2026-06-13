# A.Email — vanity mailbox sign-up

**🌐 Live demo:** https://1mrc1.github.io/email-address-shop/ · **Languages:** English · [简体中文](README.zh-CN.md) · [繁體中文](README.zh-TW.md)

A small PHP web app for **claiming and selling `username@a.email` email addresses**. Visitors check a
username for availability and price in real time, register, and either get a free mailbox instantly or
pay via Stripe Checkout. Mailboxes are then provisioned on a [mailcow](https://mailcow.email/) server
through its API.

> ⚠️ **Early prototype / pre-draft.** This was a quick first pass at an address-assignment flow, not a
> hardened product. Please read **[Status & known limitations](#status--known-limitations)** before
> running it anywhere real. It is published for reference and learning.

## Demo

A **frontend-only** static demo — the real landing page with the PHP API mocked client-side using dummy
data — is hosted on GitHub Pages: **https://1mrc1.github.io/email-address-shop/**

Nothing is stored and no mailbox is created. Try `bob` (free), `hi` (paid), `x` (premium), or `admin`
(blocked). The demo source is in [`docs/`](docs/).

## How it works

```
index.php ──▶ pricing.php        check username availability + price (length-based)
   │
   └─ register.php               create user + transaction
        ├─ free username  ──────▶ provision mailbox now ──▶ success.php
        └─ paid username  ──────▶ payment.php ──▶ Stripe Checkout
                                      ├─ success.php   (verifies session, provisions mailbox)
                                      └─ webhook.php   (checkout.session.completed → provisions mailbox)
```

- **Length-based pricing:** longer usernames are free; short ones are paid (configurable).
- **Forbidden words** and a **per-IP daily limit** on free accounts are enforced server-side.
- **Multi-domain:** the same codebase/DB can serve more than one mail domain (a `domain` column on
  `users` distinguishes them).

## Tech stack

- **PHP** (7.4–8.x), no framework — each top-level `.php` file is a directly-served page/endpoint.
- **MySQL** (mysqli, prepared statements).
- **Stripe Checkout** ([`stripe/stripe-php`](https://github.com/stripe/stripe-php)) for payments + webhook.
- **PHPMailer** over SMTP for transactional email.
- **mailcow** HTTP API for actual mailbox creation.

## Setup

Requirements: PHP, Composer, MySQL, a Stripe account, an SMTP account, and a reachable mailcow API.

```bash
# 1. Dependencies
composer install

# 2. Configuration — copy the template and fill in real credentials
cp config.example.php config.php
#   edit config.php: DB_*, STRIPE_* keys, MAILBOX_API_KEY / MAILBOX_API_URL, SMTP settings
#   config.php is gitignored — never commit it

# 3. Database
mysql -u <user> -p <database> < db.sql

# 4. Serve (production: nginx + PHP-FPM; quick local test below)
php -S localhost:8000
```

Then point a Stripe **webhook** at `https://your-domain/webhook.php` for the
`checkout.session.completed` event and set the resulting signing secret as `STRIPE_WEBHOOK_SECRET`.

### Configuration reference (`config.php`)

| Setting | Purpose |
|---|---|
| `DB_HOST` / `DB_USER` / `DB_PASS` / `DB_NAME` | MySQL connection |
| `STRIPE_SECRET_KEY` / `STRIPE_PUBLISHABLE_KEY` / `STRIPE_WEBHOOK_SECRET` | Stripe Checkout + webhook |
| `MAILBOX_API_URL` / `MAILBOX_API_KEY` | mailcow mailbox-provisioning API |
| SMTP host / user / pass (in `sendEmailNotification`) | outbound transactional email |
| `FREE_ACCOUNT_MIN_LENGTH`, `SHORT_NAME_PRICE`, `SINGLE_CHAR_PRICE`, `DAILY_FREE_LIMIT_PER_IP` | pricing & limits |
| `$FORBIDDEN_WORDS` | usernames that are blocked |

## Project layout

```
index.php            landing page + client-side JS
pricing.php          availability + pricing API
register.php         registration API (creates user, provisions free mailboxes)
payment.php          builds Stripe Checkout session
webhook.php          Stripe webhook handler
success.php/fail.php post-checkout pages
admin.php            admin panel (login, dashboard, user/transaction management)
manage.php           per-user account page (admin-gated; manage.php?user_id=N)
config.example.php   configuration template (copy to config.php)
db.sql               database schema (tables, procedures, views)
css/ img/            static assets
```

## Admin panel

`admin.php` is a session-protected admin panel that authenticates against the `admin_users` table
(bcrypt). When no admin exists yet, it offers a one-time "create admin" form. It shows
registration/revenue stats and lets you search users and re-provision mailboxes, mark accounts
completed/canceled, or delete them. For production, put an extra access layer (IP allowlist / VPN /
HTTP auth) in front of it.

> ⚠️ **Untested:** the admin panel is newly added and has **not** been functionally tested end-to-end yet.

## Status & known limitations

This is a prototype. Known issues a reader/contributor should be aware of:

- **`ENVIRONMENT` defaults to `development`**, which disables rate limiting and CSRF checks.
- The **`monthly` plan is charged once** (Stripe `mode: payment`); true recurring subscriptions are not implemented.
- No automated tests.

## License

Released under the [MIT License](LICENSE).
