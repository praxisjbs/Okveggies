/**
 * assets/js/checkout.js
 * -----------------------------------------------------------------------------
 * OK Veggies. The app layer over checkout's plain forms. Every essential
 * action works with JavaScript off; this file adds what a phone app would do:
 *
 *   1. Sheets. The delivery-day picker, the fee note and the cancellation note
 *      open as slide-up sheets with 24px corners and a blurred backdrop. They
 *      close on the Close control, the backdrop, Escape, or a swipe down.
 *      Focus enters the sheet on open and goes home on close.
 *   2. The delivery-day picker. The real select stays in the form; with
 *      JavaScript on it is swapped for a large day button that opens the
 *      sheet. One field name, one server contract, either way.
 *   3. The pay bar. The floating button's label follows the chosen payment
 *      card, and the submit turns into a waiting state so nobody double pays
 *      by double tapping. No reload on a radio change: the choice is part of
 *      the same form the button submits.
 *
 * Every value written to the page goes through textContent, never innerHTML.
 * -----------------------------------------------------------------------------
 */
(function () {
  'use strict';

  // Sheets live on OKV so the homepage, FAQ and product Read sheets share
  // the same open/close, and checkout does not bind them twice.
  function openSheet(backdrop) {
    if (window.OKV && typeof OKV.openSheet === 'function') {
      OKV.openSheet(backdrop);
    }
  }

  function closeSheet(backdrop) {
    if (window.OKV && typeof OKV.closeSheet === 'function') {
      OKV.closeSheet(backdrop);
    }
  }

  function wireSheets() {
    if (window.OKV && typeof OKV.bindSheets === 'function') {
      OKV.bindSheets();
    }
  }

  // ---- Delivery-day picker ---------------------------------------------------

  function wirePickers() {
    document.querySelectorAll('[data-delivery-picker]').forEach(function (picker) {
      var select = picker.querySelector('[data-picker-select]');
      var button = picker.querySelector('[data-picker-button]');
      var sheet = picker.querySelector('[data-picker-sheet]');
      if (!select || !button || !sheet) { return; }

      // Swap the select for the day button. The select stays in the form and
      // carries the value; it just stops being the thing you touch. Both the
      // attribute and the `hidden` class have to go: the class sets
      // display:none, and the attribute alone would leave it invisible.
      button.hidden = false;
      button.classList.remove('hidden');
      select.hidden = true;
      select.tabIndex = -1;
      select.required = false;

      function label() {
        var option = select.options[select.selectedIndex];
        return option ? option.textContent.trim() : 'Choose your day';
      }

      function paint() {
        var text = picker.querySelector('[data-picker-label]');
        if (text) { text.textContent = label(); }
        var box = button.querySelector('span');
        if (box) {
          box.classList.toggle('border-forest', true);
          box.classList.toggle('ring-2', true);
          box.classList.toggle('ring-gold', true);
          box.classList.remove('border-ink-10');
        }
        picker.querySelectorAll('[data-picker-option]').forEach(function (option) {
          var chosen = option.getAttribute('data-picker-option') === select.value;
          option.setAttribute('aria-pressed', chosen ? 'true' : 'false');
          option.classList.toggle('border-forest', chosen);
          option.classList.toggle('ring-2', chosen);
          option.classList.toggle('ring-gold', chosen);
          option.classList.toggle('border-ink-10', !chosen);
          var check = option.querySelector('[data-picker-check]');
          if (check) { check.hidden = !chosen; }
        });
      }

      button.addEventListener('click', function () {
        button.setAttribute('aria-expanded', 'true');
        openSheet(sheet);
      });
      picker.querySelectorAll('[data-picker-option]').forEach(function (option) {
        option.addEventListener('click', function () {
          select.value = option.getAttribute('data-picker-option');
          paint();
          button.setAttribute('aria-expanded', 'false');
          closeSheet(sheet);
        });
      });
      // Close restores the trigger's expanded state along with its focus.
      var observer = new MutationObserver(function () {
        if (sheet.hidden) { button.setAttribute('aria-expanded', 'false'); }
      });
      observer.observe(sheet, { attributes: true, attributeFilter: ['hidden'] });

      paint();
    });
  }

  // ---- The pay bar -----------------------------------------------------------

  var PAY_LABELS = {
    pay_in_full: 'Pay now',
    deposit: 'Pay deposit',
    pay_on_delivery: 'Place order',
    on_account: 'Place order',
  };

  function wirePayBar() {
    var options = document.querySelector('[data-payment-options]');
    if (!options) { return; }
    var labels = document.querySelectorAll('[data-pay-label]');
    var submits = document.querySelectorAll('[data-pay-submit]');

    function paint() {
      var checked = options.querySelector('input[name="payment_option"]:checked');
      var text = checked ? (PAY_LABELS[checked.value] || 'Pay now') : 'Pay now';
      labels.forEach(function (label) { label.textContent = text; });
    }

    options.addEventListener('change', paint);
    paint();

    var form = document.getElementById('checkout-payment-form');
    if (form) {
      form.addEventListener('submit', function () {
        var waiting = document.querySelector('[data-payment-options] input[name="payment_option"]:checked');
        var message = waiting && (waiting.value === 'pay_in_full' || waiting.value === 'deposit')
          ? 'Taking you to Paystack'
          : 'Placing your order';
        submits.forEach(function (button) {
          button.setAttribute('aria-busy', 'true');
          button.classList.add('pointer-events-none', 'opacity-80');
          var label = button.querySelector('[data-pay-label]');
          if (label) { label.textContent = message; }
        });
        labels.forEach(function (label) { label.textContent = message; });
      });
    }
  }

  function ready() {
    wireSheets();
    wirePickers();
    wirePayBar();
  }

  if (document.readyState !== 'loading') { ready(); }
  else { document.addEventListener('DOMContentLoaded', ready); }
})();
