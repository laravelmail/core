# laravelmail/core

Embeddable email-marketing engine for Laravel. First beta slice: subscribers, tags, templates, campaigns, suppression and a gated dispatcher. Private for now (no Packagist release until the owner says so).

Requires PHP 8.4+ and Laravel 13.

## Safety: installing never sends

Requiring the package does not send mail, schedule jobs, process IMAP, register routes, or change your `mail`/`auth` config. Sending is off until you set `LARAVELMAIL_SENDING_ENABLED=true`. The gate is checked both when a campaign is dispatched and again inside each queued send job. (Background: app issue #187.)

## Install

```bash
composer require laravelmail/core   # private repo: add a vcs repository entry first
php artisan laravelmail:install            # publishes config + migrations, enables nothing
php artisan migrate
php artisan laravelmail:doctor             # read-only check
```

Tables are prefixed `laravelmail_` (`table_prefix` in config). Migrations are published, not auto-loaded, unless you set `load_migrations` to true.

## Usage

```php
use LaravelMail\Models\{Subscriber, Tag, Template, Campaign};
use LaravelMail\Services\CampaignDispatcher;

$tag = Tag::create(['name' => 'newsletter']);
Subscriber::create(['email' => 'ann@example.com', 'first_name' => 'Ann'])->tags()->attach($tag);

$template = Template::create(['name' => 'basic', 'content' => '<main>{{content}}</main>']);
$campaign = Campaign::create([
    'name' => 'October',
    'subject' => 'Hi {{first_name}}',
    'content' => 'Hello {{first_name}}',
    'template_id' => $template->id,
]);
$campaign->tags()->attach($tag);

$dispatcher = app(CampaignDispatcher::class);
$dispatcher->preview($campaign);   // who would get it; side-effect free, works while sending is off
$dispatcher->dispatch($campaign);  // throws SendingDisabledException unless sending is enabled
```

Merge tags: `{{first_name}}`, `{{last_name}}`, `{{email}}` (HTML-escaped in bodies). Only drafts can be dispatched; a unique `(campaign, subscriber)` message row prevents double sends. Suppressed and unsubscribed addresses are skipped.

## Multi-tenancy

Implement `LaravelMail\Contracts\WorkspaceResolver` and point `laravelmail.workspace_resolver` at it. All models are then scoped to the returned id. Default is single-tenant.

## Optional routes

Set `LARAVELMAIL_ROUTES_ENABLED=true` to expose a signed unsubscribe URL (`Subscriber::unsubscribeUrl()`), under `laravelmail.routes.prefix`.

## Segments (slice 2)

Rule trees use the same JSON shape as the hosted app (`{"match":"all|any","not":false,"rules":[...]}`). Supported conditions: `field`, `meta`, `status`, `created_at`, `tag`, `segment`, `campaign_sent`, `campaign_opened`, `campaign_clicked`. Attach with `$campaign->segments()->attach($id, ['excluded' => false])` (or `excludedSegments()`); a campaign targets its tags OR segments, minus excluded segments.

```php
app(\LaravelMail\Services\Segments\SegmentRuleCompiler::class)->query($segment->rules)->get();
```

## Tracking (slice 2, opt-in)

Set `LARAVELMAIL_ROUTES_ENABLED=true` and `LARAVELMAIL_TRACKING_ENABLED=true`. Then sends get an open pixel, rewritten http(s) links (redirects only to URLs stored for that message) and a `{{unsubscribe_url}}` merge tag. Without both flags, nothing is rewritten or exposed.

## SMTP rotation (slice 2)

`laravelmail.sending.pool` takes a list of named mailers or inline transport arrays; messages round-robin across them. The pool never modifies host `mail` config.

## Import, tags (slice 3)

```php
app(\LaravelMail\Services\Subscribers\SubscriberImporter::class)->importFile($path, ['imported']);
// header: email,first_name,last_name,tags   (tags separated by ;)
```
Skips suppressed addresses, never re-subscribes someone who unsubscribed, sends nothing. `TagService` attaches/detaches tags and bulk-tags a query in chunks.

## Webhooks (slice 3, opt-in)

`LARAVELMAIL_WEBHOOKS_ENABLED=true` with `routes.enabled` exposes `POST {prefix}/webhooks/{mailgun|postmark}` on `routes.webhook_middleware` (default `api`, no CSRF). Requests must pass the provider secret (`laravelmail.webhooks.*`), otherwise 403. Hard bounces and complaints add a suppression and unsubscribe the address; soft bounces do nothing. `WebhookAuth` also verifies SendGrid and SNS signatures for hosts that wire those endpoints.

## MJML, deliverability, automations (slice 3)

- `MjmlCompiler`: optional, run `npm install` in `resources/mjml`; `compileOrPassThrough()` returns raw HTML when node/mjml is missing. `HtmlToMjml::wrap()` wraps HTML as an `mj-raw` block.
- `BlacklistChecker` + `DnsblResolver`: DNSBL lookups, log-only.
- `FlowGraphValidator`: validates automation flow graphs (dangling or self edges, loops, missing conditions, non-https webhooks). The flow engine itself is not extracted yet.

## Automations (slice 4, opt-in)

`LARAVELMAIL_AUTOMATIONS_ENABLED=true`. Flows are `automation_steps` (node_key / next_key / alt_key) with `FlowGraphValidator` for checks. Steps: wait, condition, wait_until, goal, add_tag, remove_tag, update_field, webhook (https, no private addresses, no redirects), send_email, exit. `FlowEngine::enrol()` starts a journey (active automations only, never for unsubscribed or suppressed people). `FlowEngine::tick()` processes due journeys; schedule it yourself with `LaravelMail::schedule($schedule)` or `php artisan laravelmail:automation-run`. `send_email` needs `features.sending` too; with sending off the journey parks and nothing goes out. Unsupported step types (e.g. `send_sms`) fail the journey rather than being skipped.

## Public signup and preference center (slice 4, opt-in)

`LARAVELMAIL_FORMS_ENABLED=true` with `routes.enabled`:
- `POST {prefix}/subscribe/{uuid}` for tags with `enablePublicSignup()`. Throttled, optional origin allowlist, honeypot field `website`, same answer for new and existing addresses. Double opt-in stores a pending row and sends the confirmation only when `features.sending` is on.
- Signed JSON preference center at `{prefix}/preferences/{subscriber}` (topics are tags with `preference_topic`; internal tags cannot be changed; suppressed addresses cannot resubscribe).

## Deliverability (slice 5, opt-in)

`LARAVELMAIL_DELIVERABILITY_ENABLED=true` (with `features.sending`) routes campaign sends through DB gateways (`Gateway`: a named host mailer or an inline transport, stored encrypted and hidden from serialization) with hourly/daily caps, warming plans, and priority failover. At cap, a message stays pending and the job is released for later; nothing is dropped or sent early. A paused warming plan keeps its cap. A complaint spike pauses the plan. Failovers are logged (`GatewayFailover`), `DeliverabilityOverview::snapshot()` gives a per-gateway status array, and `php artisan laravelmail:warming-advance` advances plans daily (scheduled by `LaravelMail::schedule()` only when the flag is on). With no gateways configured the plain mailer/pool is used.

## Not in this slice

Automations, AI, SMS, IMAP inbox processing, tracking, A/B tests, segments, UI. They stay in the hosted app and will be extracted behind their own disabled-by-default flags. See `docs/EXTRACTION.md`.

## Tests

`tests/host-fixture/run.sh` installs a fresh Laravel app, requires this package via composer (path repo), and checks discovery, publish, migrate, no routes/schedule/mail on install, in-host behaviour, route/config caching with every opt-in route enabled, and rollback.

```bash
composer install && vendor/bin/phpunit
```


## HTTP API (opt-in)

Off by default. Needs `LARAVELMAIL_ROUTES_ENABLED=true` and `LARAVELMAIL_API_ENABLED=true`.

```bash
php artisan laravelmail:token "crm-sync" --ability=subscribers:read --ability=subscribers:write
```

The plain token is printed once; only its SHA-256 hash is stored. Send it as `Authorization: Bearer <token>`.
Endpoints live under `/laravelmail/api/v1` (throttled by `routes.api_throttle`):

| Resource | Abilities |
|---|---|
| `subscribers` (list, create/upsert, show, patch, delete, `POST|DELETE /{id}/tags`) | `subscribers:read`, `subscribers:write` |
| `tags` (list, create, delete) | `tags:read`, `tags:write` |
| `segments` (CRUD; rule trees are validated on write) | `segments:read`, `segments:write` |
| `templates` (CRUD) | `templates:read`, `templates:write` |
| `campaigns` (CRUD, `GET /{id}/audience`) | `campaigns:read`, `campaigns:write` |
| `POST campaigns/{id}/dispatch` | `campaigns:send` (and `features.sending`) |

Safety: API-created campaigns are always drafts; only `dispatch` can send and it needs its own ability plus the sending flag;
an API write never re-subscribes an unsubscribed address and refuses suppressed ones. A token's `workspace_id` scopes every query.

## A/B tests

Give a draft campaign two or more variants (`AbTestService::define()` or `PUT /api/v1/campaigns/{id}/variants`). Each variant overrides the subject and/or content and has a weight (1-100). At dispatch every recipient is placed in a variant by a stable hash, so re-dispatch never moves anyone. `GET /api/v1/campaigns/{id}/variants/results` (or `AbTestService::results()` / `leader()`) shows sent, opens, clicks and rates per variant. Opens and clicks need `features.tracking`. The package does not auto-send a winner; that stays an explicit decision.


## Bulk subscriber management

API (`subscribers:write` / `subscribers:read`): `POST /subscribers/import` (CSV as `file` up to 10 MB or `csv`, optional `tags`), `GET /subscribers/export?tag=&status=` (streamed CSV; formula-looking cells are prefixed with `'`), `POST /subscribers/bulk` with `action` of `tag|untag|unsubscribe|delete`, a selection (`ids`, `tag`, `status`, `email_contains`; an empty selection is refused) and `dry_run` to preview the count. CLI: `php artisan laravelmail:export path.csv --tag= --status=`.


## IP pools and blacklist monitoring

Needs `features.deliverability`. Track sending IPs (optionally grouped in pools) and check them against DNSBLs (`deliverability.dnsbl_zones`, default Spamhaus ZEN, SpamCop, Barracuda). `php artisan laravelmail:blacklist-check [--ip=]` records each result as history, flips an IP between `active` and `listed`, and fires `SendingIpListingChanged` on a change so you can alert. It is monitoring only: it never changes how mail is sent, never pauses an IP for you (a `paused` IP stays paused) and is not scheduled for you; add it to your own scheduler if you want it. API (`deliverability:read|write`): `ip-pools`, `ips`, `ips/{id}/check`, `ips/{id}/history`. IPv4 only.

## SMS (opt-in)

Off by default (`features.sms`). Provider-agnostic: implement `LaravelMail\Contracts\SmsDriver` (`name()`, `send($to, $body): ?string`, throw on failure), register it under `laravelmail.sms.drivers` and select it with `LARAVELMAIL_SMS_DRIVER`. The default `log` driver delivers nothing; no vendor or endpoint is built in. `SmsSender::send($subscriber, $template)` supports `{{first_name}}`-style merge tags and only texts people with a valid E.164 number, explicit consent (`$subscriber->grantSmsConsent($phone)`, call it only when they really opted in), no opt-out, still subscribed and not suppressed. Otherwise it records a `skipped` row with the reason. Every attempt is stored in `laravelmail_sms_messages`. A "Reply STOP" line is appended by default. `SmsSender::handleInbound($from, $text)` handles STOP replies from a webhook you own. The automation `send_sms` step uses the same sender (parks while sms is off, fails visibly without consent). The subscriber API accepts `phone` but cannot grant consent.

## Lead scoring (opt-in)

Off by default (`features.scoring`). Rules (`laravelmail_scoring_rules`) award points, which may be negative, for `email_opened`, `link_clicked`, `tag_added` (any tag or one specific tag), `subscribed` or a named `custom` event; an optional `max_total` caps what one rule can give a subscriber. Every award is a ledger row (`laravelmail_score_events`) and the running total is `subscribers.score`. Awards are idempotent per rule, subscriber and reference: an email counts once however often it is opened, a link once however often it is clicked. Scores are usable in segments (`lead_score` at_least/at_most) and in automation conditions (`lead_score`, at least N). `php artisan laravelmail:score-decay --days=30 --points=5` reduces scores of inactive people (never below zero; not scheduled for you). API (`scoring:read|write`): `scoring-rules` CRUD, `GET|POST subscribers/{id}/score-events`.
