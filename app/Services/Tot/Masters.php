<?php

namespace App\Services\Tot;

use App\Services\AdminApi\AdminApiClient;
use App\Services\AdminApi\AdminApiUnavailableException;
use Illuminate\Support\Facades\Cache;

/** Dropdown data from GET /masters (active records only, by code). Shared reference data, so it is cached for a few minutes. */
class Masters
{
    public function __construct(private readonly AdminApiClient $api) {}

    /** @return array<string,mixed> */
    public function all(): array
    {
        return Cache::remember('vob.masters', now()->addMinutes((int) config('vob.masters_cache_minutes')), function () {
            $response = $this->api->get('masters');
            if (! $response->successful() || ! is_array($response->json())) {
                throw new AdminApiUnavailableException('Masters could not be loaded.');
            }

            return $response->json();
        });
    }

    /**
     * code => label for a master list. Accepts [{code,name|description,city?}] or plain strings.
     *
     * @return array<string,string>
     */
    public function options(string $master): array
    {
        $options = [];
        foreach ((array) ($this->all()[$master] ?? []) as $item) {
            if (is_string($item) || is_int($item)) {
                $options[(string) $item] = (string) $item;

                continue;
            }

            $code = $item['code'] ?? null;
            if ($code === null) {
                continue;
            }
            $name = trim((string) ($item['name'] ?? $item['description'] ?? $code));
            $label = in_array($master, ['company_codes', 'purchase_orgs'], true) ? "$code - $name" : $name;
            if ($master === 'company_codes' && ! empty($item['city'])) {
                $label .= ' ('.$item['city'].')';
            }
            $options[(string) $code] = $label;
        }

        return $options;
    }
}
