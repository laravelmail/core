# Extraction status

Source of truth: research/laravelmail/01-package-conversion-plan.md (izdrail/research.izdrail.com, commit e4d99d1). Approach: option A (embeddable package), app stays an app, app repo untouched.

Done (slice 1): package skeleton, namespaced `LaravelMail\`, prefixed tables, workspace resolver contract, subscribers/tags/templates/campaigns/messages/suppressions, gated dispatcher + queued job, install/doctor commands, opt-in signed unsubscribe route, safety tests.

Differences from the app's schema (needs a mapping layer before the hosted app can consume the package): string status instead of `campaign_statuses` ids, `laravelmail_` table prefix, `workspace_id` as nullable string.

Slice 2 done: segments, tracking, SMTP rotation, PhoneNumber. Slice 3 done: MJML compiler (optional), webhook auth + bounce/complaint suppression (mailgun, postmark; sendgrid/SNS verifiers), DNSBL checker, flow graph validator, CSV import, tag service. Next: flow engine, warming/gateway routing, forms, SMS, scoring, then the schema-dependent port.

## Reconciliation with the docs-lane map (Oct 10)

Adopted: name `laravelmail/core`, PHP ^8.4 / Laravel ^13, `features.*` flags all off, UserResolver + WorkspaceResolver, host-owned scheduling helper `LaravelMail::schedule()`, package-owned mailer (inline transport via Mail::build, no host mail/gmail config rewrite), validation client config default-disabled with no hardcoded endpoint, AI and mailbox excluded.

OPEN CONFLICT: the map wants migrations with identical filenames/schema to the app (so the app can drop its copies and `migrate` is a no-op) and a port of the SendPortal-derived Portal/router macros. Slice 1 is a clean schema (prefixed tables, string statuses). The app's `2025_01_01_000000_create_initial_schema` also bundles users/workspaces/invitations that the host must own, and `App\Models\Subscriber` references Conversation/Automation/Sms classes. A verbatim port needs a decision: (a) schema-compatible port (verbatim migrations, split out host-owned tables) or (b) keep the clean schema plus a mapping layer for the app.
