<?php

declare(strict_types=1);

namespace LaravelMail\Services;

use Illuminate\Support\Collection;
use LaravelMail\Models\Campaign;
use LaravelMail\Models\CampaignVariant;
use LaravelMail\Models\Message;

class AbTestService
{
    /** Replace a draft campaign's variants. Each: label, weight (1-100), optional subject/content. Needs 2+ (or none to clear). */
    public function define(Campaign $campaign, array $variants): void
    {
        if (! $campaign->isDraft()) {
            throw new \LaravelMail\Exceptions\InvalidCampaignStateException('Variants can only change while the campaign is a draft.');
        }
        if ($variants !== [] && count($variants) < 2) {
            throw new \InvalidArgumentException('An A/B test needs at least two variants.');
        }
        $labels = array_column($variants, 'label');
        if (count($labels) !== count(array_unique($labels))) {
            throw new \InvalidArgumentException('Variant labels must be unique.');
        }
        foreach ($variants as $v) {
            if (blank($v['label'] ?? null) || (($v['weight'] ?? 50) < 1) || (($v['weight'] ?? 50) > 100)) {
                throw new \InvalidArgumentException('Each variant needs a label and a weight from 1 to 100.');
            }
            if (blank($v['subject'] ?? null) && blank($v['content'] ?? null)) {
                throw new \InvalidArgumentException("Variant {$v['label']} must change the subject or the content.");
            }
        }

        \DB::transaction(function () use ($campaign, $variants) {
            $campaign->variants()->delete();
            foreach ($variants as $v) {
                $campaign->variants()->create(['label' => $v['label'], 'subject' => $v['subject'] ?? null, 'content' => $v['content'] ?? null, 'weight' => $v['weight'] ?? 50]);
            }
        });
    }

    /** Stable weighted pick: the same subscriber always lands in the same variant for a campaign. */
    public function assign(Campaign $campaign, int $subscriberId, ?Collection $variants = null): ?CampaignVariant
    {
        $variants ??= $campaign->variants()->orderBy('id')->get();
        if ($variants->isEmpty()) {
            return null;
        }
        $total = max(1, (int) $variants->sum('weight'));
        $point = crc32($campaign->id.':'.$subscriberId) % $total;
        $acc = 0;
        foreach ($variants as $v) {
            $acc += (int) $v->weight;
            if ($point < $acc) {
                return $v;
            }
        }

        return $variants->last();
    }

    /** @return array<int, array{variant_id:int,label:string,messages:int,sent:int,opened:int,clicked:int,open_rate:float,click_rate:float}> */
    public function results(Campaign $campaign): array
    {
        $out = [];
        foreach ($campaign->variants()->orderBy('id')->get() as $v) {
            $q = Message::where('campaign_id', $campaign->id)->where('variant_id', $v->id);
            $sent = (clone $q)->where('status', 'sent')->count();
            $opened = (clone $q)->whereNotNull('opened_at')->count();
            $clicked = (clone $q)->whereNotNull('clicked_at')->count();
            $out[] = ['variant_id' => $v->id, 'label' => $v->label, 'messages' => (clone $q)->count(), 'sent' => $sent, 'opened' => $opened,
                'clicked' => $clicked, 'open_rate' => $sent ? round($opened / $sent, 4) : 0.0, 'click_rate' => $sent ? round($clicked / $sent, 4) : 0.0];
        }

        return $out;
    }

    /** Leader by the chosen metric (open_rate|click_rate). Null if there is no data or it is a tie. Informational only; sends nothing. */
    public function leader(Campaign $campaign, string $metric = 'open_rate'): ?string
    {
        if (! in_array($metric, ['open_rate', 'click_rate'], true)) {
            throw new \InvalidArgumentException('metric must be open_rate or click_rate');
        }
        $r = collect($this->results($campaign))->filter(fn ($x) => $x['sent'] > 0)->sortByDesc($metric)->values();
        if ($r->isEmpty() || ($r->count() > 1 && $r[0][$metric] === $r[1][$metric])) {
            return null;
        }

        return $r[0]['label'];
    }
}
