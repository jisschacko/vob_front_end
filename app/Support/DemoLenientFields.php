<?php

namespace App\Support;

/**
 * Demo mode (VOB_SKIP_THIRD_PARTY): PAN, GST number, account name / number and IFSC are not validated and are always shown as verified.
 *
 * VOB itself never format-checks them. The Admin does, though, and rejects a whole draft over a bad PAN. In demo mode VOB therefore
 * remembers what the vendor typed for these fields (in the server-side session), and when the Admin refuses only these fields it saves
 * the rest of the form without them. Never active in production (EnforcementSwitches::skipThirdParty() is false there).
 */
class DemoLenientFields
{
    public static function active(): bool
    {
        return EnforcementSwitches::skipThirdParty();
    }

    /** @return list<string> paths such as "vendor_details.pan_no" */
    public static function paths(): array
    {
        return array_values((array) config('vob.demo_unvalidated_fields', []));
    }

    public static function is(string $path): bool
    {
        return self::active() && in_array($path, self::paths(), true);
    }

    /** Remember what was posted for these fields (a cleared field forgets its value). @param array<string,mixed> $form the draft body */
    public static function remember(array $form): void
    {
        $kept = (array) session('demo_fields', []);

        foreach (self::paths() as $path) {
            [$section, $field] = explode('.', $path, 2);
            if (! is_array($form[$section] ?? null) || ! array_key_exists($field, $form[$section])) {
                continue;
            }

            $value = $form[$section][$field];
            if ($value === null || $value === '') {
                unset($kept[$path]);
            } else {
                $kept[$path] = (string) $value;
            }
        }

        session()->put('demo_fields', $kept);
    }

    public static function override(string $path): ?string
    {
        if (! self::is($path)) {
            return null;
        }

        $value = ((array) session('demo_fields', []))[$path] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The draft body without the given fields (only the unvalidated ones can be removed; sections left empty are dropped).
     *
     * @param  array<string,mixed>  $form  the draft body
     * @param  list<string>  $paths  the fields the Admin refused
     * @return array<string,mixed>
     */
    public static function strip(array $form, array $paths): array
    {
        foreach (array_intersect($paths, self::paths()) as $path) {
            [$section, $field] = explode('.', $path, 2);
            unset($form[$section][$field]);
            if (isset($form[$section]) && $form[$section] === []) {
                unset($form[$section]);
            }
        }

        return $form;
    }

    /** True when every error is about one of these fields. @param array<string,mixed> $errors Admin 422 errors keyed by path */
    public static function onlyThese(array $errors): bool
    {
        return $errors !== [] && array_diff(array_keys($errors), self::paths()) === [];
    }

    /** @param array<string,mixed> $errors @return array<string,mixed> */
    public static function withoutThese(array $errors): array
    {
        return array_diff_key($errors, array_flip(self::paths()));
    }
}
