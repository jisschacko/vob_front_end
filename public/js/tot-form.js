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
})();
