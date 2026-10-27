<?php

namespace App\Services\Tot;

use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;

/**
 * What is still missing before the vendor presses Submit. Uses the Admin's `required_fields` / `required_documents` (never a list of
 * our own). Which checks run depends on the development switches VOB_ENFORCE_REQUIRED / VOB_ENFORCE_DOCUMENTS (always on in production).
 * The Admin re-checks everything on submit; this only gives the vendor the list without a round trip.
 */
class SubmitReadiness
{
    private const PAYMENT_DAY_FIELDS = ['days_from_statement', 'days_from_grn', 'y_days', 'z_days', 'b_days', 'sor_days'];

    public function __construct(
        private readonly Masters $masters,
        private readonly TotFormMapper $mapper,
    ) {}

    /**
     * @param  array<string,mixed>  $detail  GET /tots/{id}
     * @return array<string,string> field path => message
     */
    public function missing(array $detail, bool $checkRequired, bool $checkDocuments): array
    {
        $page = new TotPage($detail, $this->masters, $this->mapper, new MessageBag);
        $problems = [];

        if ($checkRequired) {
            $labels = $this->labels();
            foreach ((array) ($detail['required_fields'] ?? []) as $path) {
                if ($path === 'payment_terms.terms') {
                    $filled = array_filter(self::PAYMENT_DAY_FIELDS, fn ($f) => filled($page->raw("payment_terms.$f")));
                    if ($filled === []) {
                        $problems['payment_terms.days_from_statement'] = 'Enter at least one payment term (days).';
                    }

                    continue;
                }
                if ($path === 'vendor_details.gst_regn_no' && $page->raw('vendor_details.gst_type') === 'GST Unregistered') {
                    continue;
                }
                if (blank($page->raw($path)) && ! $page->isMasked($path) && ! $page->hasDemoValue($path)) {
                    $problems[$path] = ($labels[$path] ?? Str::headline(Str::afterLast($path, '.'))).' is required.';
                }
            }
        }

        if ($checkDocuments) {
            foreach (TotLayout::documents() as $type => [$label]) {
                if ($page->documentRequired($type) && $page->document($type) === null) {
                    $problems["documents.$type"] = "$label must be uploaded.";
                }
            }
            $overridden = in_array($page->raw('bank.validation_failed_override'), ['Yes', true], true);
            if ($page->bankStatus() !== 'verified' && ! $overridden) {
                $problems['bank.verification'] = 'Verify the bank account (or tick "Bank details validation failed" to continue with manual approval).';
            }
        }

        return $problems;
    }

    /**
     * Admin submit errors ("incomplete": {path: message}) to page paths and messages.
     *
     * @param  array<string,mixed>  $errors
     * @return array<string,string>
     */
    public function fromAdmin(array $errors): array
    {
        $out = [];
        foreach ($errors as $path => $message) {
            $out[(string) $path] = is_array($message) ? (string) ($message[0] ?? '') : (string) $message;
        }

        return $out;
    }

    /** @return array<string,string> path => label of every labelled grid field in the layout */
    private function labels(): array
    {
        $labels = [];
        foreach (TotLayout::cards() as $card) {
            foreach (($card['blocks'] ?? []) as $block) {
                if (($block[0] ?? null) === 'grid') {
                    foreach ($block[2] as $field) {
                        $labels[$field['path']] = $field['label'];
                    }
                }
            }
        }

        return $labels;
    }
}
