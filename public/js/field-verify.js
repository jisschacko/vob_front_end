/* Background checks while the vendor fills the form (Cashfree through our own server): PAN, GSTIN, IFSC and bank account, each when the
   vendor leaves the box. Every call is a POST with the CSRF token; the server throttles and remembers answers per value. No inline script (CSP).
   The bank check reads the SAVED draft, so the draft is saved first. Fields only carry data-verify when live checks are on (not demo mode). */
(function () {
  'use strict';

  var form = document.getElementById('tot-form');
  if (!form || !form.querySelector('[data-verify]')) return;

  var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var PAN = /^[A-Z]{5}[0-9]{4}[A-Z]$/;
  var GSTIN = /^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/;
  var IFSC = /^[A-Z]{4}0[A-Z0-9]{6}$/;

  function clean(v) { return (v || '').toUpperCase().replace(/\s+/g, ''); }

  function post(url, data) {
    var body = new FormData();
    Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
    return fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' }, body: body, credentials: 'same-origin', redirect: 'manual' })
      .then(function (r) {
        if (r.status === 401 || r.status === 419 || r.type === 'opaqueredirect') return { state: 'unavailable', message: 'Your session has ended. Please sign in again.' };
        return r.json().catch(function () { return { state: 'unavailable', message: 'We could not verify this right now.' }; });
      })
      .catch(function () { return { state: 'unavailable', message: 'We could not verify this right now. Check your connection.' }; });
  }

  /** the message line under the field (the bank check reports under Confirm Account No) */
  function msgOf(input) {
    var box = input.closest('.field-wrap');
    return box && box.querySelector('[data-verify-msg]');
  }

  function show(el, state, text) {
    if (!el) return;
    el.hidden = !text;
    el.className = 'verify-msg ' + (state ? 'is-' + state : '');
    el.textContent = text ? (state === 'valid' ? '✓ ' : '') + text : '';
  }

  // ---- PAN and GSTIN: check the value the vendor leaves, once per value --------------------------------------------------
  function simple(input, name, shape, skip) {
    var last = null;
    var msg = msgOf(input);
    input.addEventListener('input', function () { show(msg, '', ''); input.classList.remove('is-valid', 'is-bad'); });
    input.addEventListener('blur', function () {
      var value = clean(input.value);
      input.value = value;
      if (value === '' || (skip && skip())) { show(msg, '', ''); last = null; return; }
      if (value === last) return;
      if (!shape.test(value)) { // wrong shape: tell at once, no call
        show(msg, 'bad', name === 'pan' ? 'Enter a valid PAN, for example ABCDE1234F.' : 'Enter a valid 15-character GSTIN.');
        input.classList.add('is-bad');
        return;
      }
      last = value;
      show(msg, 'wait', 'Checking…');
      var data = {}; data[name] = value;
      post(input.dataset.verifyUrl, data).then(function (r) {
        if (clean(input.value) !== value) return; // changed meanwhile
        var who = r.detail && r.detail.name ? ' (' + r.detail.name + ')' : '';
        show(msg, r.state === 'valid' ? 'valid' : (r.state === 'invalid' ? 'bad' : 'muted'), r.state === 'valid' ? r.message + who : r.message);
        input.classList.toggle('is-valid', r.state === 'valid');
        input.classList.toggle('is-bad', r.state === 'invalid');
        if (r.state === 'unavailable') last = null; // try again next time
      });
    });
  }

  var pan = form.querySelector('[data-verify="pan"]');
  if (pan) simple(pan, 'pan', PAN);

  var gst = form.querySelector('[data-verify="gstin"]');
  var gstType = form.querySelector('[data-gst-type]');
  if (gst) simple(gst, 'gstin', GSTIN, function () { return gstType && gstType.value !== 'GST Registered'; });

  // ---- IFSC: bank, branch, address, MICR and payment modes; fills "Bank Name & Address" when it is empty or was filled by us ----
  var ifsc = form.querySelector('[data-verify="ifsc"]');
  var fill = form.querySelector('[data-ifsc-fill]');
  if (ifsc) {
    var lastIfsc = null;
    var ifscMsg = msgOf(ifsc);
    var detailEl = ifsc.closest('.field-wrap').querySelector('[data-verify-detail]');
    ifsc.addEventListener('input', function () { show(ifscMsg, '', ''); if (detailEl) detailEl.hidden = true; });
    ifsc.addEventListener('blur', function () {
      var value = clean(ifsc.value);
      ifsc.value = value;
      if (value === '') { show(ifscMsg, '', ''); if (detailEl) detailEl.hidden = true; lastIfsc = null; return; }
      if (value === lastIfsc || !IFSC.test(value)) return; // a wrong shape is already reported by the format check of tot-form.js
      lastIfsc = value;
      show(ifscMsg, 'wait', 'Checking…');
      post(ifsc.dataset.verifyUrl, { ifsc: value }).then(function (r) {
        if (clean(ifsc.value) !== value) return;
        show(ifscMsg, r.state === 'valid' ? 'valid' : (r.state === 'invalid' ? 'bad' : 'muted'), r.message);
        if (r.state === 'unavailable') lastIfsc = null;
        if (r.state !== 'valid' || !r.detail) { if (detailEl) detailEl.hidden = true; return; }
        var d = r.detail;
        var line = [d.bank, d.branch, d.address].filter(Boolean).join(', ');
        var extra = [d.micr ? 'MICR ' + d.micr : '', d.modes && d.modes.length ? d.modes.join(' / ') : ''].filter(Boolean).join(' · ');
        // the bank, branch and address go into "Bank Name & Address" below; only MICR and payment modes are shown here
        if (detailEl) { detailEl.textContent = extra; detailEl.hidden = !extra; }
        if (fill && line && (fill.value.trim() === '' || fill.dataset.autofilled === fill.value)) {
          fill.value = line;
          fill.dataset.autofilled = line;
          fill.dispatchEvent(new Event('input', { bubbles: true })); // grows the box and schedules the autosave
        }
      });
    });
  }

  // ---- Bank account: Cashfree makes the penny drop itself; nothing for the vendor to pay or scan -------------------------------
  var name = form.querySelector('[data-verify="bank"][name$="[account_name]"]');
  var acct = form.querySelector('[data-verify="bank"][name$="[account_no]"]');
  var conf = form.querySelector('[data-verify="bank"][data-confirm-of]');
  var bankIfsc = ifsc;
  if (name && acct && conf && bankIfsc) {
    var bankMsg = msgOf(conf);
    var lastKey = null;
    var running = false;

    var ready = function () {
      return name.value.trim() !== '' && acct.value.trim() !== '' && conf.value !== '' && conf.value === acct.value && IFSC.test(clean(bankIfsc.value));
    };
    var keyOf = function () { return [clean(acct.value), clean(bankIfsc.value), name.value.trim().toLowerCase()].join('|'); };

    // Submit / Resubmit ask this: a bank account that was verified and then edited must be verified again (or the manual-approval box ticked)
    var verifiedSig = (document.querySelector('.bank-status strong') || {}).textContent === 'Verified' ? keyOf() : null;
    // the values for which the check answered "failed" / "could not verify" (also when the Admin already says failed on page load)
    var failedSig = (document.querySelector(".bank-status strong") || {}).textContent === "Verification failed" ? keyOf() : null;
    var override = form.querySelector('input[type="checkbox"][name$="[validation_failed_override]"]');
    var overrideBox = form.querySelector('[data-override-box]');
    window.vobBankGate = function () {
      if (override && override.checked) return '';
      if (running) return 'Bank account is being verified, please wait for the verification to finish.';
      if (failedSig !== null && keyOf() === failedSig) return 'Bank account verification failed, click the checkbox "For failed validations" to proceed with manual verification.';
      if (verifiedSig !== null && keyOf() !== verifiedSig) return 'Bank account is edited, wait for verification. If verification fails, tick "For failed validations" to continue on manual approval.';
      return '';
    };

    // green tick under Account No and IFS Code while they are the verified values (shown by the server for the saved ones)
    var ticks = form.querySelectorAll('[data-bank-ok]');
    var setOk = function (on) { ticks.forEach(function (t) { t.hidden = !on; }); };

    var run = function () {
      if (running || !ready()) return;
      var key = keyOf();
      if (key === lastKey) return; // already checked this exact combination
      lastKey = key;
      running = true;
      show(bankMsg, 'wait', 'Verifying the bank account…');
      Promise.resolve(window.vobAutosave ? window.vobAutosave() : true).then(function (saved) {
        if (saved === false) { lastKey = null; running = false; show(bankMsg, 'muted', 'Your details could not be saved yet, so the account was not verified. Fix the form and leave this box again.'); return; }
        return post(conf.dataset.verifyUrl).then(function (r) {
          running = false;
          if (keyOf() !== key) { lastKey = null; run(); return; } // typed again while waiting
          var d = r.detail || {};
          var text = r.message;
          if (r.state === 'valid' && d.name_at_bank) text += ' Name at bank: ' + d.name_at_bank + (d.name_match ? ' (' + d.name_match.toLowerCase().replace(/_/g, ' ') + ')' : '') + '.';
          show(bankMsg, r.state === 'valid' ? 'valid' : (r.state === 'invalid' || r.state === 'incomplete' ? 'bad' : 'muted'), text);
          if (r.state === 'unavailable' || r.state === 'incomplete') lastKey = null;
          if (r.state === 'valid') { verifiedSig = key; failedSig = null; setOk(true); }
          if (r.state === 'invalid' || r.state === 'unavailable') failedSig = key;
          // "For failed validations" is offered only when the bank could not confirm the account or the check could not run (Cashfree down,
          // no credits, IP not whitelisted); a verified account no longer needs it
          if (overrideBox && (r.state === 'invalid' || r.state === 'unavailable')) overrideBox.hidden = false;
          if (overrideBox && r.state === 'valid') {
            overrideBox.hidden = true;
            if (override && override.checked) { override.checked = false; override.dispatchEvent(new Event('change', { bubbles: true })); } // saves the draft
          }
          var status = document.querySelector('.bank-status strong');
          if (status && (r.state === 'valid' || r.state === 'invalid')) status.textContent = r.state === 'valid' ? 'Verified' : 'Verification failed';
        });
      });
    };

    [name, acct, conf, bankIfsc].forEach(function (input) { input.addEventListener('blur', run); });
    [name, acct, conf, bankIfsc].forEach(function (input) { input.addEventListener('input', function () {
      if (lastKey !== null && keyOf() !== lastKey) { lastKey = null; show(bankMsg, '', ''); }
      setOk(verifiedSig !== null && keyOf() === verifiedSig); // edited: the tick goes until the new values are verified
    }); });
  }
})();
