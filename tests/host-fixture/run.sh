#!/usr/bin/env bash
# Fresh-host integration check: a real Laravel app requires this package via composer and we verify
# discovery, publish, migrate, zero side effects on install, route/config caching and rollback.
# Usage: tests/host-fixture/run.sh [workdir]
set -euo pipefail
PKG="$(cd "$(dirname "$0")/../.." && pwd)"
WORK="${1:-$(mktemp -d)}"
PHP="${PHP:-php}"
COMPOSER="${COMPOSER:-composer}"
fail() { echo "FAIL: $*" >&2; exit 1; }
ok() { echo "ok   $*"; }

cd "$WORK"
if [ ! -d app ]; then $COMPOSER create-project laravel/laravel app --no-interaction --prefer-dist -q; fi
cd app
rm -f database/database.sqlite; touch database/database.sqlite; for f in "$PKG"/database/migrations/*.php; do rm -f "database/migrations/$(basename "$f")"; done; rm -f config/laravelmail.php
export DB_CONNECTION=sqlite DB_DATABASE="$PWD/database/database.sqlite" MAIL_MAILER=array QUEUE_CONNECTION=database CACHE_STORE=array SESSION_DRIVER=array

$COMPOSER config repositories.laravelmail "{\"type\":\"path\",\"url\":\"$PKG\",\"options\":{\"symlink\":true}}" --no-interaction
$COMPOSER require laravelmail/core:@dev --no-interaction -q -W
ok "composer require resolved"

$PHP artisan package:discover 2>&1 | grep -q 'laravelmail/core' || fail "provider not auto-discovered"
ok "service provider auto-discovered"

# Install alone: no routes, nothing scheduled
$PHP artisan route:list --json | grep -qi laravelmail && fail "routes registered on install"
$PHP artisan schedule:list 2>&1 | grep -qi laravelmail && fail "scheduled task registered on install"
ok "no routes, no schedule after install"

$PHP artisan laravelmail:install >/dev/null
ls database/migrations | grep -q 'create_laravelmail_tables' || fail "migrations not published"
test -f config/laravelmail.php || fail "config not published"
ok "config and migrations published"

$PHP artisan migrate --force >/dev/null
$PHP artisan laravelmail:doctor | grep -q 'disabled (safe default)' || fail "doctor"
ok "migrated, doctor reports sending disabled"

# Behaviour inside the real host app
cat > /tmp/laravelmail-host-check.php <<'PHPEOF'
<?php
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use LaravelMail\Models\{Subscriber, Campaign};
use LaravelMail\Services\CampaignDispatcher;
use LaravelMail\Exceptions\SendingDisabledException;
use Illuminate\Support\Facades\{Mail, DB, Schema};

Subscriber::create(['email' => 'a@example.com']);
$c = Campaign::create(['name' => 'x', 'subject' => 's', 'content' => 'b', 'send_to_all' => true]);
assert(count(app(CampaignDispatcher::class)->preview($c)) === 1, 'preview');
try { app(CampaignDispatcher::class)->dispatch($c); fwrite(STDERR, "dispatch not refused\n"); exit(1); } catch (SendingDisabledException) {}
assert($c->fresh()->status === 'draft', 'status');
assert(DB::table('laravelmail_messages')->count() === 0, 'messages');
assert(Schema::hasTable('jobs') ? DB::table('jobs')->count() === 0 : true, 'jobs');
assert(config('mail.default') === 'array', 'host mail config untouched');
echo "host-check-ok\n";
PHPEOF
$PHP -d zend.assertions=1 -d assert.exception=1 /tmp/laravelmail-host-check.php | grep -q host-check-ok || fail "in-host behaviour check"
ok "subscriber + draft work; dispatch refused; no messages or jobs created; host mail config untouched"

$PHP artisan config:cache >/dev/null && $PHP artisan route:cache >/dev/null || fail "config/route cache"
ok "config:cache and route:cache succeed"
$PHP artisan config:clear >/dev/null; $PHP artisan route:clear >/dev/null

# Opt-in routes appear only when enabled and never collide
LARAVELMAIL_ROUTES_ENABLED=true LARAVELMAIL_TRACKING_ENABLED=true LARAVELMAIL_FORMS_ENABLED=true LARAVELMAIL_WEBHOOKS_ENABLED=true \
  $PHP artisan route:list --json > /tmp/routes.json
grep -q 'laravelmail.unsubscribe' /tmp/routes.json || fail "opt-in routes missing"
LARAVELMAIL_ROUTES_ENABLED=true LARAVELMAIL_TRACKING_ENABLED=true LARAVELMAIL_FORMS_ENABLED=true LARAVELMAIL_WEBHOOKS_ENABLED=true \
  $PHP artisan route:cache >/dev/null || fail "route:cache with all routes enabled (duplicate names?)"
$PHP artisan route:clear >/dev/null
ok "opt-in routes register and cache cleanly"

# Uninstall path
$PHP artisan migrate:rollback --force >/dev/null
$PHP artisan laravelmail:doctor >/dev/null 2>&1 && fail "tables still present after rollback"
ok "rollback removes package tables"
echo "ALL HOST CHECKS PASSED"
