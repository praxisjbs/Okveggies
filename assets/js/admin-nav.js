/**
 * assets/js/admin-nav.js
 * -----------------------------------------------------------------------------
 * OK Veggies. The admin sidebar as a phone panel (includes/components/admin/
 * sidebar.php, styled by .okv-admin-nav in assets/css/src/tailwind source).
 *
 * From 768px up the sidebar is simply a column of the shell, always in view,
 * and this file leaves it alone. Below that it is a full-screen panel, and the
 * panel owes a phone three native behaviours a stylesheet cannot give it:
 *
 *   1. Nothing scrolls out of the menu. The panel is fixed to the viewport, so
 *      the page is covered rather than pushed down; the page itself is locked
 *      while the panel is open; and the nav list scrolls inside the panel with
 *      overscroll contained, so a flick at either end of the list cannot carry
 *      on into the page behind it.
 *   2. Back closes the menu. Opening pushes one history entry, so the phone's
 *      Back button closes the panel the way a native drawer does instead of
 *      leaving the screen. Choosing a destination inside the panel spends that
 *      entry on the way out, so Back on the screen that arrives lands on the
 *      page before the menu, not on this one again.
 *   3. It behaves like a dialog. While open the panel carries role and
 *      aria-modal, Tab is kept inside it, Escape closes it, and focus returns
 *      to the hamburger that opened it. The attributes come off on close, so
 *      the desktop sidebar keeps its ordinary landmark role.
 *
 * Everything it wires is server-rendered. With JavaScript off the phone panel
 * stays closed, exactly as before this file existed, and every screen is still
 * reachable by address; the desktop sidebar is untouched either way.
 * -----------------------------------------------------------------------------
 */
(function () {
  'use strict';

  var panel = document.getElementById('okv-admin-sidebar');
  var toggle = document.querySelector('[data-okv-nav-toggle]');
  if (!panel || !toggle) { return; }

  var closeButton = panel.querySelector('[data-okv-nav-close]');
  var scrollRegion = panel.querySelector('[data-okv-nav-scroll]');
  var wide = window.matchMedia('(min-width: 768px)');

  var open = false;
  var ownsEntry = false; // the history entry this panel pushed is still ours
  var opener = null;     // what had focus when the panel opened
  var pending = '';      // where the panel is leaving for, if anywhere
  var leaveTimer = null;

  function lockPage(on) {
    // Both elements. Locking the body alone still leaves iOS Safari able to
    // scroll the page behind a fixed panel while the address bar moves.
    document.documentElement.style.overflow = on ? 'hidden' : '';
    document.body.style.overflow = on ? 'hidden' : '';
  }

  function focusable() {
    return Array.prototype.slice.call(
      panel.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])')
    ).filter(function (item) {
      return !item.hidden && !item.classList.contains('hidden') && item.getAttribute('aria-hidden') !== 'true';
    });
  }

  function openPanel() {
    if (open || wide.matches) { return; }
    open = true;
    opener = document.activeElement;
    panel.hidden = false;
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    toggle.setAttribute('aria-expanded', 'true');
    lockPage(true);
    if (scrollRegion) { scrollRegion.scrollTop = 0; }
    try {
      window.history.pushState({ okvAdminNav: true }, '', window.location.href);
      ownsEntry = true;
    } catch (error) {
      // A browser that refuses the entry (an old WebView, a file:// page) still
      // gets the panel. Only Back keeps its ordinary meaning there.
      ownsEntry = false;
    }
    var first = closeButton || focusable()[0];
    if (first) { first.focus(); }
  }

  /**
   * Closes the panel. Returns true when it handed a history entry back, which
   * means a popstate is on its way to the caller.
   *
   * @param {boolean} entryAlreadyPopped the traversal that closed us has
   *        already consumed the entry, so there is nothing to give back.
   */
  function closePanel(entryAlreadyPopped) {
    if (!open) { return false; }
    open = false;
    panel.hidden = true;
    panel.removeAttribute('role');
    panel.removeAttribute('aria-modal');
    toggle.setAttribute('aria-expanded', 'false');
    lockPage(false);
    if (opener && typeof opener.focus === 'function' && document.contains(opener)) {
      opener.focus();
    } else {
      toggle.focus();
    }
    opener = null;
    var spend = ownsEntry;
    ownsEntry = false;
    if (spend && !entryAlreadyPopped) {
      window.history.back();
      return true;
    }
    return false;
  }

  function go() {
    if (!pending) { return; }
    var href = pending;
    pending = '';
    if (leaveTimer !== null) {
      window.clearTimeout(leaveTimer);
      leaveTimer = null;
    }
    window.location.assign(href);
  }

  function leaveTo(href) {
    pending = href;
    if (closePanel(false)) {
      // The browser owes us a popstate for the entry just handed back. Leave
      // when it lands; the timer is the belt to that braces, so a browser that
      // never fires it still gets the reader to the destination.
      leaveTimer = window.setTimeout(go, 300);
      return;
    }
    go();
  }

  toggle.addEventListener('click', function () {
    if (open) { closePanel(false); } else { openPanel(); }
  });

  if (closeButton) {
    closeButton.addEventListener('click', function () { closePanel(false); });
  }

  // A destination chosen inside the panel. The entry pushed on open is spent
  // before the browser leaves, so Back on the next screen does not land on this
  // one again.
  panel.addEventListener('click', function (event) {
    if (!open || event.defaultPrevented || event.button !== 0) { return; }
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) { return; }
    var link = event.target && event.target.closest ? event.target.closest('a[href]') : null;
    if (!link || link.hasAttribute('download')) { return; }
    if (link.target && link.target !== '_self') { return; }
    var href = link.getAttribute('href') || '';
    if (href === '' || href.charAt(0) === '#') {
      closePanel(false);
      return;
    }
    event.preventDefault();
    leaveTo(link.href);
  });

  window.addEventListener('popstate', function () {
    if (pending) { go(); return; }
    if (open) { closePanel(true); }
  });

  // A page restored from the back/forward cache comes back exactly as it was
  // left, an open panel and a locked page included, so put it back to rest.
  window.addEventListener('pageshow', function (event) {
    if (!event.persisted) { return; }
    if (open) { closePanel(true); return; }
    lockPage(false);
  });

  document.addEventListener('keydown', function (event) {
    if (!open) { return; }
    if (event.key === 'Escape') {
      event.preventDefault();
      closePanel(false);
      return;
    }
    if (event.key !== 'Tab') { return; }
    var items = focusable();
    if (items.length === 0) {
      event.preventDefault();
      return;
    }
    var first = items[0];
    var last = items[items.length - 1];
    var inside = panel.contains(document.activeElement);
    if (event.shiftKey && (document.activeElement === first || !inside)) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && (document.activeElement === last || !inside)) {
      event.preventDefault();
      first.focus();
    }
  });

  // Rotating a phone to landscape, or any crossing of 768px, gives the sidebar
  // back to the shell. It is not a panel there and must not hold the page
  // locked.
  function onWidthChange() {
    if (wide.matches && open) { closePanel(false); }
  }
  if (typeof wide.addEventListener === 'function') {
    wide.addEventListener('change', onWidthChange);
  } else if (typeof wide.addListener === 'function') {
    wide.addListener(onWidthChange);
  }
}());
