  <div class="page-divider">Terms of Trade (TOT)</div>

  {{-- The 14 clauses come from the Admin (`clause_templates` = the original wording); the vendor's edits are `form.clauses`. --}}
  @forelse ($page->clauseTemplates() as $tpl)
    @include('tot._clause', ['tpl' => $tpl])
  @empty
    <div class="card-note">The Terms of Trade clauses are not available right now. Please try again later.</div>
  @endforelse

  <div class="card">
    <div class="card-header">Signatories &mdash; Terms of Trade</div>
    <div class="card-body">
      <div class="field-grid">
        <div class="field-wrap"><label class="flabel">For the Company &mdash; Authorized Signatory</label><p class="help">Lulu International Shopping Malls Pvt. Ltd.</p><p class="esign-id left">L03</p><div class="esign-space"></div></div>
        <div class="field-wrap"><label class="flabel">For the Vendor &mdash; Authorized Signatory</label><p class="esign-id left">V01</p><div class="esign-space"></div></div>
      </div>
    </div>
  </div>
