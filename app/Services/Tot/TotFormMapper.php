<?php

namespace App\Services\Tot;

/**
 * Converts between the API form (GET /tots/{id} `form`, PUT /draft body) and the flat "path => value" values the page renders.
 *
 * Paths: single `section.field`; keyed `section.KEY.field` (rebate lines use `group:kind` as KEY); list `section.INDEX.field`.
 * Pivot sections (company_codes, categories) are lists of codes and are handled by the page itself.
 */
class TotFormMapper
{
    /**
     * @param  array<string,mixed>  $form  the API `form`
     * @return array<string,mixed> nested: [section][field] / [section][key][field] / [section][i][field]
     */
    public function values(array $form): array
    {
        $values = [];

        foreach (TotLayout::sections() as $name => $def) {
            $data = $form[$name] ?? null;
            if (! is_array($data)) {
                continue;
            }

            switch ($def['kind']) {
                case 'single':
                    $values[$name] = array_map(fn ($v) => $this->toDisplay($v), $data);
                    break;
                case 'keyed':
                    foreach ($data as $row) {
                        if (is_array($row) && ($key = $this->rowKey($def, $row)) !== null) {
                            $values[$name][$key] = array_map(fn ($v) => $this->toDisplay($v), array_diff_key($row, array_flip($def['key'])));
                        }
                    }
                    break;
                case 'list':
                    foreach (array_values($data) as $i => $row) {
                        $values[$name][$i] = array_map(fn ($v) => $this->toDisplay($v), (array) $row);
                    }
                    break;
                case 'pivot':
                    $values[$name] = array_map('strval', array_values($data));
                    break;
            }
        }

        return $values;
    }

    /**
     * Build the PUT /draft `form` body from the posted page.
     *
     * @param  array<string,mixed>  $input  request('form')
     * @param  list<string>  $locked  API locked_fields (never sent)
     * @param  list<string>  $masked  paths that were shown masked; left out when the vendor did not retype them
     * @param  list<string>  $assetKeys  allowed deliverable asset codes
     * @return array{form:array<string,mixed>, keyed:array<string,list<string>>} keyed = per section, the row keys in the order sent
     *                                                                           (used to map API error indexes back to fields)
     */
    public function payload(array $input, array $locked, array $masked, array $assetKeys): array
    {
        $form = [];
        $order = [];

        foreach (TotLayout::sections() as $name => $def) {
            if ($this->isLocked($name, $locked) || ! array_key_exists($name, $input)) {
                continue;
            }
            $data = $input[$name];

            switch ($def['kind']) {
                case 'single':
                    $row = $this->fields($name, $def, (array) $data, $locked, $masked);
                    if ($row !== []) {
                        $form[$name] = $row;
                    }
                    break;

                case 'keyed':
                    $allowed = $def['keys'] === 'master:deliverable_assets' ? $assetKeys : $def['keys'];
                    $rows = [];
                    foreach ((array) $data as $key => $values) {
                        $key = (string) $key;
                        if (! in_array($key, $allowed, true) || ! is_array($values)) {
                            continue;
                        }
                        $fields = $this->fields("$name.$key", $def, $values, $locked, $masked);
                        $rows[] = $this->keyFields($def, $key) + $fields;
                        $order[$name][] = $key;
                    }
                    if ($rows !== []) {
                        $form[$name] = $rows;
                    }
                    break;

                case 'list':
                    $rows = [];
                    foreach (array_values((array) $data) as $i => $values) {
                        $row = $this->fields("$name.$i", $def, (array) $values, $locked, $masked);
                        if (array_filter($row, fn ($v) => $v !== null && $v !== false) !== []) { // blank rows are dropped
                            $rows[] = $row;
                        }
                    }
                    $form[$name] = $rows; // the list is replaced, so an empty list clears it
                    break;

                case 'pivot': // categories: {CODE: Yes|No}
                    $form[$name] = array_values(array_map('strval', array_keys(array_filter((array) $data, fn ($v) => $v === 'Yes'))));
                    break;
            }
        }

        return ['form' => $form, 'keyed' => $order];
    }

    /**
     * API error keys ("contacts.1.phone", "outlets.0.state", "vendor_details.pan_no") -> the page's field paths.
     *
     * @param  array<string,mixed>  $errors
     * @param  array<string,list<string>>  $keyed
     * @return array<string,string> path => first message
     */
    public function errorsByPath(array $errors, array $keyed): array
    {
        $out = [];
        foreach ($errors as $key => $messages) {
            $parts = explode('.', (string) $key);
            if (isset($parts[1]) && ctype_digit($parts[1]) && isset($keyed[$parts[0]][(int) $parts[1]])) {
                $parts[1] = $keyed[$parts[0]][(int) $parts[1]];
            }
            $out[implode('.', $parts)] = is_array($messages) ? (string) ($messages[0] ?? '') : (string) $messages;
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function fields(string $base, array $def, array $values, array $locked, array $masked): array
    {
        $row = [];
        foreach ($def['fields'] as $field => $type) {
            $path = "$base.$field";
            if (! array_key_exists($field, $values) || $this->isLocked($path, $locked)) {
                continue;
            }
            $value = $this->normalise($values[$field], $type);
            if ($value === null && in_array($path, $masked, true)) {
                continue; // masked value not retyped: keep what is stored
            }
            $row[$field] = $value;
        }

        return $row;
    }

    /** @return array<string,mixed> */
    private function keyFields(array $def, string $key): array
    {
        if ($def['key'] === ['group', 'kind']) {
            [$group, $kind] = explode(':', $key, 2);

            return ['group' => $group, 'kind' => $kind];
        }

        return [$def['key'][0] => ($def['int_key'] ?? false) ? (int) $key : $key];
    }

    private function rowKey(array $def, array $row): ?string
    {
        if ($def['key'] === ['group', 'kind']) {
            return isset($row['group'], $row['kind']) ? $row['group'].':'.$row['kind'] : null;
        }

        return isset($row[$def['key'][0]]) ? (string) $row[$def['key'][0]] : null;
    }

    private function normalise(mixed $value, string $type): mixed
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        return match (true) {
            $value === '' || $value === null => null,
            $type === 'bool' => in_array($value, ['1', 1, true, 'Yes', 'yes', 'true'], true),
            default => (string) $value,
        };
    }

    private function toDisplay(mixed $value): mixed
    {
        return is_bool($value) ? ($value ? 'Yes' : 'No') : (is_scalar($value) ? (string) $value : null);
    }

    /** A locked entry covers itself and everything below it ("company_codes" covers "company_codes.x"). */
    public static function isLocked(string $path, array $locked): bool
    {
        foreach ($locked as $entry) {
            if ($path === $entry || str_starts_with($path, $entry.'.')) {
                return true;
            }
        }

        return false;
    }
}
