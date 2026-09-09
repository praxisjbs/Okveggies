(() => {
  'use strict';

  const widget = document.querySelector('[data-support-widget]');
  if (!widget) return;

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

  contactButton.addEventListener('click', () => {
    choices.hidden = true;
    formView.hidden = false;
    const name = formView.querySelector('[name="name"]');
    if (name) name.focus();
  });

  backButton.addEventListener('click', () => {
    formView.hidden = true;
    choices.hidden = false;
    contactButton.focus();
  });

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

  document.querySelectorAll('[data-contact-form]').forEach((form) => {
    const errorBox = form.querySelector('[data-contact-error]');
    const submit = form.querySelector('[data-contact-submit]');
    const success = form.parentElement.querySelector('[data-contact-success]');

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      errorBox.hidden = true;
      errorBox.textContent = '';
      submit.disabled = true;
      submit.textContent = 'Sending...';

      try {
        const response = await fetch(form.action, {
          method: 'POST',
          body: new FormData(form),
          headers: {Accept: 'application/json', 'X-Requested-With': 'fetch'},
          credentials: 'same-origin'
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.status !== 'ok') {
          throw data;
        }
        form.hidden = true;
        success.hidden = false;
        success.focus();
      } catch (failure) {
        errorBox.textContent = failure && failure.message
          ? String(failure.message)
          : 'We could not send your message. Check your connection and try again.';
        errorBox.hidden = false;
        if (failure && failure.field) {
          const field = form.elements.namedItem(String(failure.field));
          if (field && typeof field.focus === 'function') field.focus();
        } else {
          errorBox.focus();
        }
      } finally {
        submit.disabled = false;
        submit.textContent = 'Send message';
      }
    });
  });
})();
