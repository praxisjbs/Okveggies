/**
 * assets/js/admin-pricing.js
 * OK Veggies. The pricing screen. Inline price edits, the bulk category move
 * with its preview, the spreadsheet import with its preview, and the per-product
 * history panel.
 *
 * Everything here is a convenience. The server re-checks every permission and
 * CSRF token, re-validates a bulk move and re-reads an import against the live
 * database before it writes anything, so nothing below is load bearing for
 * correctness. All user data is written with textContent, never innerHTML.
 */
(function () {
  'use strict';

  var ENDPOINT = '/api/v1/pricing.php';

  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); }
    else { document.addEventListener('DOMContentLoaded', fn); }
  }

  function toast(message, type) {
    if (window.OKV && OKV.toast) { OKV.toast(message, type); }
  }

  function naira(subunit) {
    if (window.OKV && OKV.money) { return OKV.money(subunit); }
    return '₦' + Math.round(subunit / 100).toLocaleString('en-NG');
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) { node.className = className; }
    if (text !== undefined && text !== null) { node.textContent = String(text); }
    return node;
  }

  function post(body) {
    return fetch(ENDPOINT, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
      body: body
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        return { ok: res.ok, data: data || {} };
      });
    });
  }

  // --- Inline price edit -----------------------------------------------------

  function wirePriceForm(form) {
    var input = form.querySelector('[data-price-input]');
    var save = form.querySelector('[data-price-save]');
    if (!input) { return; }

    function dirty() {
      return input.value.trim() !== (input.getAttribute('data-original') || '').trim();
    }
    function refresh() {
      if (save) { save.hidden = !dirty(); }
    }

    input.addEventListener('input', refresh);
    input.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        input.value = input.getAttribute('data-original') || '';
        refresh();
        input.blur();
      }
    });

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (!dirty()) { return; }

      // Read the form before disabling anything: a disabled field is left out
      // of FormData, so the price would never reach the server.
      var body = new URLSearchParams(new FormData(form));
      input.disabled = true;
      if (save) { save.disabled = true; }

      post(body).then(function (result) {
        input.disabled = false;
        if (save) { save.disabled = false; }

        if (result.ok && result.data.status === 'ok') {
          // Show the price the server actually stored, not what was typed.
          var stored = Math.round(result.data['new'] / 100);
          input.value = String(stored);
          input.setAttribute('data-original', String(stored));
          refresh();
          toast(result.data.message || 'Price updated.', 'ok');
          return;
        }
        input.value = input.getAttribute('data-original') || '';
        refresh();
        toast(result.data.message || 'We could not change that price.', 'error');
      }).catch(function () {
        input.disabled = false;
        if (save) { save.disabled = false; }
        toast('We could not reach the server. Check your connection and try again.', 'error');
      });
    });

    refresh();
  }

  // --- Bulk category move ----------------------------------------------------

  function bulkTable(rows, heading, tone) {
    var wrap = el('div', 'mt-4');
    wrap.appendChild(el('p', 'font-semibold text-sm ' + (tone || 'text-ink'), heading));

    var table = el('table', 'w-full text-sm mt-2');
    var thead = el('thead');
    var headRow = el('tr', 'text-left text-ink-60 border-b border-mist');
    ['Product', 'Now', 'Becomes'].forEach(function (label) {
      var th = el('th', 'py-1 pr-4 font-medium', label);
      th.setAttribute('scope', 'col');
      headRow.appendChild(th);
    });
    thead.appendChild(headRow);
    table.appendChild(thead);

    var tbody = el('tbody');
    rows.forEach(function (row) {
      var tr = el('tr', 'border-b border-mist last:border-0');
      tr.appendChild(el('td', 'py-1 pr-4', row.name));
      tr.appendChild(el('td', 'py-1 pr-4 font-mono text-ink-60', naira(row.old)));
      var becomes = el('td', 'py-1 pr-4 font-mono');
      becomes.textContent = row.reason ? row.reason : naira(row['new']);
      tr.appendChild(becomes);
      tbody.appendChild(tr);
    });
    table.appendChild(tbody);

    var scroller = el('div', 'overflow-x-auto');
    scroller.appendChild(table);
    wrap.appendChild(scroller);
    return wrap;
  }

  function wireBulk() {
    var panel = document.querySelector('[data-bulk-panel]');
    var form = document.querySelector('[data-bulk-form]');
    if (!panel || !form) { return; }

    var open = document.querySelector('[data-bulk-open]');
    var close = panel.querySelector('[data-bulk-close]');
    var result = panel.querySelector('[data-bulk-result]');
    var applyButton = form.querySelector('[data-bulk-apply]');
    var modeSelect = form.querySelector('select[name="mode"]');
    var amountLabel = form.querySelector('[data-bulk-amount-label]');
    var amountHint = form.querySelector('[data-bulk-hint]');

    if (open) {
      open.addEventListener('click', function () {
        panel.hidden = false;
        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        var first = form.querySelector('select, input');
        if (first) { first.focus(); }
      });
    }
    if (close) {
      close.addEventListener('click', function () {
        panel.hidden = true;
        if (open) { open.focus(); }
      });
    }

    function describeMode() {
      var flat = modeSelect && modeSelect.value === 'flat';
      if (amountLabel) { amountLabel.textContent = flat ? 'Amount in naira' : 'Percentage'; }
      if (amountHint) {
        amountHint.textContent = flat
          ? '500 adds ₦500 to every price. Use -500 to take it off.'
          : '10 raises by a tenth. Use -10 to drop it.';
      }
    }
    if (modeSelect) { modeSelect.addEventListener('change', function () { describeMode(); hideResult(); }); }
    describeMode();

    function hideResult() {
      if (result) { result.hidden = true; result.textContent = ''; }
      if (applyButton) { applyButton.hidden = true; }
    }
    ['category_id', 'amount'].forEach(function (name) {
      var field = form.querySelector('[name="' + name + '"]');
      if (field) { field.addEventListener('input', hideResult); field.addEventListener('change', hideResult); }
    });

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var body = new URLSearchParams(new FormData(form));
      body.set('action', 'preview_bulk');

      post(body).then(function (res) {
        if (!result) { return; }
        result.textContent = '';
        result.hidden = false;

        if (!res.ok || res.data.status !== 'ok') {
          result.appendChild(el('p', 'rounded-md bg-tomato-tint text-tomato text-sm px-4 py-3',
            res.data.message || 'We could not work that out.'));
          if (applyButton) { applyButton.hidden = true; }
          return;
        }

        var moving = res.data.moving || 0;
        result.appendChild(el('p', 'text-sm text-ink',
          moving === 1 ? '1 price would change.' : moving + ' prices would change.'));

        if (res.data.rows && res.data.rows.length) {
          result.appendChild(bulkTable(res.data.rows, 'These would change', 'text-ink'));
        }
        if (res.data.skipped && res.data.skipped.length) {
          result.appendChild(bulkTable(res.data.skipped, 'Passed over, no price set yet', 'text-ink-60'));
        }
        if (res.data.blocked && res.data.blocked.length) {
          result.appendChild(bulkTable(res.data.blocked, 'These stop the move', 'text-tomato'));
          result.appendChild(el('p', 'text-sm text-tomato mt-2',
            'A bulk move is all or nothing. Change the amount so every product can take it.'));
        }
        if (applyButton) { applyButton.hidden = !res.data.ok || moving === 0; }
      }).catch(function () {
        toast('We could not reach the server. Check your connection and try again.', 'error');
      });
    });

    if (applyButton) {
      applyButton.addEventListener('click', function () {
        var reason = form.querySelector('[name="reason"]');
        if (reason && reason.value.trim() === '') {
          toast('Say why prices are moving. It goes into the history.', 'error');
          reason.focus();
          return;
        }
        applyButton.disabled = true;
        var body = new URLSearchParams(new FormData(form));
        body.set('action', 'apply_bulk');

        post(body).then(function (res) {
          applyButton.disabled = false;
          if (res.ok && res.data.status === 'ok') {
            toast(res.data.message || 'Prices updated.', 'ok');
            setTimeout(function () { window.location.reload(); }, 500);
            return;
          }
          toast(res.data.message || 'Nothing was changed.', 'error');
        }).catch(function () {
          applyButton.disabled = false;
          toast('We could not reach the server. Check your connection and try again.', 'error');
        });
      });
    }
  }

  // --- Spreadsheet import ----------------------------------------------------

  function wireImport() {
    var panel = document.querySelector('[data-import-panel]');
    var form = document.querySelector('[data-import-form]');
    if (!panel || !form) { return; }

    var open = document.querySelector('[data-import-open]');
    var close = panel.querySelector('[data-import-close]');
    var result = panel.querySelector('[data-import-result]');

    if (open) {
      open.addEventListener('click', function () {
        panel.hidden = false;
        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        var file = form.querySelector('input[type="file"]');
        if (file) { file.focus(); }
      });
    }
    if (close) {
      close.addEventListener('click', function () {
        panel.hidden = true;
        if (open) { open.focus(); }
      });
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var body = new FormData(form);
      body.set('action', 'preview_import');

      var button = form.querySelector('button[type="submit"]');
      if (button) { button.disabled = true; button.textContent = 'Reading'; }

      post(body).then(function (res) {
        if (button) { button.disabled = false; button.textContent = 'Check this sheet'; }
        if (!result) { return; }
        result.textContent = '';
        result.hidden = false;

        if (!res.ok || res.data.status !== 'ok') {
          result.appendChild(el('p', 'rounded-md bg-tomato-tint text-tomato text-sm px-4 py-3',
            res.data.message || 'We could not read that sheet.'));
          return;
        }

        var changes = res.data.changes || [];
        var problems = res.data.problems || [];

        var summary = el('p', 'text-sm text-ink');
        summary.textContent = changes.length === 1
          ? '1 price would change.'
          : changes.length + ' prices would change.';
        if (res.data.same) { summary.textContent += ' ' + res.data.same + ' already match.'; }
        if (res.data.skipped) { summary.textContent += ' ' + res.data.skipped + ' left alone.'; }
        result.appendChild(summary);

        if (changes.length) {
          result.appendChild(bulkTable(changes, 'These would change', 'text-ink'));
        }

        if (problems.length) {
          var box = el('div', 'mt-4 rounded-md bg-tomato-tint px-4 py-3');
          box.appendChild(el('p', 'font-semibold text-sm text-tomato',
            problems.length === 1 ? '1 row we cannot apply' : problems.length + ' rows we cannot apply'));
          var list = el('ul', 'mt-2 space-y-1 text-sm text-tomato');
          problems.forEach(function (problem) {
            var item = el('li');
            item.textContent = 'Row ' + problem.line + ', ' + problem.sku + ': ' + problem.reason;
            list.appendChild(item);
          });
          box.appendChild(list);
          box.appendChild(el('p', 'text-sm text-tomato mt-2',
            'An import is all or nothing. Fix these in the sheet and upload it again.'));
          result.appendChild(box);
          return;
        }

        if (!changes.length) {
          result.appendChild(el('p', 'text-sm text-ink-60 mt-2', 'Nothing in that sheet is different from what we already have.'));
          return;
        }

        var confirm = el('button', 'okv-btn px-4 mt-4', 'Apply these ' + changes.length + ' prices');
        confirm.type = 'button';
        confirm.addEventListener('click', function () {
          confirm.disabled = true;
          var applyBody = new URLSearchParams();
          applyBody.set('action', 'apply_import');
          applyBody.set('token', res.data.token || '');
          applyBody.set(window.OKV && OKV.csrfField ? OKV.csrfField : 'okv_csrf', csrfToken(form));

          post(applyBody).then(function (applied) {
            if (applied.ok && applied.data.status === 'ok') {
              toast(applied.data.message || 'Prices updated.', 'ok');
              setTimeout(function () { window.location.reload(); }, 500);
              return;
            }
            confirm.disabled = false;
            toast(applied.data.message || 'Nothing was changed.', 'error');
          }).catch(function () {
            confirm.disabled = false;
            toast('We could not reach the server. Check your connection and try again.', 'error');
          });
        });
        result.appendChild(confirm);
      }).catch(function () {
        if (button) { button.disabled = false; button.textContent = 'Check this sheet'; }
        toast('We could not reach the server. Check your connection and try again.', 'error');
      });
    });
  }

  /** The CSRF token from a form already on the page. */
  function csrfToken(form) {
    var field = form.querySelector('input[name="okv_csrf"]');
    if (field) { return field.value; }
    return (window.OKV && OKV.csrf) ? OKV.csrf : '';
  }

  // --- Price history ---------------------------------------------------------

  function wireHistory() {
    var panel = document.querySelector('[data-history-panel]');
    if (!panel) { return; }
    var body = panel.querySelector('[data-history-body]');
    var title = document.getElementById('price-history-title');
    var close = panel.querySelector('[data-history-close]');
    var opener = null;

    function shut() {
      panel.hidden = true;
      if (opener) { opener.focus(); }
    }
    if (close) { close.addEventListener('click', shut); }
    panel.addEventListener('click', function (event) { if (event.target === panel) { shut(); } });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !panel.hidden) { shut(); }
    });

    document.querySelectorAll('[data-history-open]').forEach(function (button) {
      button.addEventListener('click', function () {
        opener = button;
        var id = button.getAttribute('data-product-id');
        if (title) { title.textContent = 'Price history, ' + (button.getAttribute('data-product-name') || ''); }
        if (body) { body.textContent = 'Loading.'; }
        panel.hidden = false;
        if (close) { close.focus(); }

        fetch(ENDPOINT + '?action=history&product_id=' + encodeURIComponent(id), {
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
        }).then(function (res) { return res.json(); }).then(function (data) {
          if (!body) { return; }
          body.textContent = '';
          var rows = (data && data.history) || [];
          if (!rows.length) {
            body.appendChild(el('p', 'text-sm text-ink-60', 'This product has no price history yet.'));
            return;
          }
          var list = el('ol', 'space-y-3');
          rows.forEach(function (row) {
            var item = el('li', 'border-l-2 border-mist pl-4');
            var line = el('p', 'text-sm text-ink');
            line.textContent = row.old_price_subunit === null
              ? 'Priced at ' + naira(row.new_price_subunit)
              : naira(row.old_price_subunit) + ' to ' + naira(row.new_price_subunit);
            item.appendChild(line);
            var meta = el('p', 'text-xs text-ink-40');
            meta.textContent = String(row.effective_from || '') +
              (row.changed_by_name ? ' by ' + row.changed_by_name : '');
            item.appendChild(meta);
            if (row.change_reason) {
              item.appendChild(el('p', 'text-xs text-ink-60 mt-1', row.change_reason));
            }
            list.appendChild(item);
          });
          body.appendChild(list);
        }).catch(function () {
          if (body) { body.textContent = 'We could not load the history. Please try again.'; }
        });
      });
    });
  }

  ready(function () {
    document.querySelectorAll('[data-price-form]').forEach(wirePriceForm);
    wireBulk();
    wireImport();
    wireHistory();
  });
})();
