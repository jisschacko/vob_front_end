{{-- "SKU Data" popup for Margin & Rebate. Native <dialog>: centred by the browser, Esc closes it. The inputs have no name, so nothing here is ever posted. --}}
@php $articles = array_values((array) config('sku.articles', [])) @endphp
<dialog id="sku-dialog" class="sku-dialog" aria-labelledby="sku-title" data-articles="{{ json_encode($articles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}" data-rows="{{ (int) config('sku.popup_rows', 4) }}">
    <div class="sku-head">
        <h3 id="sku-title">SKU Data</h3>
        <button type="button" class="sku-x" data-sku-cancel aria-label="Close">&times;</button>
    </div>
    <div class="sku-body">
        <div class="table-scroll">
            <table class="mini sku-table">
                <thead>
                    <tr><th>Article</th><th>Description</th><th>Front End Margin</th><th>Back End Margin</th></tr>
                </thead>
                <tbody data-sku-rows></tbody>
            </table>
        </div>
        <button type="button" class="file-btn sku-more" data-sku-more>+ Add another row</button>
        <p class="error sku-error" role="alert" data-sku-error hidden></p>
    </div>
    <div class="sku-foot">
        <button type="button" class="btn btn-secondary" data-sku-cancel>Cancel</button>
        <button type="button" class="btn" data-sku-add>Add</button>
    </div>

    {{-- Confirmation shown by Add --}}
    <div class="sku-confirm" data-sku-confirm hidden role="alertdialog" aria-labelledby="sku-confirm-title">
        <div class="sku-confirm-box">
            <h4 id="sku-confirm-title">Add these to Margin &amp; Rebate?</h4>
            <div class="table-scroll">
                <table class="mini sku-summary">
                    <thead><tr><th>Article</th><th>Description</th><th>Front End Margin</th><th>Back End Margin</th></tr></thead>
                    <tbody data-sku-summary></tbody>
                </table>
            </div>
            <div class="sku-foot">
                <button type="button" class="btn btn-secondary" data-sku-back>Back</button>
                <button type="button" class="btn" data-sku-confirm-yes>Confirm</button>
            </div>
        </div>
    </div>
</dialog>
