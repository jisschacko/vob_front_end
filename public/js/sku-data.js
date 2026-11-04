/* "SKU Data" popup of the Margin & Rebate section: type an article number (suggestions appear), see the description, enter the margins,
   confirm, and the rows are added to the margin table on the page. No inline script (CSP); the popup inputs have no name, so they are never posted. */
(function () {
  'use strict';

  var dialog = document.getElementById('sku-dialog');
  var openBtn = document.querySelector('[data-sku-open]');
  var form = document.getElementById('tot-form');
  if (!dialog || !openBtn || !form || typeof dialog.showModal !== 'function') return;

  var articles = [];
  try { articles = JSON.parse(dialog.dataset.articles || '[]'); } catch (e) { articles = []; }
  var byNumber = {};
  articles.forEach(function (a) { byNumber[String(a.article)] = a.description || ''; });

  var tbody = dialog.querySelector('[data-sku-rows]');
  var errorEl = dialog.querySelector('[data-sku-error]');
  var confirmEl = dialog.querySelector('[data-sku-confirm]');
  var summary = dialog.querySelector('[data-sku-summary]');
  var firstRows = parseInt(dialog.dataset.rows || '4', 10) || 4;
  var uid = 0;

  // typing in the popup must not trigger the page's autosave
  ['input', 'change'].forEach(function (type) { dialog.addEventListener(type, function (e) { e.stopPropagation(); }); });

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function showError(text) { errorEl.textContent = text; errorEl.hidden = !text; }

  function closePanels(except) {
    dialog.querySelectorAll('.sku-panel').forEach(function (p) { if (p !== except) p.hidden = true; });
  }

  /** articles chosen in the other rows */
  function taken(exceptRow) {
    var t = {};
    tbody.querySelectorAll('tr').forEach(function (tr) { if (tr !== exceptRow && tr.dataset.article) t[tr.dataset.article] = true; });
    return t;
  }

  // ---- one popup row: [article auto-suggest] [description] [front end margin] [back end margin] ----
  function buildRow() {
    var tr = el('tr');
    tr.dataset.article = ''; // set only while the typed text is a listed, unused article number

    var id = 'sku-art-' + (++uid);
    var tdA = el('td');
    var combo = el('div', 'sku-combo');
    var input = el('input', 'f sku-article');
    input.type = 'text';
    input.id = id;
    input.placeholder = 'Type article no.';
    input.autocomplete = 'off';
    input.maxLength = 20;
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', id + '-list');
    input.setAttribute('aria-label', 'Article');
    var panel = el('div', 'sku-panel');
    panel.hidden = true;
    var list = el('ul', 'sku-list');
    list.id = id + '-list';
    list.setAttribute('role', 'listbox');
    panel.appendChild(list);
    combo.appendChild(input);
    combo.appendChild(panel);
    tdA.appendChild(combo);

    var tdD = el('td');
    var desc = el('input', 'f sku-desc');
    desc.type = 'text';
    desc.readOnly = true;
    desc.tabIndex = -1;
    desc.placeholder = 'Description';
    desc.setAttribute('aria-label', 'Description');
    tdD.appendChild(desc);

    var tdF = el('td');
    var fe = el('input', 'f');
    fe.type = 'text'; fe.maxLength = 200; fe.setAttribute('aria-label', 'Front end margin');
    tdF.appendChild(fe);
    var tdB = el('td');
    var be = el('input', 'f');
    be.type = 'text'; be.maxLength = 200; be.setAttribute('aria-label', 'Back end margin');
    tdB.appendChild(be);

    var active = -1;

    function options() { return Array.prototype.slice.call(list.querySelectorAll('.sku-opt:not(.is-taken)')); }
    function setActive(i) {
      var items = options();
      items.forEach(function (o) { o.classList.remove('is-active'); o.removeAttribute('aria-selected'); });
      active = items.length ? (i + items.length) % items.length : -1;
      if (active >= 0) {
        items[active].classList.add('is-active');
        items[active].setAttribute('aria-selected', 'true');
        input.setAttribute('aria-activedescendant', items[active].id);
        items[active].scrollIntoView({ block: 'nearest' });
      } else {
        input.removeAttribute('aria-activedescendant');
      }
    }

    function suggest() {
      var q = input.value.trim().toLowerCase();
      var used = taken(tr);
      list.innerHTML = '';
      active = -1;
      var n = 0;
      articles.forEach(function (a, i) {
        var num = String(a.article);
        if (q && num.toLowerCase().indexOf(q) === -1 && String(a.description || '').toLowerCase().indexOf(q) === -1) return;
        var li = el('li', 'sku-opt');
        li.id = id + '-o' + i;
        li.setAttribute('role', 'option');
        li.appendChild(el('span', 'sku-opt-num', num));
        li.appendChild(el('span', 'sku-opt-desc', a.description || ''));
        if (used[num]) { li.classList.add('is-taken'); li.setAttribute('aria-disabled', 'true'); li.title = 'Already chosen in another row'; }
        else { li.addEventListener('mousedown', function (e) { e.preventDefault(); choose(num); }); } // mousedown: before the input loses focus
        list.appendChild(li);
        n++;
      });
      if (!n) list.appendChild(el('li', 'sku-none', 'No matching article'));
      closePanels(panel);
      panel.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    }

    function hide() { panel.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); }

    function choose(num) {
      input.value = num;
      sync();
      hide();
      showError('');
      fe.focus();
    }

    /** the row holds an article only when the text is exactly a listed number that no other row uses */
    function sync() {
      var num = input.value.trim();
      var ok = Object.prototype.hasOwnProperty.call(byNumber, num) && !taken(tr)[num];
      tr.dataset.article = ok ? num : '';
      desc.value = ok ? byNumber[num] : '';
      input.classList.toggle('is-valid', ok);
    }

    input.addEventListener('focus', suggest);
    input.addEventListener('click', function () { if (panel.hidden) suggest(); });
    input.addEventListener('input', function () { sync(); suggest(); showError(''); });
    input.addEventListener('blur', function () { sync(); hide(); });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); if (panel.hidden) suggest(); setActive(active + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); if (panel.hidden) suggest(); setActive(active - 1); }
      else if (e.key === 'Enter') {
        if (!panel.hidden && active >= 0) { e.preventDefault(); choose(options()[active].querySelector('.sku-opt-num').textContent); }
        else if (!panel.hidden) { e.preventDefault(); hide(); }
      }
      else if (e.key === 'Escape' && !panel.hidden) { e.preventDefault(); e.stopPropagation(); hide(); } // close the list, not the popup
    });

    tr.appendChild(tdA); tr.appendChild(tdD); tr.appendChild(tdF); tr.appendChild(tdB);
    tr._fields = { article: input, desc: desc, fe: fe, be: be };
    return tr;
  }

  function reset() {
    tbody.innerHTML = '';
    for (var i = 0; i < firstRows; i++) tbody.appendChild(buildRow());
    showError('');
    confirmEl.hidden = true;
  }

  // ---- open / cancel ----
  openBtn.addEventListener('click', function () { reset(); dialog.showModal(); });
  dialog.querySelectorAll('[data-sku-cancel]').forEach(function (b) { b.addEventListener('click', function () { dialog.close(); }); });
  dialog.addEventListener('click', function (e) { if (e.target === dialog) dialog.close(); }); // click on the dim backdrop
  dialog.querySelector('[data-sku-more]').addEventListener('click', function () { var r = buildRow(); tbody.appendChild(r); r._fields.article.focus(); });

  function picked() {
    var out = [];
    tbody.querySelectorAll('tr').forEach(function (tr) {
      if (!tr.dataset.article) return;
      out.push({ article: tr.dataset.article, description: byNumber[tr.dataset.article] || '', fe: tr._fields.fe.value.trim(), be: tr._fields.be.value.trim() });
    });
    return out;
  }

  /** a typed article that is not a listed (or is a repeated) number is an error, so nothing is silently dropped */
  function typedProblem() {
    var bad = null;
    tbody.querySelectorAll('tr').forEach(function (tr) {
      var typed = tr._fields.article.value.trim();
      if (bad || !typed || tr.dataset.article) return;
      bad = Object.prototype.hasOwnProperty.call(byNumber, typed) ? typed + ' is chosen in more than one row.' : typed + ' is not in the article list. Pick one of the suggestions.';
    });
    return bad;
  }

  // ---- Add: confirm the selected values first ----
  dialog.querySelector('[data-sku-add]').addEventListener('click', function () {
    var problem = typedProblem();
    if (problem) { showError(problem); return; }
    var rows = picked();
    if (!rows.length) { showError('Select at least one article.'); return; }
    showError('');
    summary.innerHTML = '';
    rows.forEach(function (r) {
      var tr = el('tr');
      [r.article, r.description, r.fe || '-', r.be || '-'].forEach(function (t) { tr.appendChild(el('td', '', t)); });
      summary.appendChild(tr);
    });
    confirmEl.hidden = false;
    confirmEl.querySelector('[data-sku-confirm-yes]').focus();
  });
  dialog.querySelector('[data-sku-back]').addEventListener('click', function () { confirmEl.hidden = true; });

  // ---- put the confirmed rows into the page's margin table ----
  var section = document.getElementById('margin_rebate');

  function marginRows() {
    return Array.prototype.filter.call(section.querySelectorAll('table.mini tr'), function (tr) { return tr.querySelector('[name^="form[margins]["]'); });
  }
  function isBlank(tr) {
    return Array.prototype.every.call(tr.querySelectorAll('[name^="form[margins]["]'), function (i) { return i.value.trim() === ''; });
  }
  function nextIndex(rows) {
    var max = -1;
    rows.forEach(function (tr) {
      tr.querySelectorAll('[name^="form[margins]["]').forEach(function (i) {
        var m = i.name.match(/^form\[margins\]\[(\d+)\]/);
        if (m) max = Math.max(max, parseInt(m[1], 10));
      });
    });
    return max + 1;
  }
  function newRowLike(last, index) {
    var tr = last.cloneNode(true);
    var oldIdx = (last.querySelector('[name^="form[margins]["]').name.match(/^form\[margins\]\[(\d+)\]/) || [])[1];
    tr.querySelectorAll('[name^="form[margins]["]').forEach(function (i) {
      i.name = i.name.replace('[margins][' + oldIdx + ']', '[margins][' + index + ']');
      if (i.id) i.id = i.id.replace('margins-' + oldIdx + '-', 'margins-' + index + '-');
      i.value = '';
      i.style.height = '';
    });
    tr.querySelectorAll('.error, [data-live-error]').forEach(function (n) { n.remove(); });
    last.parentNode.insertBefore(tr, last.nextSibling);

    return tr;
  }
  function setValue(input, value) {
    var max = parseInt(input.getAttribute('maxlength') || '0', 10);
    input.value = max ? value.slice(0, max) : value;
    input.dispatchEvent(new Event('input', { bubbles: true })); // grows the textarea and schedules the autosave
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }

  dialog.querySelector('[data-sku-confirm-yes]').addEventListener('click', function () {
    if (!section) { dialog.close(); return; }
    var rows = picked();
    var existing = marginRows();
    var blanks = existing.filter(isBlank);
    var idx = nextIndex(existing);

    rows.forEach(function (r) {
      var tr = blanks.shift();
      if (!tr) { tr = newRowLike(existing[existing.length - 1], idx++); existing.push(tr); }
      var cells = tr.querySelectorAll('[name^="form[margins]["]');
      setValue(cells[0], r.article + (r.description ? ' - ' + r.description : ''));
      setValue(cells[1], r.fe);
      setValue(cells[2], r.be);
    });

    dialog.close();
    section.scrollIntoView({ block: 'start' });
  });
})();
