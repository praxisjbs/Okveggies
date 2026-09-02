/**
 * assets/js/admin-payments.js
 * OK Veggies. The Payments screen. Turns the refund panel into a real modal,
 * and refreshes the figures from the server before it opens.
 *
 * A refund cannot be undone, so it is never a single click. The panel already
 * works with JavaScript off: it is a details element holding the whole form,
 * with the figures rendered by the server and a confirmation that the server
 * re-checks. Everything below is an upgrade on top of that, and nothing here is
 * load bearing for correctness.
 *
 * The one thing this does add is safety rather than polish. A Payments screen
 * left open in a tab can be minutes or hours stale, and offering to refund
 * money that has already gone back is how a customer gets paid twice. So the
 * figures are re-read from the server at the moment the modal opens, and the
 * amount is clamped to what is genuinely left. If that read fails, the modal
 * does not open and the inline panel is used instead, still carrying the
 * server's own numbers.
 *
 * All values are written with textContent, never innerHTML.
 */
(function () {
  'use strict';

  var ENDPOINT = '/api/v1/payments.php';

  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); }
    else { document.addEventListener('DOMContentLoaded', fn); }
  }

  function toast(message, type) {
    if (window.OKV && OKV.toast) { OKV.toast(message, type); }
  }

  /** Ask the server what is actually still refundable on this transaction. */
  function fetchQuote(transactionId) {
    var url = ENDPOINT + '?action=refund_quote&transaction_id=' + encodeURIComponent(transactionId);
    return fetch(url, {
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
      credentials: 'same-origin'
    }).then(function (response) {
      if (!response.ok) { throw new Error('quote_failed'); }
      return response.json();
    });
  }

  function setText(root, selector, value) {
    var node = root.querySelector(selector);
    if (node) { node.textContent = value; }
  }

  ready(function () {
    var panels = document.querySelectorAll('[data-refund]');
    if (!panels.length || typeof HTMLDialogElement === 'undefined') { return; }

    var dialog = document.createElement('dialog');
    dialog.className = 'okv-card max-w-lg';
    dialog.setAttribute('aria-label', 'Confirm this refund');
    document.body.appendChild(dialog);

    function close() {
      if (dialog.open) { dialog.close(); }
      while (dialog.firstChild) { dialog.removeChild(dialog.firstChild); }
    }

    // Escape closes without sending anything.
    dialog.addEventListener('cancel', function () { close(); });

    Array.prototype.forEach.call(panels, function (panel) {
      var summary = panel.querySelector('summary');
      var form    = panel.querySelector('[data-refund-form]');
      if (!summary || !form) { return; }

      summary.addEventListener('click', function (event) {
        // Let the details open inline if anything below fails.
        event.preventDefault();

        fetchQuote(panel.getAttribute('data-transaction-id')).then(function (data) {
          if (!data || data.status !== 'ok') { throw new Error('quote_failed'); }

          var clone = form.cloneNode(true);
          setText(clone, '[data-refund-paid]', data.paid);
          setText(clone, '[data-refund-done]', data.refunded);
          setText(clone, '[data-refund-left]', data.refundable);

          var amount = clone.querySelector('[data-refund-amount]');
          if (amount) {
            // Clamp to what the server says is left, not to what the page
            // was rendered with.
            amount.value = String(data.refundable_subunit / 100);
          }

          if (data.refundable_subunit < 1) {
            toast('That payment has already been fully refunded.', 'error');
            return;
          }

          var cancel = clone.querySelector('[data-refund-cancel]');
          if (cancel) {
            cancel.hidden = false;
            cancel.addEventListener('click', close);
          }

          close();
          dialog.appendChild(clone);
          dialog.showModal();
          var first = clone.querySelector('[data-refund-amount]');
          if (first) { first.focus(); }
        }).catch(function () {
          // No modal without fresh figures. Fall back to the inline panel,
          // which still carries the numbers the server rendered.
          panel.open = true;
          toast('Could not refresh the refund figures. Check them before sending.', 'error');
        });
      });
    });
  });
}());
