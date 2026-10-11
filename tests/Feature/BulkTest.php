<?php

namespace LaravelMail\Tests\Feature;

use Illuminate\Http\UploadedFile;
use LaravelMail\Models\ApiToken;
use LaravelMail\Models\Subscriber;
use LaravelMail\Services\Subscribers\TagService;
use LaravelMail\Tests\TestCase;

class BulkTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravelmail.routes.enabled', true);
        $app['config']->set('laravelmail.features.api', true);
    }

    private function h(): array
    {
        return ['Authorization' => 'Bearer '.ApiToken::issue('t', ['*'])[1]];
    }

    public function test_import_csv_and_export_roundtrip_with_formula_guard(): void
    {
        $h = $this->h();
        $csv = "email,first_name,last_name\na@example.com,=cmd(),X\nb@example.com,Bee,Y\nbad,Z,Z\n";
        $this->postJson('/laravelmail/api/v1/subscribers/import', ['csv' => $csv, 'tags' => ['imp']], $h)->assertOk()->assertJsonPath('created', 2)->assertJsonPath('invalid', 1);
        $this->assertSame(2, Subscriber::count());

        $file = UploadedFile::fake()->createWithContent('s.csv', "email\nc@example.com\n");
        $this->post('/laravelmail/api/v1/subscribers/import', ['file' => $file], $h + ['Accept' => 'application/json'])->assertOk()->assertJsonPath('created', 1);

        $body = $this->get('/laravelmail/api/v1/subscribers/export?tag=imp', $h)->assertOk()->streamedContent();
        $this->assertStringContainsString('email,first_name,last_name,status,tags,created_at', $body);
        $this->assertStringContainsString("'=cmd()", $body);
        $this->assertStringNotContainsString('c@example.com', $body);
        $this->getJson('/laravelmail/api/v1/subscribers/abc', $h)->assertStatus(404);
        $this->postJson('/laravelmail/api/v1/subscribers/import', [], $h)->assertStatus(422);
    }

    public function test_bulk_actions_need_a_selection_and_support_dry_run(): void
    {
        $h = $this->h();
        foreach (['a', 'b', 'c'] as $n) {
            $s = Subscriber::create(['email' => "$n@example.com"]);
            if ($n !== 'c') {
                app(TagService::class)->attach($s, ['grp']);
            }
        }
        $u = '/laravelmail/api/v1/subscribers/bulk';
        $this->postJson($u, ['action' => 'delete'], $h)->assertStatus(422); // no selection: never "everyone"
        $this->postJson($u, ['action' => 'delete', 'tag' => 'grp', 'dry_run' => true], $h)->assertJsonPath('affected', 2);
        $this->assertSame(3, Subscriber::count());
        $this->postJson($u, ['action' => 'tag', 'ids' => [3], 'tag_name' => 'grp'], $h)->assertJsonPath('affected', 1);
        $this->postJson($u, ['action' => 'untag', 'tag' => 'grp', 'tag_name' => 'grp'], $h)->assertJsonPath('affected', 3);
        $this->postJson($u, ['action' => 'unsubscribe', 'ids' => [1, 2]], $h)->assertJsonPath('affected', 2);
        $this->assertSame(2, Subscriber::where('status', 'unsubscribed')->count());
        $this->postJson($u, ['action' => 'delete', 'status' => 'unsubscribed'], $h)->assertJsonPath('affected', 2);
        $this->assertSame(1, Subscriber::count());
        $this->postJson($u, ['action' => 'nuke', 'ids' => [3]], $h)->assertStatus(422);
    }

    public function test_export_command(): void
    {
        Subscriber::create(['email' => 'z@example.com']);
        $f = tempnam(sys_get_temp_dir(), 'lm');
        $this->artisan('laravelmail:export', ['path' => $f])->assertSuccessful();
        $this->assertStringContainsString('z@example.com', file_get_contents($f));
        unlink($f);
    }

    public function test_email_contains_is_literal(): void
    {
        Subscriber::create(['email' => 'a_b@example.com']);
        Subscriber::create(['email' => 'axb@example.com']);
        $n = app(\LaravelMail\Services\Subscribers\BulkActions::class)->run('delete', ['email_contains' => 'a_b'], null, true);
        $this->assertSame(1, $n);
    }
}
