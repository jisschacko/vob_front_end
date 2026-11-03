# To bring back after the demo

Things switched off or removed **for the demo only**. When the demo is over the owner tells Claude "demo is over, restore the items in
`docs/AFTER_DEMO.md`"; Claude restores them, puts the original tests back and runs the suite.

---

## 1. Bank verification status line under the bank fields (removed 2026-11-03)

**What it showed:** under the IFS Code row of the Bank Account Details section, one help line with the verification state
(`Pending` / `Verified` / `Verification failed`), the reference and time when verified, and an explanation.

**Why removed:** the owner asked to remove it from `/home` for the demo (bank verification is switched off at the same time, see item 2).

**Restore in `resources/views/tot/_block.blade.php`**, inside `@elseif ($kind === 'bank_status')`, directly after `@php $status = $page->bankStatus() @endphp`
and before the commented-out button:

```blade
    <p class="help bank-status">Bank account verification:
        <strong>{{ ['verified' => 'Verified', 'failed' => 'Verification failed', 'pending' => 'Pending'][$status] ?? ucfirst($status) }}</strong>.
        @if ($status === 'verified' && ($ref = $page->bankVerification())['reference']) (reference {{ $ref['reference'] }}@if ($ref['verified_at']), {{ \Illuminate\Support\Carbon::parse($ref['verified_at'])->setTimezone(config('vob.display_timezone'))->format('d-M-Y, h:i A') }}@endif) @endif
        Changing the account name, number or IFS code resets it; on a renewal the account is always verified again.</p>
```

(The same text is in git history: `git show bfebd75:resources/views/tot/_block.blade.php`.)

**Restore these tests** (they were replaced while it is off):

- `tests/Feature/ContractAlignmentTest.php`, describe `bank verification record (5B)`: replace
  `it('no longer shows the bank status line, reference or time on the vendor page', ...)` with

  ```php
  it('shows the reference and time the Admin recorded', function () {
      T::fake([], T::detail(['form' => ['bank' => ['verification_status' => 'verified', 'verification_reference' => 'DEMO-4F9K2LQ7XA', 'verified_at' => '2026-10-21T05:00:00+00:00']]]));
      $html = $this->withSession(T::session())->get('/home')->getContent();

      expect($html)->toContain('<strong>Verified</strong>')->toContain('reference DEMO-4F9K2LQ7XA')->toContain('21-Oct-2026, 10:30 AM');
  });
  ```

- `tests/Feature/BankVerificationTest.php`, describe `the form page`: replace
  `it('shows no verify button and no status line while bank verification is switched off', ...)` with the two original tests below
  (they also need the button from item 2):

  ```php
  it('has a verify button that posts to its own form, and shows the current status', function () {
      T::fake();
      $html = $this->withSession(T::session())->get('/home')->getContent();

      expect($html)->toContain('Verify bank account')->toContain('data-bank-verify')
          ->toMatch('/<form id="bank-verify" method="POST" action="[^"]+\/bank\/verify">\s*<input type="hidden" name="_token"/')
          ->toContain('Bank account verification:')->toContain('Pending');
  });

  it('shows Verified and offers to verify again', function () {
      T::fake(detail: T::detail(['form' => ['bank' => ['verification_status' => 'verified']]]));
      $html = $this->withSession(T::session())->get('/home')->getContent();

      expect($html)->toContain('<strong>Verified</strong>')->toContain('Verify again');
  });
  ```

## 2. "Verify bank account" button (switched off by the owner, comment block in the same file)

In `resources/views/tot/_block.blade.php` the button is wrapped in `{{-- Verify bank account button disabled for now ... --}}`.
Remove the comment markers to bring it back (item 1's tests above expect it). Check with the owner first whether the Cashfree
verification should be on again at the same time.

## Not to restore unless the owner asks

- The IFS Code help text ("11 characters: 4 capital letters, then 0, then 6 letters or digits (for example HDFC0001234).") was also removed
  from `app/Services/Tot/TotLayout.php` on the same day. The format is still checked and an error message is shown.
