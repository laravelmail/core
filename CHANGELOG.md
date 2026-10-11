# Changelog

All notable changes. Unreleased until Stefan tags; no version tags have been created.

## Unreleased

### Added
- Core: subscribers, tags, templates, campaigns, messages, suppressions; renderer with merge tags; queued sending with a safety gate (nothing sends unless `features.sending` is on and a campaign is explicitly dispatched).
- Segments: rule-tree engine with the app's condition names, plus `lead_score`.
- Tracking: open pixel and click redirects (flag-gated), per-message hashes.
- SMTP rotation, MJML compile and HTML-to-MJML, bounce/complaint webhooks (Postmark, SendGrid, SES), DNSBL checker, flow validator, CSV import, tag service.
- Automations (flow engine: wait, condition, wait-until, tags, field update, webhook, email, SMS, goal, exit), preference center, public signup forms with optional double opt-in.
- Deliverability: DB gateways, hourly/daily caps, warming plans, failover routing, overview; IP pools and persisted blacklist monitoring with a listing-change event.
- HTTP API with hashed bearer tokens and per-resource abilities (see docs/API.md); bulk import/export/actions.
- A/B tests (weighted, stable assignment, per-variant results).
- SMS: driver contract, log/null drivers, consent-gated sender, STOP handling.
- Lead scoring: rules, idempotent ledger, decay command.
- RSS: safe fetcher, GUID dedupe, draft-campaign builder.
- Commands: install, doctor, token, export, warming-advance, blacklist-check, score-decay, rss-fetch, automation run.
- CI: PHPUnit, fresh-host integration fixture, PHPStan (level 5 with baseline).

### Safety defaults
- Every feature flag is off by default; installing registers no routes, schedules nothing, sends nothing and never rewrites host mail/auth config.

### Fixed
- Segment `contains`/`starts_with`/`ends_with` and the API/bulk email filters treated `%` and `_` as wildcards; they are now literal on SQLite, MySQL and PostgreSQL.
- Unhandled operators in segment rules now raise a clear `SegmentRuleException` instead of `UnhandledMatchError`.
- Non-numeric ids on API routes return 404 instead of 500.
- Migration rollbacks on SQLite (unique index dropped before its column).

### Known gaps
- License is a `proprietary` placeholder pending the BSD-3-Clause vs MIT decision.
- Not yet run against MySQL/PostgreSQL; PHP 8.3 not run (see README requirements).
- Not extracted: store/CMS integrations, conversations, CRM deals, AI, mailbox/IMAP.
