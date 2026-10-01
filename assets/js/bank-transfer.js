/**
 * assets/js/bank-transfer.js
 * -----------------------------------------------------------------------------
 * OK Veggies. The small helpers for paying by direct bank transfer (PRD 9.3a),
 * used on checkout and on the order page. The page works without any of it:
 * the account details are plain text and the receipt box is an ordinary file
 * input the server checks. This file only makes it kinder:
 *
 *   1. Copy buttons. The account number and the exact amount copy in one tap,
 *      as bare figures a banking app will paste. The buttons are hidden in the
 *      markup and shown here, so nobody sees a button that cannot work.
 *   2. An early look at the receipt. The file type and size are judged in the
 *      browser before anything is sent, so a wrong file is turned back at once
 *      with the same words the server would use, instead of after an upload.
 *   3. A receipt-required check on the order page form, and a waiting state on
 *      submit so a slow upload is not sent twice.
 *
 * Every value written to the page goes through textContent, never innerHTML.
 * -----------------------------------------------------------------------------
 */
(function () {
  'use strict';

  var ALLOWED = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
      var area = document.createElement('textarea');
      area.value = text;
      area.setAttribute('readonly', '');
      area.style.position = 'fixed';
      area.style.opacity = '0';
      document.body.appendChild(area);
      area.select();
      try {
        if (document.execCommand('copy')) { resolve(); } else { reject(new Error('copy refused')); }
      } catch (error) {
        reject(error);
      } finally {
        document.body.removeChild(area);
      }
    });
  }

  function wireCopy() {
    document.querySelectorAll('[data-copy]').forEach(function (button) {
      button.classList.remove('hidden');
      button.addEventListener('click', function () {
        var block = button.closest('dl');
        var status = block && block.parentNode ? block.parentNode.querySelector('[data-copy-status]') : null;
        var name = button.getAttribute('data-copy-name') || 'that';
        copyText(button.getAttribute('data-copy') || '').then(function () {
          if (status) { status.textContent = 'Copied ' + name + '.'; }
        }, function () {
          if (status) { status.textContent = 'We could not copy it. Select the text and copy it by hand.'; }
        });
      });
    });
  }

  function extensionOf(name) {
    var dot = name.lastIndexOf('.');
    return dot < 0 ? '' : name.slice(dot + 1).toLowerCase();
  }

  function showError(field, message) {
    var input = field.querySelector('[data-receipt-input]');
    var error = field.querySelector('[data-receipt-error]');
    if (error) {
      error.textContent = message;
      error.classList.toggle('hidden', message === '');
    }
    if (input) {
      if (message === '') { input.removeAttribute('aria-invalid'); }
      else { input.setAttribute('aria-invalid', 'true'); }
    }
  }

  /** Returns the problem in plain words, or '' when the file can be a receipt. */
  function problemWith(file, maxBytes) {
    if (!file) { return ''; }
    if (file.size < 1) { return 'That file is empty. Attach the receipt again.'; }
    if (ALLOWED.indexOf(extensionOf(file.name)) < 0) {
      return 'Attach the receipt as a photo (JPG, PNG or WebP) or a PDF.';
    }
    if (maxBytes > 0 && file.size > maxBytes) {
      return 'That file is too large. Attach a photo or PDF under ' + Math.max(1, Math.floor(maxBytes / 1048576)) + 'MB.';
    }
    return '';
  }

  function wireReceipts() {
    document.querySelectorAll('[data-receipt-field]').forEach(function (field) {
      var input = field.querySelector('[data-receipt-input]');
      if (!input) { return; }
      var maxBytes = parseInt(field.getAttribute('data-max-bytes') || '0', 10);
      input.addEventListener('change', function () {
        var problem = problemWith(input.files && input.files[0], maxBytes);
        showError(field, problem);
        if (problem !== '') { input.value = ''; }
      });
    });

    document.querySelectorAll('[data-transfer-form]').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        var field = form.querySelector('[data-receipt-field]');
        var input = form.querySelector('[data-receipt-input]');
        if (field && input && !(input.files && input.files.length)) {
          event.preventDefault();
          showError(field, 'Attach the receipt from your bank so we can check the payment.');
          input.focus();
          return;
        }
        var button = form.querySelector('[data-transfer-submit]');
        if (button) {
          button.setAttribute('aria-busy', 'true');
          button.classList.add('pointer-events-none', 'opacity-80');
        }
      });
    });
  }

  function ready() {
    wireCopy();
    wireReceipts();
  }

  if (document.readyState !== 'loading') { ready(); }
  else { document.addEventListener('DOMContentLoaded', ready); }
})();
