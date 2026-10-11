<?php

namespace LaravelMail\Tests\Feature;

use LaravelMail\Models\{Campaign, Message, Segment, Subscriber, Tag};
use LaravelMail\Services\CampaignDispatcher;
use LaravelMail\Services\Segments\SegmentRuleCompiler;
use LaravelMail\Services\Segments\SegmentRuleException;
use LaravelMail\Tests\TestCase;

class SegmentsTest extends TestCase
{
    private function emails(array $tree): array
    {
        return app(SegmentRuleCompiler::class)->query($tree)->orderBy('email')->pluck('email')->all();
    }

    private function seedData(): void
    {
        $vip = Tag::create(['name' => 'vip']);
        Subscriber::create(['email' => 'ann@x.com', 'first_name' => 'Ann', 'meta' => ['plan' => 'pro']])->tags()->attach($vip);
        Subscriber::create(['email' => 'bob@y.com', 'first_name' => null, 'meta' => ['plan' => 'free']]);
        Subscriber::create(['email' => 'cat@x.com', 'first_name' => 'Cat']);
        Subscriber::where('email', 'cat@x.com')->first()->unsubscribe();
    }

    public function test_field_and_negative_null_handling(): void
    {
        $this->seedData();
        $this->assertSame(['ann@x.com', 'cat@x.com'], $this->emails(['match' => 'all', 'rules' => [['type' => 'field', 'field' => 'email', 'operator' => 'ends_with', 'value' => '@x.com']]]));
        // not_equals must keep NULL first_name rows
        $this->assertSame(['bob@y.com', 'cat@x.com'], $this->emails(['rules' => [['type' => 'field', 'field' => 'first_name', 'operator' => 'not_equals', 'value' => 'ann']]]));
    }

    public function test_status_tag_meta_and_nesting(): void
    {
        $this->seedData();
        $tag = Tag::first();
        $this->assertSame(['ann@x.com'], $this->emails(['rules' => [['type' => 'tag', 'operator' => 'has', 'value' => $tag->id]]]));
        $this->assertSame(['bob@y.com', 'cat@x.com'], $this->emails(['rules' => [['type' => 'tag', 'operator' => 'has_not', 'value' => $tag->id]]]));
        $this->assertSame(['ann@x.com', 'bob@y.com'], $this->emails(['rules' => [['type' => 'status', 'operator' => 'subscribed']]]));
        $this->assertSame(['ann@x.com'], $this->emails(['rules' => [['type' => 'meta', 'field' => 'plan', 'operator' => 'equals', 'value' => 'pro']]]));
        $this->assertSame(['ann@x.com', 'bob@y.com'], $this->emails(['match' => 'any', 'rules' => [
            ['match' => 'all', 'rules' => [['type' => 'tag', 'operator' => 'has', 'value' => $tag->id]]],
            ['type' => 'meta', 'field' => 'plan', 'operator' => 'equals', 'value' => 'free'],
        ]]));
        $this->assertSame(['bob@y.com', 'cat@x.com'], $this->emails(['not' => true, 'rules' => [['type' => 'tag', 'operator' => 'has', 'value' => $tag->id]]]));
    }

    public function test_segment_reference_and_cycle_guard(): void
    {
        $this->seedData();
        $a = Segment::create(['name' => 'a', 'rules' => ['rules' => [['type' => 'status', 'operator' => 'subscribed']]]]);
        $this->assertSame(['ann@x.com', 'bob@y.com'], $this->emails(['rules' => [['type' => 'segment', 'operator' => 'in', 'value' => $a->id]]]));
        $a->update(['rules' => ['rules' => [['type' => 'segment', 'operator' => 'in', 'value' => $a->id]]]]);
        $this->expectException(SegmentRuleException::class);
        $this->emails(['rules' => [['type' => 'segment', 'operator' => 'in', 'value' => $a->id]]]);
    }

    public function test_rejects_bad_input(): void
    {
        $this->expectException(SegmentRuleException::class);
        $this->emails(['rules' => [['type' => 'meta', 'field' => "x'; DROP", 'operator' => 'exists']]]);
    }

    public function test_campaign_targets_segment_and_excludes_segment(): void
    {
        $this->seedData();
        $target = Segment::create(['name' => 't', 'rules' => ['rules' => [['type' => 'status', 'operator' => 'subscribed']]]]);
        $excl = Segment::create(['name' => 'e', 'rules' => ['rules' => [['type' => 'meta', 'field' => 'plan', 'operator' => 'equals', 'value' => 'free']]]]);
        $c = Campaign::create(['name' => 'c', 'subject' => 's', 'content' => 'b']);
        $c->segments()->attach($target->id, ['excluded' => false]);
        $c->excludedSegments()->attach($excl->id, ['excluded' => true]);
        $this->assertSame(['ann@x.com'], app(CampaignDispatcher::class)->preview($c)->pluck('email')->all());
    }

    public function test_contains_treats_percent_and_underscore_literally(): void
    {
        Subscriber::create(['email' => 'a@example.com', 'first_name' => '100% Ann']);
        Subscriber::create(['email' => 'b@example.com', 'first_name' => '1000 Bob']);
        Subscriber::create(['email' => 'c@example.com', 'first_name' => 'a_b']);
        Subscriber::create(['email' => 'd@example.com', 'first_name' => 'axb']);
        $c = app(\LaravelMail\Services\Segments\SegmentRuleCompiler::class);
        $q = fn ($v) => $c->query(['match' => 'all', 'rules' => [['type' => 'field', 'field' => 'first_name', 'operator' => 'contains', 'value' => $v]]])->pluck('email')->all();
        $this->assertSame(['a@example.com'], $q('0%'));
        $this->assertSame(['c@example.com'], $q('a_b'));
    }
}
