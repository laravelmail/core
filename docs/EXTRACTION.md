# Extraction status

Source of truth: research/laravelmail/01-package-conversion-plan.md (izdrail/research.izdrail.com, commit e4d99d1). Approach: option A (embeddable package), app stays an app, app repo untouched.

Done (slice 1): package skeleton, namespaced `LaravelMail\`, prefixed tables, workspace resolver contract, subscribers/tags/templates/campaigns/messages/suppressions, gated dispatcher + queued job, install/doctor commands, opt-in signed unsubscribe route, safety tests.

Differences from the app's schema (needs a mapping layer before the hosted app can consume the package): string status instead of `campaign_statuses` ids, `laravelmail_` table prefix, `workspace_id` as nullable string.

Next candidates: segments, suppression import, tracking pixel/click routes (opt-in), SMTP rotation, then AI/automations behind flags.
