<?php

namespace App\Services;

class OfficialRiceSourceLibrary
{
    private const STOP_WORDS = [
        'a', 'an', 'and', 'are', 'as', 'at', 'can', 'could', 'do', 'does', 'for', 'from', 'how', 'i', 'in',
        'is', 'it', 'me', 'my', 'of', 'on', 'or', 'please', 'should', 'the', 'this', 'to', 'what', 'when',
        'where', 'which', 'with', 'would', 'you', 'your', 'rice', 'palay', 'ang', 'ano', 'ba', 'bakit',
        'gawin', 'ko', 'mga', 'na', 'ng', 'nito', 'po', 'sa', 'saan', 'ito', 'paano', 'kailan', 'ako',
        'ko', 'mo', 'si', 'ni', 'at', 'o', 'para', 'po', 'ba', 'pwede', 'maaari', 'dapat',
    ];

    /**
     * Find a small set of curated, official DA/PhilRice excerpts relevant to a question.
     * This is local retrieval, not a live web search.
     */
    public static function search(string $question, int $limit = 3): array
    {
        $queryTokens = self::tokens($question);
        if ($queryTokens === []) {
            return [];
        }

        $ranked = [];
        foreach (config('consultation_sources', []) as $source) {
            $keywordText = implode(' ', $source['keywords'] ?? []);
            $keywordTokens = self::tokens($keywordText);
            $matches = array_values(array_intersect($queryTokens, $keywordTokens));
            if ($matches === []) {
                continue;
            }

            $ranked[] = [
                'source' => $source,
                'score' => count($matches) / max(1, count($queryTokens)),
                'matches' => count($matches),
            ];
        }

        usort($ranked, static function (array $left, array $right): int {
            return [$right['matches'], $right['score']] <=> [$left['matches'], $left['score']];
        });

        return array_map(
            static fn (array $item): array => $item['source'],
            array_slice($ranked, 0, max(1, $limit))
        );
    }

    private static function tokens(string $text): array
    {
        $normalized = mb_strtolower($text, 'UTF-8');
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalized) ?? '';
        $tokens = preg_split('/\s+/u', trim($normalized), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $tokens,
            static fn (string $token): bool => mb_strlen($token, 'UTF-8') > 1
                && !in_array($token, self::STOP_WORDS, true)
        )));
    }
}
