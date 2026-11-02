/* Sign-in pages: the "show password" eye button. No inline script (CSP). The button is hidden until this script runs. */
(function () {
  'use strict';

  document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
    var input = document.getElementById(btn.getAttribute('data-toggle-password'));
    if (!input) return;
    btn.hidden = false;
    btn.addEventListener('click', function () {
      var hidden = input.type === 'password';
      input.type = hidden ? 'text' : 'password';
      btn.setAttribute('aria-label', hidden ? 'Hide password' : 'Show password');
    });
  });
})();
