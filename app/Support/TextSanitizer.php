<?php

namespace App\Support;

/**
 * Input sanitising for free-text form values (vendor name, brand, address, contact person, bank details, tax information, commercial text...).
 *
 * It removes what has no place in a business text and is the usual carrier of stored XSS: HTML / script / style / comment markup, PHP and
 * processing instructions, NUL and other control characters, and invalid UTF-8. It does NOT touch the characters real business data needs:
 * & ' " / \ - ( ) , . : ; # @ + % = ? ! * _ and a "<" or ">" that is not part of a tag ("rebate < 5%", "A -> B") all stay exactly as typed.
 *
 * Deliberately not done here: HTML-encoding (that is the job of output escaping, which Blade does on every {{ }}; encoding on input would store
 * "AT&amp;T"), and any blacklist of SQL words or quotes (SQL injection is prevented only by parameterised queries / Eloquent and never by
 * filtering, so "O'Neil" and "DROP" in a brand name are ordinary text).
 */
class TextSanitizer
{
    public static function clean(string $value): string
    {
        // invalid UTF-8 (a common filter-evasion trick) is dropped, NUL and control characters except tab / newline / carriage return too
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

        // markup can be nested to survive one pass ("<scr<script>ipt>"): repeat until nothing changes
        for ($i = 0; $i < 10; $i++) {
            $before = $value;
            $value = (string) preg_replace([
                '#<!--.*?(-->|$)#s',                                   // comments (also unterminated)
                '#<(script|style|iframe|object|embed|template)\b[^>]*>.*?(</\1\s*>|$)#is', // elements whose content is code, content included
                '#<\?.*?(\?>|$)#s',                                    // PHP blocks and XML processing instructions
                '#<![A-Za-z\[][^>]*>?#',                               // <!DOCTYPE ...>, <![CDATA[ ...
                '#</?[A-Za-z][^>]*>#',                                 // any other complete tag, with attributes
                '#</?[A-Za-z][^<>]*$#',                                // a tag cut off at the end ("<img src=x onerror=...")
            ], '', $value);

            if ($value === $before) {
                break;
            }
        }

        return $value;
    }

    /**
     * Cleans every string in a (nested) value; other types are returned as they are. Keys listed in $skip (compared in lower case) are left
     * alone at any depth, for secrets that must reach the Admin byte for byte (passwords, tokens, the encrypted sign-in field).
     *
     * @param  list<string>  $skip
     */
    public static function deep(mixed $value, array $skip = []): mixed
    {
        if (is_string($value)) {
            return self::clean($value);
        }
        if (! is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = is_string($key) && in_array(strtolower($key), $skip, true) ? $item : self::deep($item, $skip);
        }

        return $out;
    }
}
