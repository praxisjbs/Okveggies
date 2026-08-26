/**
 * assets/js/okv-rbac.js
 * OK Veggies. Client-side permission gating for the admin panel. This is UX
 * only: it hides controls the user cannot use. The server re-checks every
 * action. window.OKV.rbac.permissions is injected by Rbac::jsBootstrap().
 */
(function () {
  'use strict';
  window.OKV = window.OKV || {};
  var rbac = window.OKV.rbac || { permissions: [] };

  OKV.can = function (perm) {
    var perms = rbac.permissions || [];
    if (perms.indexOf('*') !== -1) { return true; }
    if (perms.indexOf(perm) !== -1) { return true; }
    var dot = perm.indexOf('.');
    if (dot !== -1 && perms.indexOf(perm.slice(0, dot) + '.*') !== -1) { return true; }
    return false;
  };

  OKV.canAny = function (list) {
    for (var i = 0; i < list.length; i++) { if (OKV.can(list[i])) { return true; } }
    return false;
  };

  function gate(root) {
    (root || document).querySelectorAll('[data-perm]').forEach(function (el) {
      if (!OKV.can(el.getAttribute('data-perm'))) { el.hidden = true; el.style.display = 'none'; }
    });
    (root || document).querySelectorAll('[data-perm-any]').forEach(function (el) {
      var list = el.getAttribute('data-perm-any').split(',').map(function (s) { return s.trim(); });
      if (!OKV.canAny(list)) { el.hidden = true; el.style.display = 'none'; }
    });
  }

  OKV.ready = OKV.ready || function (fn) {
    if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); }
  };

  OKV.ready(function () {
    gate(document);
    // Re-gate DOM inserted by AJAX.
    var mo = new MutationObserver(function (muts) {
      muts.forEach(function (m) {
        m.addedNodes.forEach(function (n) { if (n.nodeType === 1) { gate(n); } });
      });
    });
    mo.observe(document.body, { childList: true, subtree: true });
  });
})();
