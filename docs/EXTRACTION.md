# Extraction status

Source of truth: research/laravelmail/01-package-conversion-plan.md (izdrail/research.izdrail.com, commit e4d99d1). Approach: option A (embeddable package), app stays an app, app repo untouched.

Done (slice 1): package skeleton, namespaced `LaravelMail\`, prefixed tables, workspace resolver contract, subscribers/tags/templates/campaigns/messages/suppressions, gated dispatcher + queued job, install/doctor commands, opt-in signed unsubscribe route, safety tests.

Differences from the app's schema (needs a mapping layer before the hosted app can consume the package): string status instead of `campaign_statuses` ids, `laravelmail_` table prefix, `workspace_id` as nullable string.

Phase 3 gate: tests/host-fixture/run.sh (fresh Laravel 13 host, composer require, all checks pass; found and fixed two migration rollback bugs on SQLite).

Slice 5: gateways, caps, warming plans, failover, overview (features.deliverability).

Slice 2: segments, tracking, SMTP rotation, PhoneNumber. Slice 3: MJML (optional), webhooks + bounce suppression, DNSBL, flow validator, CSV import, tags. Slice 4: package-native flow engine (journeys, 10 step types, SSRF-safe webhook), preference center, public signup with double opt-in, all flag-gated. Next: warming/gateway routing, SMS, scoring, deliverability overview, HTTP API, then the schema-dependent port.

## Reconciliation with the docs-lane map (Oct 10)

Adopted: name `laravelmail/core`, PHP ^8.4 / Laravel ^13, `features.*` flags all off, UserResolver + WorkspaceResolver, host-owned scheduling helper `LaravelMail::schedule()`, package-owned mailer (inline transport via Mail::build, no host mail/gmail config rewrite), validation client config default-disabled with no hardcoded endpoint, AI and mailbox excluded.

OPEN CONFLICT: the map wants migrations with identical filenames/schema to the app (so the app can drop its copies and `migrate` is a no-op) and a port of the SendPortal-derived Portal/router macros. Slice 1 is a clean schema (prefixed tables, string statuses). The app's `2025_01_01_000000_create_initial_schema` also bundles users/workspaces/invitations that the host must own, and `App\Models\Subscriber` references Conversation/Automation/Sms classes. A verbatim port needs a decision: (a) schema-compatible port (verbatim migrations, split out host-owned tables) or (b) keep the clean schema plus a mapping layer for the app.

## Slice 6: HTTP API
Token auth (`laravelmail_api_tokens`, hash only), ability-scoped REST over subscribers, tags, segments, templates, campaigns; `laravelmail:token` command; `features.api` flag. Not covered: API tokens UI, per-token rate limits, webhooks-out.

## Slice 7: A/B tests
Weighted, stable variant split with subject/content overrides, per-variant results and leader. Not covered: automatic holdout + winner send, statistical significance.

## Slice 8: bulk subscribers
CSV import/export (API + `laravelmail:export`), bulk tag/untag/unsubscribe/delete with dry run. Id routes now reject non-numeric ids with 404. Not covered: async import jobs for very large files, import mapping UI.

## Slice 9: IP pools + blacklist monitoring
Pools, tracked IPs, persisted DNSBL check history, listing-change event, command + API. Not covered: automatic routing by pool, IPv6, auto-pause on listing, a scheduler entry.
