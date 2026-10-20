@php $kind = $b[0] @endphp

@if ($kind === 'grid')
    <div class="{{ $b[1] === 3 ? 'field-grid-3' : 'field-grid' }}">
        @foreach ($b[2] as $f)
            @include('tot._field', ['f' => $f])
        @endforeach
    </div>

@elseif ($kind === 'note')
    <div class="card-note">{{ $b[1] }}</div>

@elseif ($kind === 'heading')
    <p class="flabel block-heading">{{ $b[1] }}</p>

@elseif ($kind === 'view')
    @include($b[1])

@elseif ($kind === 'payment_terms_note')
    @if ($page->paymentTermsRequired())
        <div class="card-note">Fill in at least one of the payment day fields<span class="req">*</span>.</div>
    @endif

@elseif ($kind === 'bank_status')
    @php $status = $page->bankStatus() @endphp
    <p class="help bank-status">Bank account verification:
        <strong>{{ ['verified' => 'Verified', 'failed' => 'Verification failed', 'pending' => 'Pending'][$status] ?? ucfirst($status) }}</strong>.
        @if ($status === 'verified' && ($ref = $page->bankVerification())['reference']) (reference {{ $ref['reference'] }}@if ($ref['verified_at']), {{ \Illuminate\Support\Carbon::parse($ref['verified_at'])->setTimezone(config('vob.display_timezone'))->format('d-M-Y, h:i A') }}@endif) @endif
        Changing the account name, number or IFS code resets it; on a renewal the account is always verified again.</p>
    @if ($page->editable())
        <div class="field-row">
            <button type="submit" class="file-btn" form="bank-verify" data-bank-verify>{{ $status === 'verified' ? 'Verify again' : 'Verify bank account' }}</button>
            <span class="help">You pay Rs 1 by UPI from this account; it is refunded within 48 hours.</span>
        </div>
    @endif

@elseif ($kind === 'table')
    <div class="table-scroll">
        <table class="mini" @isset($b[3]) id="{{ $b[3] }}" @endisset>
            @if (array_filter($b[1], fn ($h) => $h !== ''))
                <tr>@foreach ($b[1] as $h)<th>{{ $h }}</th>@endforeach</tr>
            @endif
            @foreach ($b[2] as $row)
                <tr>
                    @foreach ($row as $cell)
                        @if (is_array($cell))
                            <td @if (($cell['colspan'] ?? 1) > 1) colspan="{{ $cell['colspan'] }}" @endif>@include('tot._field', ['f' => $cell])</td>
                        @else
                            <td>{{ $cell }}</td>
                        @endif
                    @endforeach
                </tr>
            @endforeach
        </table>
    </div>

@elseif ($kind === 'list_table')
    @php $rows = $page->listRows($b[1], $b[2]) @endphp
    <div class="table-scroll">
        <table class="mini">
            <tr>@foreach ($b[3] as $h)<th>{{ $h }}</th>@endforeach</tr>
            @foreach ($rows as $i => $row)
                <tr>
                    @foreach ($b[4] as $column => $def)
                        <td>@include('tot._field', ['f' => \App\Services\Tot\TotLayout::cell("{$b[1]}.$i.$column", $def[0], 1, $def[1] ?? [])])</td>
                    @endforeach
                </tr>
            @endforeach
        </table>
    </div>
    <p class="help">Save the draft to get an extra blank row.</p>

@elseif ($kind === 'categories')
    <div class="toggle-grid">
        @foreach ($page->masterOptions('categories') as $code => $label)
            <div class="toggle-item">
                <label for="cat-{{ $code }}">{{ $label }}</label>
                <select class="f" id="cat-{{ $code }}" name="form[categories][{{ $code }}]" @if ($page->isLocked('categories')) disabled @endif>
                    @php $v = $page->categoryValue((string) $code) @endphp
                    <option value=""></option>
                    <option value="Yes" @selected($v === 'Yes')>Yes</option>
                    <option value="No" @selected($v === 'No')>No</option>
                </select>
                @if ($page->error("categories.$code"))<span class="error" role="alert">{{ $page->error("categories.$code") }}</span>@endif
            </div>
        @endforeach
    </div>

@elseif ($kind === 'deliverables')
    <div class="table-scroll">
        <table class="mini">
            <tr><th>Asset</th><th>Nos</th><th>Brand</th><th>Paid/ToT</th><th>Duration</th><th>Payment Terms</th></tr>
            @foreach ($page->masterOptions('deliverable_assets') as $code => $label)
                <tr>
                    <td>{{ $label }}</td>
                    @foreach (['numbers', 'brand', 'paid_total', 'duration'] as $col)
                        <td>@include('tot._field', ['f' => \App\Services\Tot\TotLayout::cell("deliverables.$code.$col", 'text')])</td>
                    @endforeach
                    <td>@include('tot._field', ['f' => \App\Services\Tot\TotLayout::cell("deliverables.$code.payment_terms", 'select', 1, ['options' => ['Debit', 'Cheque/RTGS']])])</td>
                </tr>
            @endforeach
        </table>
    </div>

@elseif ($kind === 'esign')
    <div class="esign-grid">
        <div class="esign-side">
            <p class="esign-head">For &amp; On behalf of Lulu</p>
            <div class="esign-sub-grid">
                @foreach ([['Central Buyer/ Regional Buying Manager', 'L01'], ['Manager Central Buying', 'L02'], ['GM-Buying / Director', 'L03']] as [$role, $anchor])
                    <div class="esign-sub">
                        <div class="esign-space"></div>
                        <p class="esign-desig">{{ $role }}</p>
                        <p class="esign-id">{{ $anchor }}</p>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="esign-side esign-vendor">
            <p class="esign-head">For &amp; On behalf of Vendor</p>
            <div class="esign-space"></div>
            <p class="esign-desig">Authorised Signatory</p>
            <p class="esign-id">V01</p>
        </div>
    </div>

@elseif ($kind === 'documents')
    <div class="field-grid">
        @foreach ($b[1] as $type)
            @php
                $doc = $page->document($type);
                [$label, $help] = (\App\Services\Tot\TotLayout::documents()[$type] + [1 => null]);
                $required = $page->documentRequired($type);
            @endphp
            <div class="field-wrap">
                <label class="flabel">{{ $label }}@if ($required)<span class="req">*</span>@endif</label>
                <p class="help-top">
                    @if ($doc) Uploaded: {{ $doc['name'] ?? '' }}@if (! empty($doc['size'])) ({{ number_format($doc['size'] / 1024, 0) }} KB)@endif
                    @else No file uploaded yet. @endif
                </p>
                <div class="field-row">
                    @if ($type === 'rtgs_form')
                        <a class="file-btn" href="{{ asset('downloads/RTGS_template.docx') }}" download="RTGS_template.docx">Download Sample</a>
                    @endif
                    @if ($page->editable())
                        <label class="file-btn">{{ $doc ? 'Replace File' : 'Choose File' }}<input type="file" name="file" accept="application/pdf,.pdf" form="doc-up-{{ $type }}" data-doc-input></label>
                        <button type="submit" class="file-btn" form="doc-up-{{ $type }}">Upload</button>
                        @if ($doc)<button type="submit" class="file-btn danger" form="doc-rm-{{ $type }}">Remove</button>@endif
                    @endif
                </div>
                <p class="help">PDF, up to {{ config('vob.docs_max_kb') / 1024 }} MB.@if ($help) {{ $help }}@endif</p>
            </div>
        @endforeach
    </div>
@endif
