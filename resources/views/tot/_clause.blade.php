@php
    /** One Terms of Trade clause. $tpl comes from the API `clause_templates` (the original wording); the vendor's edit from `form.clauses`. */
    $key = $tpl['key'];
    $original = (string) ($tpl['text'] ?? '');
    $placeholders = array_values((array) ($tpl['placeholders'] ?? []));
    $sectionKey = 'clause_'.$key;
    $current = $page->clauseText($key, $original);
    $edited = $page->clauseIsEdited($key);
    $flagged = $page->isFlagged($sectionKey);
    $error = $page->error('clauses.'.$key);
    $open = $error || is_string(old('form.clauses.'.$key));
    $canEdit = $page->editable();
    $fields = array_values(array_unique(array_merge(\App\Services\Tot\ClauseText::fieldsIn($original, $placeholders), $placeholders)));
@endphp
<div class="card clause-card" id="{{ $sectionKey }}" data-clause @if ($flagged) data-flagged @endif>
    <div class="card-header clause-header">
        <span>{{ $tpl['number'] ?? '' }}. {{ $tpl['title'] ?? $key }}
            @if ($edited)<span class="badge badge-edited">Edited by you</span>@endif
            @if ($flagged)<span class="badge badge-flag">Comment added</span>@endif
        </span>
        @if ($canEdit)
            <button type="button" class="pencil" data-clause-toggle aria-expanded="{{ $open ? 'true' : 'false' }}" aria-controls="clause-edit-{{ $key }}"
                    title="Edit this section" aria-label="Edit {{ $tpl['title'] ?? $key }}">
                <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M3 17.25V21h3.75L17.8 9.94l-3.75-3.75L3 17.25zm17.71-10.21a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
            </button>
        @endif
    </div>
    <div class="card-body text-body">
        <div class="clause-view" @if ($open) hidden @endif>
            @foreach (\App\Services\Tot\ClauseText::paragraphs($current, $placeholders) as $segments)
                <p>
                    @foreach ($segments as $segment)
                        @if (isset($segment['field']))
                            @include('tot._field', ['f' => \App\Services\Tot\TotLayout::cell('business_terms.'.$segment['field'], 'number', 1, ['inline' => true, 'aria' => str_replace('_', ' ', $segment['field']), 'max' => \App\Services\Tot\ClauseText::max($segment['field']), 'sync' => $segment['field'], 'idsuffix' => '-view-'.$key])])
                        @else
                            {!! nl2br(e($segment['text'])) !!}
                        @endif
                    @endforeach
                </p>
            @endforeach
        </div>

        @if ($canEdit)
            <div class="clause-edit" id="clause-edit-{{ $key }}" @unless ($open) hidden @endunless>
                <label class="flabel" for="clause-text-{{ $key }}">Wording of {{ $tpl['number'] ?? '' }}. {{ $tpl['title'] ?? $key }}</label>
                <textarea class="f clause-text" id="clause-text-{{ $key }}" name="form[clauses][{{ $key }}]" rows="10" maxlength="20000"
                          data-original="{{ $original }}" @unless ($open || $edited) disabled @endunless>{{ $current }}</textarea>
                @if ($fields !== [])
                    <div class="clause-fields">
                        @foreach ($fields as $field)
                            <div class="field-wrap">
                                @include('tot._field', ['f' => \App\Services\Tot\TotLayout::field('business_terms.'.$field, ucfirst(str_replace('_', ' ', $field)), 'number', ['max' => \App\Services\Tot\ClauseText::max($field), 'sync' => $field, 'idsuffix' => '-edit-'.$key])])
                            </div>
                        @endforeach
                    </div>
                @endif
                <p class="help">Keep the markers (for example <code>{refund_within_days}</code>) where a number should appear. Text identical to the original wording is saved as "not edited".</p>
                <button type="button" class="file-btn" data-clause-restore>Restore original wording</button>
                @if ($error)<p class="error" role="alert">{{ $error }}</p>@endif
            </div>
        @endif
    </div>
</div>
