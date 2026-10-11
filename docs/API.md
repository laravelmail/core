# HTTP API reference

Base: `/laravelmail/api/v1` (prefix configurable via `routes.prefix`). Off unless `LARAVELMAIL_ROUTES_ENABLED=true` and `LARAVELMAIL_API_ENABLED=true`.
Some groups also need their own flag: deliverability (`ips`, `ip-pools`), scoring (`scoring-rules`, score events), rss (`rss-feeds`), crm (`deals`), conversations (`conversations`).

## Auth

Create a token: `php artisan laravelmail:token "name" --ability=subscribers:read [--workspace=ID] [--days=N]`. The plain token (`lm_...`) is printed once; only its SHA-256 hash is stored.
Send `Authorization: Bearer <token>`. `401` = missing, wrong or expired token. `403` = the token lacks the route's ability.
The token's workspace scopes every query. Requests are throttled by `routes.api_throttle` (default 120/min).

## Conventions

JSON in and out. Validation errors are `422`. Unknown or non-numeric ids are `404`. Lists with `per_page` (1-200, default 50) are paginated Laravel-style.

## Endpoints

| Method | Path | Ability |
|---|---|---|
| POST | `/subscribers/import` | `subscribers:write` |
| GET | `/subscribers/export` | `subscribers:read` |
| POST | `/subscribers/bulk` | `subscribers:write` |
| GET | `/subscribers` | `subscribers:read` |
| POST | `/subscribers` | `subscribers:write` |
| GET | `/subscribers/{id}` | `subscribers:read` |
| PATCH | `/subscribers/{id}` | `subscribers:write` |
| DELETE | `/subscribers/{id}` | `subscribers:write` |
| POST | `/subscribers/{id}/tags` | `subscribers:write` |
| DELETE | `/subscribers/{id}/tags` | `subscribers:write` |
| GET | `/tags` | `tags:read` |
| POST | `/tags` | `tags:write` |
| DELETE | `/tags/{id}` | `tags:write` |
| GET | `/segments` | `segments:read` |
| POST | `/segments` | `segments:write` |
| GET | `/segments/{id}` | `segments:read` |
| PUT | `/segments/{id}` | `segments:write` |
| DELETE | `/segments/{id}` | `segments:write` |
| GET | `/templates` | `templates:read` |
| POST | `/templates` | `templates:write` |
| GET | `/templates/{id}` | `templates:read` |
| PUT | `/templates/{id}` | `templates:write` |
| DELETE | `/templates/{id}` | `templates:write` |
| GET | `/campaigns` | `campaigns:read` |
| POST | `/campaigns` | `campaigns:write` |
| GET | `/campaigns/{id}` | `campaigns:read` |
| PATCH | `/campaigns/{id}` | `campaigns:write` |
| DELETE | `/campaigns/{id}` | `campaigns:write` |
| PUT | `/campaigns/{id}/variants` | `campaigns:write` |
| GET | `/campaigns/{id}/variants/results` | `campaigns:read` |
| GET | `/campaigns/{id}/audience` | `campaigns:read` |
| POST | `/campaigns/{id}/dispatch` | `campaigns:send` |
| GET | `/ip-pools` | `deliverability:read` |
| POST | `/ip-pools` | `deliverability:write` |
| DELETE | `/ip-pools/{id}` | `deliverability:write` |
| GET | `/ips` | `deliverability:read` |
| POST | `/ips` | `deliverability:write` |
| PATCH | `/ips/{id}` | `deliverability:write` |
| DELETE | `/ips/{id}` | `deliverability:write` |
| POST | `/ips/{id}/check` | `deliverability:write` |
| GET | `/ips/{id}/history` | `deliverability:read` |
| GET | `/scoring-rules` | `scoring:read` |
| POST | `/scoring-rules` | `scoring:write` |
| PUT | `/scoring-rules/{id}` | `scoring:write` |
| DELETE | `/scoring-rules/{id}` | `scoring:write` |
| GET | `/subscribers/{id}/score-events` | `scoring:read` |
| POST | `/subscribers/{id}/score-events` | `scoring:write` |
| GET | `/rss-feeds` | `rss:read` |
| POST | `/rss-feeds` | `rss:write` |
| DELETE | `/rss-feeds/{id}` | `rss:write` |
| GET | `/rss-feeds/{id}/items` | `rss:read` |
| POST | `/rss-feeds/{id}/fetch` | `rss:write` |
| POST | `/rss-feeds/{id}/draft-campaign` | `rss:write` |
| GET | `/deals/summary` | `deals:read` |
| GET | `/deals` | `deals:read` |
| POST | `/deals` | `deals:write` |
| GET | `/deals/{id}` | `deals:read` |
| PATCH | `/deals/{id}` | `deals:write` |
| DELETE | `/deals/{id}` | `deals:write` |
| GET | `/conversations` | `conversations:read` |
| POST | `/conversations` | `conversations:write` |
| GET | `/conversations/{id}` | `conversations:read` |
| POST | `/conversations/{id}/replies` | `conversations:write` |
| POST | `/conversations/{id}/close` | `conversations:write` |
| POST | `/conversations/{id}/read` | `conversations:write` |

## Behaviour worth knowing

- `POST /subscribers` upserts by email. It never re-subscribes someone who unsubscribed and refuses suppressed addresses (`422`). It accepts `phone` (E.164) but cannot grant SMS consent.
- `POST /subscribers/import`: CSV as multipart `file` (10 MB) or raw `csv`; optional `tags[]`. Returns created/updated/skipped/invalid and the first 50 errors.
- `POST /subscribers/bulk`: `action` = tag|untag|unsubscribe|delete plus a selection (`ids`, `tag`, `status`, `email_contains`). An empty selection is refused. `dry_run: true` only counts.
- `GET /subscribers/export`: streamed CSV; cells starting with `= + - @` are prefixed with `'`.
- Campaigns created through the API are always drafts. Edit/delete only while draft (`409` otherwise). `PUT /campaigns/{id}/variants` sets A/B variants (2+, unique labels, weight 1-100).
- `POST /campaigns/{id}/dispatch` is the only route that can send. It needs `campaigns:send` and `features.sending` (`503` while off) and a draft with subject, content and eligible recipients (`409` otherwise).
- `POST /segments` validates the rule tree on write (`422` on a bad tree).
- `POST /rss-feeds/{id}/draft-campaign` only creates a draft (`409` if no unused items).
- `PATCH /ips/{id}` can set `active` or `paused`; `listed` is managed by checks.
- `deals`: stages lead, qualified, proposal, won, lost (same names as the app). Moving to won/lost stamps `closed_at`; moving back clears it.
- `conversations`: `POST /conversations/{id}/replies` only records the reply. `send: true` additionally needs the `conversations:send` ability, `features.sending`, and a subscribed, unsuppressed recipient; otherwise `emailed` is false.
