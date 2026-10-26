<?php

namespace App\Services\Tot;

/**
 * Splits clause wording (docs/API_CONTRACT.md "Terms of Trade clauses") into paragraphs for display. Paragraphs are separated by a blank
 * line; a single line break stays inside a paragraph. `{name}` markers are the number inputs of form.business_terms.<name>.
 */
class ClauseText
{
    /** Inputs a clause may embed, with their maximum (null = no maximum). */
    private const INLINE_FIELDS = ['refund_within_days' => null, 'min_shelf_life_percent' => 100];

    /**
     * @param  list<string>  $placeholders  the names the Admin lists for this clause
     * @return list<list<array{text?:string,field?:string}>> paragraphs of segments: plain text or an inline field
     */
    public static function paragraphs(string $text, array $placeholders = []): array
    {
        $allowed = array_unique(array_merge(array_keys(self::INLINE_FIELDS), $placeholders));
        $paragraphs = [];

        foreach (preg_split('/\R\s*\R/', str_replace(["\r\n", "\r"], "\n", trim($text))) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            $segments = [];
            foreach (preg_split('/(\{[a-z0-9_]+\})/', $paragraph, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                if (preg_match('/^\{([a-z0-9_]+)\}$/', $part, $m) && in_array($m[1], $allowed, true)) {
                    $segments[] = ['field' => $m[1]];
                } else {
                    $segments[] = ['text' => $part];
                }
            }
            $paragraphs[] = $segments;
        }

        return $paragraphs;
    }

    /** Maximum for an inline number field, when it has one. */
    public static function max(string $field): ?int
    {
        return self::INLINE_FIELDS[$field] ?? null;
    }

    /** The placeholders (names) that really occur in a clause text. */
    public static function fieldsIn(string $text, array $placeholders = []): array
    {
        $names = [];
        foreach (self::paragraphs($text, $placeholders) as $segments) {
            foreach ($segments as $segment) {
                if (isset($segment['field'])) {
                    $names[$segment['field']] = true;
                }
            }
        }

        return array_keys($names);
    }
}
