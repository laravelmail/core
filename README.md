# laravelmail/laravelmail

Embeddable email-marketing engine for Laravel. First beta slice: subscribers, tags, templates, campaigns, suppression and a gated dispatcher. Private for now (no Packagist release until the owner says so).

Requires PHP 8.3+ and Laravel 12 or 13.

## Safety: installing never sends

Requiring the package does not send mail, schedule jobs, process IMAP, register routes, or change your `mail`/`auth` config. Sending is off until you set `LARAVELMAIL_SENDING_ENABLED=true`. The gate is checked both when a campaign is dispatched and again inside each queued send job. (Background: app issue #187.)

## Install

```bash
composer require laravelmail/laravelmail   # private repo: add a vcs repository entry first
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

## Not in this slice

Automations, AI, SMS, IMAP inbox processing, tracking, A/B tests, segments, UI. They stay in the hosted app and will be extracted behind their own disabled-by-default flags. See `docs/EXTRACTION.md`.

## Tests

```bash
composer install && vendor/bin/phpunit
```
