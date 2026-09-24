# PitchRooms API

PHP 8.1+ / MySQL backend for PitchRooms. No Composer, no vendor directory —
upload the files and it runs. Deploys independently of the frontend.

```
pitchrooms-api/
  public/          document root (index.php + .htaccess)
  src/             application code — never web-reachable
    Core/          env, router, request/response, database, kernel
    Middleware/    cors, auth, role
    Controllers/   one per resource group
    Services/      business rules (auth, meetings, billing, matching…)
    Jobs/          scheduled job handlers
    Support/       jwt, ids, validation, mail, logging
  routes/api.php   every route in one file
  database/        migrations (plain .sql)
  bin/             migrate, seed, scheduler, keygen
  storage/         uploads, logs (outside the webroot)
```

---

## Quick start

```bash
cp .env.example .env
php bin/keygen.php --write          # generates JWT_SECRET + SCHEDULER_KEY
# set DB_NAME / DB_USER / DB_PASS in .env
php bin/migrate.php                 # creates the schema
php bin/seed.php                    # demo accounts + one flow per vertical
php -S 127.0.0.1:8080 -t public     # dev server
```

```bash
curl http://127.0.0.1:8080/api/v1/health
```

Demo logins (password `demo123`, admin `admin123`):
`brand@` · `agency@` · `employer@` · `employee@` · `investor@` · `startup@` ·
`admin@pitchrooms.demo`

---

## The central DNS variable

`APP_DNS` is the only thing that changes when the API moves hosts. Every
absolute URL the API emits — file links, receipt links, email buttons, the
webhook callback — is built from it.

```env
APP_DNS=https://api.pitchrooms.com     # this API
FRONTEND_DNS=https://pitchrooms.com    # the React app (also the CORS origin)
```

The frontend mirrors this with `VITE_API_DNS`. Point the two at each other and
nothing else needs editing. `FRONTEND_DNS` doubles as the allowed CORS origin —
if the app is served from more than one host, add the others to
`CORS_EXTRA_ORIGINS`.

---

## Cron runs in code, not in the hosting panel

Nothing is registered with Hostinger. Recurring work lives in
`scheduled_tasks`, one-off work in `scheduled_jobs`, and a tick can be driven
three ways — all calling the same `SchedulerService::tick()`:

| Trigger | How | When to use |
|---|---|---|
| **inline** | after every API response is flushed (`Kernel::terminate`) | the default; needs nothing external |
| **http** | `POST /api/v1/internal/scheduler/tick` with `X-Scheduler-Key` | an uptime monitor can drive it on a quiet site |
| **cli** | `php bin/scheduler.php --daemon` | a VPS or a long-running worker |

Ticks are throttled by `SCHEDULER_MIN_INTERVAL` and guarded by a database lock,
so concurrent PHP-FPM workers can never run the same job twice. Jobs retry with
backoff up to `max_attempts`, then land in `failed`.

```bash
php bin/scheduler.php --status      # queue depth, per-task last run
php bin/scheduler.php --once        # one tick
php bin/scheduler.php --daemon --interval=30
```

Registered tasks: meeting reminders (24h/1h), auto-start due rooms, advance the
live room clock, auto-close overrun rooms, expire seller passes, close expired
opportunities, nightly cleanup.

**One caveat worth knowing:** inline ticks only fire while the API is receiving
traffic. On a site with no visitors overnight, a 1-hour meeting reminder can
land late. If that matters, point an external uptime monitor at the tick
endpoint every few minutes, or run the CLI daemon.

---

## What the backend now owns

These rules were enforced in the browser and were bypassable. They are all
server-side now:

- Shortlist capped at `SHORTLIST_LIMIT` (5) per opportunity
- One proposal per seller per opportunity (409 on a repeat)
- Resume required before a candidate can apply
- Only the counterparty can accept a meeting — never the requester
- Meeting times must be in the future
- **Messaging unlocks only after a completed pitch meeting** (403 `MESSAGING_LOCKED`)
- Seller pass required to enter a room; buyers exempt
- Premium slot claims are transactional — two agencies racing for #1 produces
  one winner and one clean 409
- Winning a pitch cascades: winner accepted, others declined, opportunity closed
- Match scores are computed server-side; they decide who gets a paid pitch slot
- The live room clock is authoritative — clients render from `stageEndsAt`,
  they do not run their own countdown

---

## Live pitch room

`POST /meetings/{id}/token` mints a per-participant Jitsi JWT. Room names are
no longer guessable-and-open:

- **JaaS (8x8)** — set `JITSI_DOMAIN=8x8.vc`, `JITSI_APP_ID` (the
  `vpaas-magic-cookie-…` value), `JITSI_API_KEY` (the full key id, in the form
  `<appId>/<keyId>`) and `JITSI_PRIVATE_KEY_PATH`. Tokens are RS256, scoped to
  one room, and expire 15 minutes after the meeting ends.
- **Self-hosted Jitsi** — set `JITSI_API_KEY` to the shared secret for HS256.
- **meet.jit.si** — no auth available; the token comes back `null` and
  `secured: false`. Fine for development, not for production.

`JITSI_PRIVATE_KEY_PATH` may be absolute or relative to the project root.
Prefer relative (`storage/keys/privatekey.pk`) — an absolute Windows path
breaks the moment the API is uploaded. `storage/keys/` is outside the webroot,
denied by `.htaccess`, and gitignored.

**JaaS room names are tenant-prefixed.** The token response returns both forms:
`roomName` (`<appId>/<room>`, what the Jitsi SDK needs) and `room` (the bare
name, matching the JWT's `room` claim). The frontend must use the API's
`roomName` — deriving it locally joins the wrong room.

Misconfigurations that would only surface mid-meeting (JaaS credentials with
the domain still on `meet.jit.si`, an unreadable key, a `kid` missing its
`<appId>/` prefix) are reported in `warnings` on `/health` and on the token
response.

Entry runs a 4-step gate before a token is issued: meeting code → email OTP →
mobile OTP → camera check, plus one-device-per-participant binding.

---

## Mail and SMS — Brevo

`MAIL_DRIVER` picks the transport:

| Driver | Uses | When |
|---|---|---|
| `log` | `storage/logs/messages-*.log` | local and staging; sends nothing |
| `brevo` | Brevo SMTP relay + **SMTP key** | the normal production choice |
| `brevo_api` | Brevo HTTP API + **API key** (`xkeysib-…`) | when the host blocks outbound port 587 |
| `smtp` | any other SMTP server | self-hosted mail |
| `mail` | PHP `mail()` | last resort, unauthenticated |
| `none` | nothing | disable delivery entirely |

For `brevo`, from **Brevo → SMTP & API → SMTP**:

```env
MAIL_DRIVER=brevo
SMTP_HOST=smtp-relay.brevo.com
SMTP_PORT=587
SMTP_USER=your-brevo-login@example.com   # the "Login" value, not a sender
SMTP_PASS=<your SMTP key>                # NOT your Brevo account password
MAIL_FROM=no-reply@yourdomain.com        # must be a verified sender in Brevo
```

The SMTP client is built in (`src/Support/Smtp.php`) — STARTTLS on 587 with
certificate verification, `AUTH LOGIN`, and `multipart/alternative` bodies so
every client gets HTML or plain text. No Composer dependency.

Verify the moment the key is in place:

```bash
php bin/mailtest.php --check                    # config only, sends nothing
php bin/mailtest.php you@example.com --debug    # sends, prints the SMTP transcript
php bin/mailtest.php --sms +919876543210        # transactional SMS
```

`--debug` prints the protocol conversation with credentials redacted, so an
auth or sender-verification rejection is immediately readable.

**SMS.** The pitch room's mobile OTP needs a real sender. `SMS_DRIVER=brevo`
uses Brevo's transactional SMS with `BREVO_API_KEY` and expects recipients in
E.164 (`+919876543210`). On `log` the code is written to the log instead of
being delivered — the gate still works, but only for you.

A failed email never breaks the request that triggered it: the failure is
logged with its reason and the message body is written to
`storage/logs/messages-*.log` so nothing is lost silently.

---

## Deploying to Hostinger

1. **Subdomain** `api.yourdomain.com`, document root pointed at
   `pitchrooms-api/public`. Everything else then sits outside the webroot.
2. **Upload** the whole directory (File Manager or SFTP). Skip `.env`.
3. **Database** — create it in hPanel, then import the migrations through
   phpMyAdmin, or run `php bin/migrate.php` over SSH if available.
4. **`.env`** — copy `.env.example`, set `APP_DNS`, `FRONTEND_DNS`, the DB
   credentials, then `php bin/keygen.php --write` (or paste your own secrets).
   Set `APP_ENV=production` and `APP_DEBUG=false`.
5. **Permissions** — `storage/` must be writable (755 is usually enough).
6. **TLS** — enable the free SSL certificate and force HTTPS.
7. **Check** `https://api.yourdomain.com/api/v1/health` returns `database: up`.

The frontend deploys separately: `npm run build` in `prooms/`, upload `dist/`
to the main domain's `public_html`, and add an SPA rewrite so deep links work.

**Before going live**

- `APP_DEBUG=false` — debug mode returns stack traces to clients
- Move `PAYMENT_PROVIDER` off `manual` and set the webhook secret; a pass is
  only ever granted by a signature-verified webhook
- Switch `MAIL_DRIVER` to `brevo` (or `brevo_api`) and `SMS_DRIVER` to `brevo` —
  on `log` nothing is delivered. Verify with `php bin/mailtest.php --check`
- Verify `MAIL_FROM` as a sender in Brevo, or sends are rejected
- Confirm `/health` returns an empty `warnings` array
- Run `php bin/seed.php` only if you actually want demo data in production

---

## Conventions

**Envelope**

```json
{ "ok": true,  "data": {}, "meta": { "page": 1, "perPage": 20, "total": 134 } }
{ "ok": false, "error": { "code": "SHORTLIST_LIMIT_REACHED", "message": "…" } }
```

**Auth** — `Authorization: Bearer <accessToken>` (15 min), rotated via
`POST /auth/refresh` with a refresh token (30 days). Identity always comes from
the token; `fromId` / `agencyId` / `employerId` in a request body are ignored.

**Status codes** — 400 validation · 401 unauthenticated · 403 role or gate ·
404 · 409 duplicate · 422 business rule · 429 rate limited.

**Errors worth handling in the UI:** `MESSAGING_LOCKED`, `PASS_REQUIRED`,
`SHORTLIST_LIMIT_REACHED`, `ALREADY_APPLIED`, `SLOT_TAKEN`, `GATE_INCOMPLETE`,
`RESUME_REQUIRED`, `DEADLINE_PASSED`.

Full endpoint list: [`../prooms/docs/API_SPEC.md`](../prooms/docs/API_SPEC.md).
