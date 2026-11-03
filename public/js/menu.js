/* Left menu: collapsed when the page opens, open while the pointer is over it. No inline script (CSP). */
(function () {
  'use strict';

  var layout = document.querySelector('.layout');
  var menu = document.querySelector('[data-menu]');
  if (!layout || !menu) return;

  menu.addEventListener('mouseenter', function () { layout.classList.remove('menu-collapsed'); });
  menu.addEventListener('mouseleave', function () { layout.classList.add('menu-collapsed'); });
})();
