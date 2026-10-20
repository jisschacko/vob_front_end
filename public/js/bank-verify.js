/* Bank verification page: counts down the 10 minute link and polls the server (POST, CSRF) until the payment is seen. */
(function () {
  'use strict';

  var box = document.querySelector('.bank-verify');
  if (!box) return;

  var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var statusEl = document.getElementById('bank-status');
  var countdownEl = document.getElementById('bank-countdown');
  var expires = new Date(box.dataset.expires).getTime();
  var finished = false;
  var busy = false;

  function pad(n) { return (n < 10 ? '0' : '') + n; }

  function tick() {
    var left = Math.max(0, Math.round((expires - Date.now()) / 1000));
    countdownEl.textContent = pad(Math.floor(left / 60)) + ':' + pad(left % 60);
    if (left === 0 && !finished) {
      // Ask once more: Cashfree is the authority on whether the payment arrived in time.
      check(true);
    }
  }

  function check(final) {
    if (finished || busy) return;
    busy = true;
    fetch(box.dataset.statusUrl, {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
      credentials: 'same-origin',
      redirect: 'manual'
    }).then(function (r) {
      if (r.status === 401 || r.status === 419 || r.type === 'opaqueredirect') {
        finished = true;
        statusEl.textContent = 'Your session has ended. Please sign in again.';
        return null;
      }
      return r.json();
    }).then(function (j) {
      busy = false;
      if (!j) return;
      statusEl.textContent = j.message;
      if (j.done) {
        finished = true;
        setTimeout(function () { window.location.href = box.dataset.homeUrl; }, 1800);
      } else if (final) {
        finished = true;
        statusEl.textContent = 'The payment link expired. Go back to the form and start the verification again.';
      }
    }).catch(function () {
      busy = false;
      statusEl.textContent = 'Could not check the status. Retrying...';
    });
  }

  document.getElementById('bank-check').addEventListener('click', function () { check(false); });
  setInterval(tick, 1000);
  setInterval(function () { check(false); }, 4000);
  tick();
})();
