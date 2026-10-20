<?php

namespace App\Services\Tot;

use Illuminate\Support\MessageBag;

/** Everything the TOT page needs to draw a field: value, locked / required state, options, error. Built per request. */
class TotPage
{
    /** The six payment-day fields behind the API's "payment_terms.terms" requirement. */
    private const PAYMENT_DAY_FIELDS = ['days_from_statement', 'days_from_grn', 'y_days', 'z_days', 'b_days', 'sor_days'];

    /** @var array<string,mixed> */
    private array $values;

    /**
     * @param  array<string,mixed>  $detail  GET /tots/{id}
     */
    public function __construct(
        public readonly array $detail,
        private readonly Masters $masters,
        private readonly TotFormMapper $mapper,
        private readonly MessageBag $errors,
    ) {
        $this->values = $mapper->values((array) ($detail['form'] ?? []));
    }

    public function tot(): array
    {
        return (array) ($this->detail['tot'] ?? []);
    }

    public function editable(): bool
    {
        return (bool) ($this->tot()['editable'] ?? false);
    }

    /** @return list<string> */
    public function lockedFields(): array
    {
        return array_values((array) ($this->detail['locked_fields'] ?? []));
    }

    public function isLocked(string $path): bool
    {
        return ! $this->editable() || TotFormMapper::isLocked($path, $this->lockedFields());
    }

    public function isRequired(string $path): bool
    {
        $required = (array) ($this->detail['required_fields'] ?? []);

        if (preg_match('/^payment_terms\.(\w+)$/', $path, $m) && in_array($m[1], self::PAYMENT_DAY_FIELDS, true)) {
            return false; // the requirement is "at least one of the six", shown once above them
        }
        if ($path === 'vendor_details.gst_regn_no' && $this->raw('vendor_details.gst_type') === 'GST Unregistered') {
            return false;
        }

        return in_array($path, $required, true);
    }

    public function paymentTermsRequired(): bool
    {
        return in_array('payment_terms.terms', (array) ($this->detail['required_fields'] ?? []), true);
    }

    /**
     * Paths whose stored value came back masked (e.g. the account number on a renewal).
     *
     * @return list<string>
     */
    public function maskedPaths(): array
    {
        $paths = [];
        $walk = function (array $node, string $prefix) use (&$walk, &$paths): void {
            foreach ($node as $key => $value) {
                $path = $prefix === '' ? (string) $key : "$prefix.$key";
                if (is_array($value)) {
                    $walk($value, $path);
                } elseif (is_string($value) && str_contains($value, '*')) {
                    $paths[] = $path;
                }
            }
        };
        $walk($this->values, '');

        return $paths;
    }

    /**
     * Is this document type mandatory? `required_documents` entries look like "pan_certificate" or
     * "gst_certificate (when GST Registered)"; a conditional one counts when the vendor's GST/MSME type matches.
     */
    public function documentRequired(string $type): bool
    {
        foreach ((array) ($this->detail['required_documents'] ?? []) as $entry) {
            if (! is_string($entry) || strtok($entry, ' ') !== $type) {
                continue;
            }
            if (! preg_match('/\(when (.+)\)/', $entry, $m)) {
                return true;
            }

            return in_array($m[1], [$this->raw('vendor_details.gst_type'), $this->raw('vendor_details.msme_type')], true);
        }

        return false;
    }

    /** Value stored at the Admin (display form: bools as Yes/No). */
    public function raw(string $path): mixed
    {
        return data_get($this->values, $path);
    }

    /** True when the Admin returned the value masked ("******1234"): it is not shown, and not sent back unless retyped. */
    public function isMasked(string $path): bool
    {
        $value = $this->raw($path);

        return is_string($value) && str_contains($value, '*');
    }

    /** What to show: the posted value after a failed save, otherwise the stored one. */
    public function value(string $path): mixed
    {
        $old = old('form.'.$path);
        if ($old !== null) {
            return $old;
        }

        return $this->isMasked($path) ? null : $this->raw($path);
    }

    public function name(string $path): string
    {
        return 'form['.implode('][', explode('.', $path)).']';
    }

    public function id(string $path): string
    {
        return 'f-'.preg_replace('/[^A-Za-z0-9]+/', '-', $path);
    }

    public function error(string $path): ?string
    {
        return $this->errors->first($path) ?: null;
    }

    /**
     * Select options for a field spec: code => label.
     *
     * @return array<string,string>
     */
    public function options(array $spec): array
    {
        $options = isset($spec['master'])
            ? $this->masters->options($spec['master'])
            : array_combine($spec['options'] ?? [], $spec['options'] ?? []);

        $current = $this->raw($spec['path']);
        if (is_string($current) && $current !== '' && ! isset($options[$current]) && ! $this->isMasked($spec['path'])) {
            $options[$current] = $current; // keep a stored value that is no longer in the list
        }

        return $options;
    }

    /** @return array<string,string> */
    public function masterOptions(string $master): array
    {
        return $this->masters->options($master);
    }

    /** @return list<string> */
    public function selectedCodes(string $section): array
    {
        return (array) ($this->values[$section] ?? []);
    }

    /** Posted Yes/No for a category after a failed save, else Yes when stored. */
    public function categoryValue(string $code): string
    {
        $old = old('form.categories.'.$code);

        return $old ?? (in_array($code, $this->selectedCodes('categories'), true) ? 'Yes' : '');
    }

    /** @return array<int,array<string,mixed>> existing rows of a list section, plus blank ones so there is always room to add. */
    public function listRows(string $section, int $minRows): array
    {
        $posted = old('form.'.$section);
        $rows = is_array($posted) ? array_values($posted) : array_values((array) ($this->values[$section] ?? []));

        return array_pad($rows, max($minRows, count($rows) + 1), []);
    }

    /** @return list<array{id:mixed,type:string,name:string,size:mixed,uploaded_at:mixed}> */
    public function documents(): array
    {
        return array_values((array) ($this->detail['documents'] ?? []));
    }

    public function document(string $type): ?array
    {
        foreach ($this->documents() as $document) {
            if (($document['type'] ?? null) === $type) {
                return $document;
            }
        }

        return null;
    }

    public function bankStatus(): string
    {
        return (string) ($this->detail['form']['bank']['verification_status'] ?? 'pending');
    }

    /** @return list<array<string,mixed>> */
    public function reviewerComments(): array
    {
        return array_values((array) ($this->detail['reviewer_comments'] ?? []));
    }
}
