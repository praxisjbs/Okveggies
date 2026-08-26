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

  // Transient bottom banner. type: 'ok' | 'error'.
  OKV.toast = function (message, type) {
    var host = document.getElementById('okv-toast');
    if (!host) {
      host = document.createElement('div');
      host.id = 'okv-toast';
      host.style.cssText = 'position:fixed;left:50%;bottom:20px;transform:translateX(-50%);z-index:9999;display:flex;flex-direction:column;gap:8px;';
      document.body.appendChild(host);
    }
    var el = document.createElement('div');
    var bg = type === 'error' ? '#C8321E' : '#0F5132';
    el.style.cssText = 'background:' + bg + ';color:#fff;padding:12px 16px;border-radius:6px;box-shadow:0 12px 32px rgba(3,16,10,0.14);font:500 15px/1.4 sans-serif;max-width:90vw;';
    el.textContent = message;
    host.appendChild(el);
    setTimeout(function () { el.style.opacity = '0'; el.style.transition = 'opacity 240ms'; }, 3200);
    setTimeout(function () { el.remove(); }, 3600);
  };
})();
