<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * When a client shares a reference link (their current site, an example they like),
 * fetch it and distill what it is so the scope + build can take it as a base.
 */
class ReferenceFetcher
{
    /** Extract URLs from a message body. */
    public function urls(string $text): array
    {
        preg_match_all('#https?://[^\s<>"\']+#i', $text, $m);

        return array_slice(array_unique($m[0] ?? []), 0, 3);
    }

    /** Fetch a URL and return a short plain-text digest of its content (title + visible text). */
    public function digest(string $url): ?string
    {
        try {
            $r = Http::timeout(20)->withHeaders(['User-Agent' => 'Mozilla/5.0 OvercloudBot'])->get($url);
            if (! $r->successful()) {
                return null;
            }
            $html = $r->body();
            $title = preg_match('#<title[^>]*>(.*?)</title>#is', $html, $tm) ? trim(html_entity_decode($tm[1])) : '';
            // Strip scripts/styles/tags → visible text.
            $text = preg_replace('#<(script|style|noscript)[^>]*>.*?</\1>#is', ' ', $html);
            $text = preg_replace('#<[^>]+>#', ' ', (string) $text);
            $text = trim(preg_replace('/\s+/', ' ', html_entity_decode((string) $text)));

            $digest = trim(($title ? 'Título: '.$title.'. ' : '').'Contenido: '.$text);

            return $digest !== '' ? Str::limit($digest, 1500) : null;
        } catch (\Throwable $e) {
            Log::warning('ReferenceFetcher failed', ['url' => $url, 'e' => $e->getMessage()]);

            return null;
        }
    }

    /** Pull every reference URL the lead has shared and digest them into one note. */
    public function digestForLead(Lead $lead): ?string
    {
        $conv = $lead->conversations()->where('is_group', false)->latest('updated_at')->first();
        if (! $conv) {
            return null;
        }
        $bodies = $conv->messages()->where('is_from_me', false)->latest()->limit(20)->pluck('body')->filter();
        $urls = [];
        foreach ($bodies as $b) {
            $urls = array_merge($urls, $this->urls((string) $b));
        }
        $urls = array_slice(array_unique($urls), 0, 2);
        if (! $urls) {
            return null;
        }
        $parts = [];
        foreach ($urls as $u) {
            $d = $this->digest($u);
            if ($d) {
                $parts[] = "Referencia ({$u}):\n".$d;
            }
        }

        return $parts ? implode("\n\n", $parts) : null;
    }
}
