/**
 * assets/js/okv.js
 * OK Veggies core browser helpers. Vanilla JS, no jQuery, no dependencies.
 * A page sets window.OKV.csrf before using OKV.fetch for a state change.
 */
(function () {
  'use strict';
  window.OKV = window.OKV || {};

  OKV.ready = function (fn) {
    if (document.readyState !== 'loading') { fn(); }
    else { document.addEventListener('DOMContentLoaded', fn); }
  };

  OKV.escape = function (value) {
    var div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
  };

  // Format integer subunits (kobo) as naira for display. Mirrors Money::format.
  OKV.money = function (subunit, withKobo) {
    var neg = subunit < 0;
    var abs = Math.abs(subunit | 0);
    var naira = Math.floor(abs / 100);
    var kobo = abs % 100;
    var show = (withKobo === undefined) ? (kobo !== 0) : withKobo;
    var text = naira.toLocaleString('en-NG');
    if (show) { text += '.' + String(kobo).padStart(2, '0'); }
    return (neg ? '-' : '') + '₦' + text;
  };

  /**
   * fetch wrapper. Adds the CSRF token and JSON headers, returns the parsed
   * body, and surfaces a friendly error. Never a silent catch.
   */
  OKV.fetch = function (url, options) {
    options = options || {};
    var headers = options.headers || {};
    headers['X-Requested-With'] = 'fetch';
    if (window.OKV.csrf) { headers['X-CSRF-Token'] = window.OKV.csrf; }
    if (options.body && typeof options.body !== 'string') {
      headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(options.body);
    }
    options.headers = headers;
    options.credentials = 'same-origin';
    return fetch(url, options).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok || (data && data.status === 'error')) {
          var msg = (data && data.message) || 'Something went wrong. Please try again.';
          OKV.toast(msg, 'error');
          throw new Error(msg);
        }
        return data;
      });
    });
  };

  // Sheets. Opened by data-sheet-open="id", closed by Close, backdrop, Escape
  // or a swipe down. Focus enters the dialog and returns to the trigger.
  var lastFocus = null;
  var sheetsBound = false;

  function focusables(within) {
    return Array.prototype.filter.call(
      within.querySelectorAll('a[href], button:not([disabled]), input:not([type=hidden]), select, textarea, [tabindex]:not([tabindex="-1"])'),
      function (el) {
        return el.offsetParent !== null || el === document.activeElement;
      }
    );
  }

  OKV.openSheet = function (backdrop) {
    if (!backdrop || !backdrop.hidden) { return; }
    lastFocus = document.activeElement;
    backdrop.hidden = false;
    var panel = backdrop.querySelector('[role="dialog"]');
    if (panel) { panel.focus({ preventScroll: true }); }
    document.addEventListener('keydown', onSheetKey, true);
  };

  OKV.closeSheet = function (backdrop) {
    if (!backdrop || backdrop.hidden) { return; }
    backdrop.hidden = true;
    document.removeEventListener('keydown', onSheetKey, true);
    if (lastFocus && document.contains(lastFocus)) { lastFocus.focus({ preventScroll: true }); }
  };

  function onSheetKey(event) {
    var backdrop = document.querySelector('.okv-sheet-backdrop:not([hidden])');
    if (!backdrop) { return; }
    if (event.key === 'Escape') {
      event.preventDefault();
      OKV.closeSheet(backdrop);
      return;
    }
    if (event.key !== 'Tab') { return; }
    var panel = backdrop.querySelector('[role="dialog"]');
    if (!panel) { return; }
    var list = focusables(panel);
    if (list.length === 0) { return; }
    var first = list[0];
    var last = list[list.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function wireSwipe(backdrop) {
    var panel = backdrop.querySelector('[role="dialog"]');
    if (!panel) { return; }
    var startY = null;
    var dy = 0;
    panel.addEventListener('touchstart', function (event) {
      if (event.touches.length !== 1) { return; }
      startY = event.touches[0].clientY;
      dy = 0;
      panel.style.transition = 'none';
    }, { passive: true });
    panel.addEventListener('touchmove', function (event) {
      if (startY === null) { return; }
      dy = event.touches[0].clientY - startY;
      if (dy > 0) { panel.style.transform = 'translateY(' + Math.round(dy * 0.6) + 'px)'; }
    }, { passive: true });
    panel.addEventListener('touchend', function () {
      if (startY === null) { return; }
      startY = null;
      panel.style.transition = 'transform 240ms cubic-bezier(0.34, 1.56, 0.64, 1)';
      panel.style.transform = '';
      if (dy > 90) { OKV.closeSheet(backdrop); }
      dy = 0;
    });
  }

  OKV.bindSheets = function () {
    if (sheetsBound) { return; }
    sheetsBound = true;
    document.querySelectorAll('[data-sheet-open]').forEach(function (trigger) {
      trigger.addEventListener('click', function () {
        var target = document.getElementById(trigger.getAttribute('data-sheet-open'));
        if (target) { OKV.openSheet(target); }
      });
    });
    document.querySelectorAll('.okv-sheet-backdrop').forEach(function (backdrop) {
      backdrop.addEventListener('click', function (event) {
        if (event.target === backdrop) { OKV.closeSheet(backdrop); }
      });
      backdrop.querySelectorAll('[data-sheet-close]').forEach(function (button) {
        button.addEventListener('click', function () { OKV.closeSheet(backdrop); });
      });
      // okv-motion.js owns the open and close motion and the swipe-down
      // dismiss when it is loaded, so its GSAP tweens stay the only writer of
      // the panel transform. Without it this plain touch swipe still ships.
      if (!(window.OkvMotion && window.OkvMotion.ownsSheets)) { wireSwipe(backdrop); }
    });
  };

  OKV.ready(function () { OKV.bindSheets(); });

  // One tap, one post. A form marked data-once disables its buttons the moment
  // it submits, so a double tap cannot post it twice. A page restored from the
  // back button (Paystack, then Back) gets its buttons back.
  function releaseOnce(form) {
    form.removeAttribute('data-once-busy');
    form.querySelectorAll('button[aria-busy="true"]').forEach(function (button) {
      button.disabled = false;
      button.removeAttribute('aria-busy');
    });
  }
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form || !form.hasAttribute || !form.hasAttribute('data-once')) { return; }
    if (form.getAttribute('data-once-busy') === '1') { event.preventDefault(); return; }
    form.setAttribute('data-once-busy', '1');
    // Disable after the browser has read the form, so the post still goes out.
    setTimeout(function () {
      form.querySelectorAll('button[type="submit"], button:not([type])').forEach(function (button) {
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
      });
    }, 0);
  });
  window.addEventListener('pageshow', function (event) {
    if (!event.persisted) { return; }
    document.querySelectorAll('form[data-once]').forEach(releaseOnce);
  });

  // Transient bottom banner. type: 'ok' | 'error'. An optional action adds a
  // keyboard-accessible button, used by the basket's short-lived Undo affordance.
  OKV.toast = function (message, type, action) {
    var host = document.getElementById('okv-toast');
    if (!host) {
      host = document.createElement('div');
      host.id = 'okv-toast';
      host.setAttribute('aria-live', 'polite');
      host.setAttribute('aria-atomic', 'false');
      host.style.cssText = 'position:fixed;left:50%;bottom:20px;transform:translateX(-50%);z-index:9999;display:flex;flex-direction:column;align-items:center;gap:8px;max-width:calc(100vw - 24px);pointer-events:none;';
      document.body.appendChild(host);
    }
    var toast = document.createElement('div');
    var bg = type === 'error' ? '#C8321E' : '#0F5132';
    toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
    toast.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:16px;background:' + bg + ';color:#fff;padding:8px 12px 8px 16px;border-radius:8px;box-shadow:0 12px 32px rgba(3,16,10,0.14);font:500 15px/1.4 sans-serif;max-width:calc(100vw - 24px);pointer-events:auto;';
    var text = document.createElement('span');
    text.textContent = message;
    toast.appendChild(text);

    var dismissTimer;
    var removeTimer;
    function removeToast() {
      clearTimeout(dismissTimer);
      clearTimeout(removeTimer);
      toast.remove();
    }

    if (action && typeof action.onClick === 'function') {
      var button = document.createElement('button');
      button.type = 'button';
      button.textContent = action.label || 'Undo';
      button.style.cssText = 'flex:none;min-height:44px;padding:0 12px;border:1px solid rgba(255,255,255,.65);border-radius:6px;background:transparent;color:inherit;font:700 14px/1 sans-serif;text-decoration:underline;text-underline-offset:3px;cursor:pointer;';
      button.addEventListener('click', function () {
        removeToast();
        action.onClick();
      });
      toast.appendChild(button);
    }

    host.appendChild(toast);
    var duration = action ? 7000 : 3600;
    dismissTimer = setTimeout(function () {
      toast.style.opacity = '0';
      toast.style.transition = 'opacity 240ms';
    }, duration - 400);
    removeTimer = setTimeout(removeToast, duration);
  };
})();
