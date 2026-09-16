(() => {
  'use strict';

  const widget = document.querySelector('[data-support-widget]');
  if (widget) {
    const trigger = widget.querySelector('[data-support-trigger]');
    const dialog = widget.querySelector('[data-support-dialog]');
    const panel = widget.querySelector('[data-support-panel]');
    const choices = widget.querySelector('[data-support-choices]');
    const formView = widget.querySelector('[data-support-form]');
    const contactButton = widget.querySelector('[data-support-contact]');
    const backButton = widget.querySelector('[data-support-back]');
    let returnFocus = null;

    const focusable = () => Array.from(dialog.querySelectorAll(
      'a[href], button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
    )).filter((element) => !element.hidden && element.offsetParent !== null);

    const open = () => {
      returnFocus = document.activeElement;
      dialog.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
      if (window.matchMedia('(max-width: 639px)').matches) {
        document.body.style.overflow = 'hidden';
      }
      const first = focusable()[0];
      (first || panel).focus();
    };

    const close = () => {
      dialog.hidden = true;
      trigger.setAttribute('aria-expanded', 'false');
      document.body.style.overflow = '';
      if (returnFocus && typeof returnFocus.focus === 'function') returnFocus.focus();
    };

    trigger.addEventListener('click', (event) => {
      event.preventDefault();
      open();
    });

    widget.querySelectorAll('[data-support-close]').forEach((button) => {
      button.addEventListener('click', close);
    });

    if (contactButton) {
      contactButton.addEventListener('click', () => {
        choices.hidden = true;
        formView.hidden = false;
        const name = formView.querySelector('[name="name"]');
        if (name) name.focus();
      });
    }

    if (backButton) {
      backButton.addEventListener('click', () => {
        formView.hidden = true;
        choices.hidden = false;
        contactButton.focus();
      });
    }

    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) close();
    });

    dialog.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        close();
        return;
      }
      if (event.key !== 'Tab') return;
      const items = focusable();
      if (items.length === 0) {
        event.preventDefault();
        panel.focus();
        return;
      }
      const first = items[0];
      const last = items[items.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });
  }

  document.querySelectorAll('[data-contact-form]').forEach((form) => {
    const parent = form.parentElement;
    const errorBox = form.querySelector('[data-contact-error]');
    const submit = form.querySelector('[data-contact-submit]');
    const success = parent ? parent.querySelector('[data-contact-success]') : null;

    if (parent) {
      const resetBtn = parent.querySelector('[data-support-new-message], [data-contact-new-message]');
      if (resetBtn) {
        resetBtn.addEventListener('click', () => {
          const subject = form.querySelector('[name="subject"]');
          const message = form.querySelector('[name="message"]');
          if (subject) subject.value = '';
          if (message) message.value = '';
          if (errorBox) {
            errorBox.hidden = true;
            errorBox.textContent = '';
          }
          form.hidden = false;
          if (success) success.hidden = true;
          const target = message || form.querySelector('[name="name"]');
          if (target && typeof target.focus === 'function') target.focus();
        });
      }
    }

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (errorBox) {
        errorBox.hidden = true;
        errorBox.textContent = '';
      }
      if (submit) {
        submit.disabled = true;
        submit.textContent = 'Sending...';
      }

      // form.action is shadowed by <input name="action"> in HTML forms, returning
      // [object HTMLInputElement] instead of the action URL string.
      // Use getAttribute('action') with fallback to ensure the valid endpoint.
      const endpoint = form.getAttribute('action') || '/api/v1/contact.php';

      try {
        let response;
        try {
          response = await fetch(endpoint, {
            method: 'POST',
            body: new FormData(form),
            headers: { Accept: 'application/json', 'X-Requested-With': 'fetch' },
            credentials: 'same-origin'
          });
        } catch (netErr) {
          throw new TypeError('NETWORK_ERROR');
        }

        const contentType = response.headers.get('content-type') || '';
        const isJson = contentType.includes('application/json');
        const data = isJson ? await response.json().catch(() => ({})) : {};

        if (!response.ok || data.status !== 'ok') {
          const err = new Error(data.message || (response.status >= 500
            ? 'We could not save your message. Please try again in a moment.'
            : 'We could not send your message. Please check your details and try again.'));
          if (data.field) err.field = data.field;
          if (data.code) err.code = data.code;
          throw err;
        }

        form.hidden = true;
        if (success) {
          success.hidden = false;
          success.focus();
        }
      } catch (failure) {
        let messageText;
        if (failure instanceof TypeError && failure.message === 'NETWORK_ERROR') {
          messageText = 'We could not send your message. Check your connection and try again.';
        } else if (failure && failure.message) {
          messageText = String(failure.message);
        } else {
          messageText = 'We could not send your message. Check your connection and try again.';
        }

        if (errorBox) {
          errorBox.textContent = messageText;
          errorBox.hidden = false;
        }

        if (failure && failure.field) {
          const field = form.elements.namedItem(String(failure.field));
          if (field && typeof field.focus === 'function') field.focus();
        } else if (errorBox) {
          errorBox.focus();
        }
      } finally {
        if (submit) {
          submit.disabled = false;
          submit.textContent = 'Send message';
        }
      }
    });
  });
})();
