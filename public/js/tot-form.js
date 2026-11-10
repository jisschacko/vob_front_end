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

  // ---- a message from the last Save Draft (server-rendered, e.g. "format is invalid") goes away as soon as the vendor edits that field ----
  function clearServerError(e) {
    var box = e.target.closest && e.target.closest('.field-wrap, td');
    if (!box) return;
    box.querySelectorAll('p.error:not([data-live-error])').forEach(function (p) { p.remove(); });
    if (e.target.getAttribute && e.target.getAttribute('aria-invalid') === 'true' && !box.querySelector('[data-live-error]:not([hidden])')) e.target.setAttribute('aria-invalid', 'false');
  }
  form.addEventListener('input', clearServerError);
  form.addEventListener('change', clearServerError);

  // ---- GST Regn. No is not mandatory for GST Unregistered vendors ----------------------------------------------------
  var gstType = form.querySelector('[data-gst-type]');
  var gstStar = document.getElementById('gst-regn-req');
  if (gstType && gstStar) {
    gstType.addEventListener('change', function () { gstStar.hidden = gstType.value !== 'GST Registered'; });
  }

  // ---- GST Certificate is mandatory only for GST Registered vendors (same rule as the GST Regn. No star) ----------------
  var gstCertStar = document.getElementById('gst-cert-req');
  if (gstType && gstCertStar) {
    gstType.addEventListener('change', function () { gstCertStar.hidden = gstType.value !== 'GST Registered'; });
  }

  // ---- IFS Code: 11 characters, 4 capital letters, then 0, then 6 letters / digits ------------------------------------
  var IFSC = /^[A-Z]{4}0[A-Z0-9]{6}$/;
  function liveError(input, message) {
    var el = input.parentNode.querySelector('[data-live-error]');
    if (!el) return;
    el.textContent = message;
    el.hidden = message === '';
    input.setAttribute('aria-invalid', message === '' ? 'false' : 'true');
  }
  var ifsc = form.querySelector('[data-ifsc]');
  if (ifsc && !ifsc.hasAttribute('data-demo-valid')) { // demo mode: not validated
    var checkIfsc = function (final) {
      ifsc.value = ifsc.value.toUpperCase().replace(/\s+/g, '');
      var v = ifsc.value;
      if (v === '' || IFSC.test(v)) return liveError(ifsc, '');
      if (!final && v.length < 11) return liveError(ifsc, '');
      liveError(ifsc, 'IFS Code must be 11 characters: 4 capital letters, then 0, then 6 letters or digits.');
    };
    ifsc.addEventListener('input', function () { checkIfsc(false); });
    ifsc.addEventListener('blur', function () { checkIfsc(true); });
  }

  // ---- Confirm Account No must match Account No -----------------------------------------------------------------------
  var confirmNo = form.querySelector('[data-confirm-of]');
  var accountNo = confirmNo && document.getElementById(confirmNo.dataset.confirmOf);
  if (confirmNo && accountNo) {
    var checkConfirm = function () {
      var mismatch = confirmNo.value !== '' && confirmNo.value !== accountNo.value;
      liveError(confirmNo, mismatch ? 'The account numbers do not match.' : '');
      var ok = confirmNo.parentNode.querySelector('[data-live-ok]');
      if (ok) ok.hidden = !(confirmNo.value !== '' && confirmNo.value === accountNo.value);
    };
    confirmNo.addEventListener('input', checkConfirm);
    accountNo.addEventListener('input', checkConfirm);
    // a new or changed account number must be typed again before the form is posted
    form.addEventListener('submit', function (e) {
      var changed = accountNo.value !== '' && accountNo.value !== accountNo.defaultValue;
      if (changed && confirmNo.value !== accountNo.value) {
        e.preventDefault();
        liveError(confirmNo, confirmNo.value === '' ? 'Re-enter the account number to confirm it.' : 'The account numbers do not match.');
        confirmNo.focus();
      }
    });
  }

  // ---- Reviewer comments: always expanded when the page opens (some browsers restore a collapsed <details> on reload / Back) ----
  function openComments() { document.querySelectorAll('details.comments-card').forEach(function (d) { d.open = true; }); }
  openComments();
  window.addEventListener('pageshow', openComments);

  // ---- Scope State / Region: states for "State", regions for "Regional", nothing for "PAN India" ---------------------------
  var scopeType = form.querySelector('[data-scope-type]');
  var scopeState = form.querySelector('[data-scope-state]');
  if (scopeType && scopeState) {
    var scopeLists = {};
    try { scopeLists = JSON.parse(scopeState.dataset.scopeOptions || '{}'); } catch (e) { scopeLists = {}; }
    scopeType.addEventListener('change', function () {
      var list = scopeLists[scopeType.value] || {};
      scopeState.innerHTML = '';
      scopeState.appendChild(new Option('', ''));
      Object.keys(list).forEach(function (code) { scopeState.appendChild(new Option(list[code], code)); });
      scopeState.value = '';
      scopeState.disabled = scopeType.value === 'PAN India'; // PAN India: nothing to choose (the Admin saves it empty)
    });
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

  // field-verify.js saves the draft through this before it asks for the bank check (the check uses the saved values)
  window.vobAutosave = function () { return Promise.resolve(saving).then(function () { return save(); }); };

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
      var bankProblem = window.vobBankGate ? window.vobBankGate() : '';
      if (bankProblem) { e.preventDefault(); window.alert(bankProblem); return; }
      if (!window.confirm(submitBtn.dataset.confirm)) e.preventDefault();
    });
  }


  // ---- Terms of Trade clauses: pencil opens the wording as a textarea ------------------------------------------------------
  function clauseParts(card) {
    return { view: card.querySelector('.clause-view'), edit: card.querySelector('.clause-edit'), ta: card.querySelector('textarea.clause-text'),
             btn: card.querySelector('[data-clause-toggle]') };
  }

  function openClause(card) {
    var c = clauseParts(card);
    c.view.hidden = true;
    c.edit.hidden = false;
    c.ta.disabled = false; // only opened (or already edited) clauses are posted
    c.ta.dataset.initial = c.ta.dataset.initial === undefined ? c.ta.value : c.ta.dataset.initial;
    c.btn.setAttribute('aria-expanded', 'true');
    grow(c.ta);
    c.ta.focus();
  }

  function closeClause(card) {
    var c = clauseParts(card);
    var changed = c.ta.dataset.initial !== undefined && c.ta.value !== c.ta.dataset.initial;
    if (!changed) { // nothing typed: leave the clause as it was
      c.edit.hidden = true;
      c.view.hidden = false;
      c.btn.setAttribute('aria-expanded', 'false');
      if (c.ta.dataset.initial === c.ta.dataset.original) c.ta.disabled = true;
      return;
    }
    saveClause(card); // typed something: save it, then show the saved wording
  }

  // Save the draft, then refresh just this clause's wording from the server (no page reload).
  function refreshClause(card) {
    var key = card.id;
    return fetch(window.location.href, { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
      .then(function (r) { return r.ok ? r.text() : ''; })
      .then(function (html) {
        var fresh = html ? new DOMParser().parseFromString(html, 'text/html').getElementById(key) : null;
        if (!fresh) return;
        card.querySelector('.clause-view').innerHTML = fresh.querySelector('.clause-view').innerHTML;
        card.querySelector('.clause-header > span').innerHTML = fresh.querySelector('.clause-header > span').innerHTML;
      })
      .catch(function () { /* the wording is saved; the view just keeps what it showed */ });
  }

  function saveClause(card) {
    var c = clauseParts(card);
    var buttons = card.querySelectorAll('[data-clause-save],[data-clause-restore]');
    buttons.forEach(function (b) { b.disabled = true; });
    c.ta.disabled = false;
    return Promise.resolve(saving).then(function () { return save(); }).then(function (ok) {
      if (!ok) { buttons.forEach(function (b) { b.disabled = false; }); return false; }
      return refreshClause(card).then(function () {
        c.ta.dataset.initial = c.ta.value;
        c.edit.hidden = true;
        c.view.hidden = false;
        c.btn.setAttribute('aria-expanded', 'false');
        if (c.ta.value === c.ta.dataset.original) c.ta.disabled = true;
        buttons.forEach(function (b) { b.disabled = false; });
        return true;
      });
    });
  }

  document.querySelectorAll('[data-clause-toggle]').forEach(function (btn) {
    var card = btn.closest('[data-clause]');
    var c = clauseParts(card);
    c.ta.dataset.initial = c.ta.value;
    btn.addEventListener('click', function () { if (c.edit.hidden) openClause(card); else closeClause(card); });
  });

  document.querySelectorAll('[data-clause-restore]').forEach(function (btn) {
    var card = btn.closest('[data-clause]');
    var c = clauseParts(card);
    btn.addEventListener('click', function () {
      if (!window.confirm('Restore the original wording of this section? Your edits to it will be removed.')) return;
      c.ta.disabled = false;
      c.ta.value = c.ta.dataset.original; // text identical to the original removes the edit at the Admin
      grow(c.ta);
      saveClause(card);
    });
  });

  document.querySelectorAll('[data-clause-save]').forEach(function (btn) {
    var card = btn.closest('[data-clause]');
    btn.addEventListener('click', function () { saveClause(card); });
  });

  // the number fields of a clause appear twice (in the text and beside the textarea): keep both copies equal
  form.addEventListener('input', function (e) {
    var key = e.target.dataset && e.target.dataset.sync;
    if (!key) return;
    form.querySelectorAll('[data-sync="' + key + '"]').forEach(function (other) { if (other !== e.target) other.value = e.target.value; });
  });

  // demo mode: PAN / GST / bank fields are never validated; when the vendor leaves a field that has a value it shows a green "Verified"
  form.querySelectorAll('[data-demo-valid]').forEach(function (input) {
    var badge = input.parentNode.querySelector('[data-demo-valid-badge]');
    function show(on) {
      input.classList.toggle('is-valid', on);
      if (badge) badge.hidden = !on;
    }
    input.addEventListener('input', function () { show(false); });                        // typing: not shown yet
    input.addEventListener('blur', function () { show(input.value.trim() !== ''); });     // lost focus: verified if filled in
  });

  // sections the reviewer commented on: bring the first one into view
  var flagged = document.querySelector('[data-flagged]');
  if (flagged) flagged.scrollIntoView({ block: 'start' });

  // ---- document upload: save the draft first, then submit the upload form (a page reload would lose unsaved edits) -------
  document.querySelectorAll('[data-doc-input]').forEach(function (input) {
    input.addEventListener('change', function () {
      if (!input.files.length) return;
      var target = document.getElementById(input.getAttribute('form'));
      Promise.resolve(save()).then(function () { if (target) target.submit(); });
    });
  });

  // ---- Back to top: shown once the page is scrolled -----------------------------------------------------------------------
  var toTop = document.querySelector('[data-back-to-top]');
  if (toTop) {
    var toggleTop = function () { toTop.hidden = window.pageYOffset < 300; };
    window.addEventListener('scroll', toggleTop, { passive: true });
    toggleTop();
    toTop.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });
  }
})();
