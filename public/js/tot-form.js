/* TOT form behaviour. No inline script is used (CSP); every action is a POST with the CSRF token. */
(function () {
  'use strict';

  var form = document.getElementById('tot-form');
  if (!form) return;

  var statusEl = document.getElementById('autosave-status');
  var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var timer = null;
  var dirty = false;
  var saving = null;

  function say(text, isError) {
    if (!statusEl) return;
    statusEl.textContent = text;
    statusEl.style.color = isError ? '#b3261e' : '';
  }

  // ---- textarea auto-grow -------------------------------------------------------------------------------------------
  function grow(el) { el.style.height = 'auto'; el.style.height = el.scrollHeight + 'px'; }
  document.querySelectorAll('textarea.autogrow').forEach(grow);
  form.addEventListener('input', function (e) { if (e.target.matches && e.target.matches('textarea.autogrow')) grow(e.target); });

  // ---- GST Regn. No is not mandatory for GST Unregistered vendors ----------------------------------------------------
  var gstType = form.querySelector('[data-gst-type]');
  var gstStar = document.getElementById('gst-regn-req');
  if (gstType && gstStar) {
    gstType.addEventListener('change', function () { gstStar.hidden = gstType.value === 'GST Unregistered'; });
  }

  // ---- autosave (same call as Save Draft, answered as JSON) -----------------------------------------------------------
  function save() {
    if (saving) return saving;
    dirty = false;
    say('Saving...');
    saving = fetch(form.dataset.autosave, {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
      body: new FormData(form),
      credentials: 'same-origin',
      redirect: 'manual'
    }).then(function (r) {
      if (r.status === 401 || r.status === 419 || r.type === 'opaqueredirect') {
        say('Your session has ended. Please sign in again.', true);
        return false;
      }
      return r.json().then(function (j) {
        if (j.saved) { say('Draft saved ' + j.saved_at); return true; }
        say((j.message || 'Draft not saved.') + ' Use Save Draft to see what needs correcting.', true);
        return false;
      });
    }).catch(function () {
      say('Could not save. Check your connection; your changes are still on this page.', true);
      return false;
    }).then(function (ok) { saving = null; return ok; });
    return saving;
  }

  function schedule() {
    dirty = true;
    clearTimeout(timer);
    timer = setTimeout(function () { if (dirty) save(); }, 2500);
  }
  form.addEventListener('change', schedule);
  form.addEventListener('input', function (e) { if (!e.target.matches('[data-doc-input]')) schedule(); });

  // ---- submit: ask for confirmation (the server-side checks run regardless) ------------------------------------------------
  var submitBtn = document.querySelector('[data-submit]');
  if (submitBtn) {
    submitBtn.addEventListener('click', function (e) {
      if (!window.confirm(submitBtn.dataset.confirm)) e.preventDefault();
    });
  }

  // ---- bank verification: save the typed bank details first, then start the verification ----------------------------------
  var verifyBtn = document.querySelector('[data-bank-verify]');
  if (verifyBtn) {
    verifyBtn.addEventListener('click', function (e) {
      e.preventDefault();
      var target = document.getElementById(verifyBtn.getAttribute('form'));
      Promise.resolve(save()).then(function (ok) {
        if (ok === false && !window.confirm('Your latest changes could not be saved. Verify with the bank details already saved?')) return;
        if (target) target.submit();
      });
    });
  }

  // ---- document upload: save the draft first, then submit the upload form (a page reload would lose unsaved edits) -------
  document.querySelectorAll('[data-doc-input]').forEach(function (input) {
    input.addEventListener('change', function () {
      if (!input.files.length) return;
      var target = document.getElementById(input.getAttribute('form'));
      Promise.resolve(save()).then(function () { if (target) target.submit(); });
    });
  });
})();
