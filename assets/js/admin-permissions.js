/**
 * assets/js/admin-permissions.js
 * OK Veggies. The Permissions screen. Filtering eighty keys down to the one you
 * want, the module level tools (everything, nothing, just the view keys), the
 * dirty state that stops a half made change being saved by accident, and the
 * restore button on a recorded version.
 *
 * None of this is load bearing. The page renders every role and every key
 * without JavaScript, the server re-checks the Owner and the CSRF token on every
 * write, and the server decides for itself what actually changed. The tally here
 * is a convenience that says what the form holds, never what the database does.
 *
 * User data reaches the page through textContent only, never innerHTML.
 */
(function () {
  'use strict';

  var ENDPOINT = '/api/v1/permissions.php';

  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); }
    else { document.addEventListener('DOMContentLoaded', fn); }
  }

  function toast(message, type) {
    if (window.OKV && OKV.toast) { OKV.toast(message, type); }
  }

  function readJson(response) {
    return response.json().catch(function () { return {}; }).then(function (data) {
      return { ok: response.ok, data: data || {} };
    });
  }

  function postForm(form) {
    return fetch(form.getAttribute('action') || ENDPOINT, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
      body: new URLSearchParams(new FormData(form))
    }).then(readJson);
  }

  function postFields(fields) {
    var body = new URLSearchParams();
    Object.keys(fields).forEach(function (key) { body.append(key, fields[key]); });
    if (window.OKV && window.OKV.csrf) { body.append('okv_csrf', window.OKV.csrf); }
    return fetch(ENDPOINT, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
      body: body
    }).then(readJson);
  }

  ready(function () {
    var form     = document.querySelector('[data-perm-form]');
    var search   = document.querySelector('[data-perm-search]');
    var note     = document.querySelector('[data-perm-search-note]');
    var modules  = Array.prototype.slice.call(document.querySelectorAll('[data-perm-module]'));
    var tallies  = Array.prototype.slice.call(document.querySelectorAll('[data-perm-tally]'));

    var save     = form ? form.querySelector('[data-perm-save]') : null;
    var reset    = form ? form.querySelector('[data-perm-reset]') : null;
    var dirtyTag = form ? form.querySelector('[data-perm-dirty]') : null;
    var errBox   = form ? form.querySelector('[data-okv-error]') : null;

    // Which modules were open when the page arrived, so clearing the search puts
    // the screen back the way the reader had it rather than reshaping it.
    var openState = {};
    modules.forEach(function (module) {
      openState[module.getAttribute('data-perm-module')] = module.open;
    });

    function boxesIn(module) {
      return Array.prototype.slice.call(module.querySelectorAll('[data-perm-key]'));
    }

    function allBoxes() {
      return Array.prototype.slice.call(document.querySelectorAll('[data-perm-key]'));
    }

    function isDirty() {
      return allBoxes().some(function (box) {
        return (box.checked ? '1' : '0') !== (box.getAttribute('data-original') || '0');
      });
    }

    // --- Counts and dirty state ---------------------------------------------

    function refresh() {
      var on = 0;
      modules.forEach(function (module) {
        var modOn = 0;
        var modAll = 0;
        boxesIn(module).forEach(function (box) {
          if (box.disabled) { return; }   // a locked box is not part of the offer
          modAll++;
          if (box.checked) { modOn++; }
        });
        var tag = module.querySelector('[data-perm-count]');
        if (tag) { tag.textContent = modOn + ' of ' + modAll; }
        on += modOn;
      });

      tallies.forEach(function (node) { node.textContent = String(on); });

      var dirty = isDirty();
      if (dirtyTag) { dirtyTag.hidden = !dirty; }
      if (reset) { reset.hidden = !dirty; }
      if (save) { save.disabled = !dirty; }
    }

    // --- Search --------------------------------------------------------------

    function applySearch(term) {
      var query = String(term || '').trim().toLowerCase();

      modules.forEach(function (module) {
        var matches = 0;
        Array.prototype.slice.call(module.querySelectorAll('[data-perm-row]')).forEach(function (row) {
          var hit = query === '' || (row.getAttribute('data-perm-text') || '').indexOf(query) !== -1;
          row.hidden = !hit;
          if (hit) { matches++; }
        });

        module.hidden = matches === 0;
        if (query === '') {
          module.open = openState[module.getAttribute('data-perm-module')] || false;
        } else if (matches > 0) {
          module.open = true;
        }
      });

      if (!note) { return; }
      if (query === '') {
        note.hidden = true;
        note.textContent = '';
        return;
      }

      var visible = 0;
      modules.forEach(function (module) {
        if (module.hidden) { return; }
        Array.prototype.slice.call(module.querySelectorAll('[data-perm-row]')).forEach(function (row) {
          if (!row.hidden) { visible++; }
        });
      });

      note.hidden = false;
      note.textContent = visible === 0
        ? 'Nothing matches "' + String(term).trim() + '". Try a shorter word.'
        : visible + (visible === 1 ? ' permission matches "' : ' permissions match "') + String(term).trim() + '".';
    }

    if (search) {
      search.addEventListener('input', function () { applySearch(search.value); });
      search.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { search.value = ''; applySearch(''); }
      });
    }

    // --- Module tools --------------------------------------------------------

    modules.forEach(function (module) {
      Array.prototype.slice.call(module.querySelectorAll('[data-perm-all]')).forEach(function (button) {
        button.addEventListener('click', function () {
          var on = button.getAttribute('data-perm-all') === '1';
          boxesIn(module).forEach(function (box) {
            if (!box.disabled) { box.checked = on; }
          });
          refresh();
        });
      });

      Array.prototype.slice.call(module.querySelectorAll('[data-perm-preset]')).forEach(function (button) {
        button.addEventListener('click', function () {
          var keys = (button.getAttribute('data-perm-preset') || '').split(',');
          boxesIn(module).forEach(function (box) {
            if (!box.disabled && keys.indexOf(box.getAttribute('data-perm-key')) !== -1) { box.checked = true; }
          });
          refresh();
        });
      });
    });

    // --- Open and close ------------------------------------------------------

    Array.prototype.slice.call(document.querySelectorAll('[data-perm-expand]')).forEach(function (button) {
      button.addEventListener('click', function () {
        var open = button.getAttribute('data-perm-expand') === 'all';
        modules.forEach(function (module) {
          if (module.hidden) { return; }
          module.open = open;
          openState[module.getAttribute('data-perm-module')] = open;
        });
      });
    });

    // --- Save ----------------------------------------------------------------

    function saveFailed(message) {
      if (errBox) {
        errBox.textContent = message;
        errBox.hidden = false;
        errBox.scrollIntoView({ block: 'center' });
      } else {
        toast(message, 'error');
      }
      if (save) {
        save.disabled = false;
        save.removeAttribute('aria-busy');
      }
    }

    if (reset) {
      reset.addEventListener('click', function () {
        allBoxes().forEach(function (box) {
          box.checked = (box.getAttribute('data-original') || '0') === '1';
        });
        refresh();
      });
    }

    if (form && save) {
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (errBox) { errBox.hidden = true; errBox.textContent = ''; }
        save.disabled = true;
        save.setAttribute('aria-busy', 'true');

        postForm(form).then(function (result) {
          if (result.ok && result.data.status === 'ok') {
            toast(result.data.message || 'Permissions saved.', 'ok');
            setTimeout(function () { window.location.reload(); }, 400);
            return;
          }
          saveFailed((result.data && result.data.message) || 'Something went wrong. Please try again.');
        }).catch(function () {
          saveFailed('We could not reach the server. Check your connection and try again.');
        });
      });
    }

    // --- Put a version back --------------------------------------------------

    Array.prototype.slice.call(document.querySelectorAll('[data-perm-restore]')).forEach(function (button) {
      button.addEventListener('click', function () {
        var versionId = button.getAttribute('data-perm-restore');
        var when      = button.getAttribute('data-perm-restore-label') || 'that version';
        var roleField = form ? form.querySelector('input[name="role_id"]') : null;

        if (!window.confirm('Put this role back to the permissions it had on ' + when + '? The change in place now stays in the history.')) {
          return;
        }

        button.disabled = true;
        button.setAttribute('aria-busy', 'true');

        postFields({
          action: 'restore',
          role_id: roleField ? roleField.value : '',
          version_id: versionId
        }).then(function (result) {
          if (result.ok && result.data.status === 'ok') {
            toast(result.data.message || 'Role put back.', 'ok');
            setTimeout(function () { window.location.reload(); }, 400);
            return;
          }
          button.disabled = false;
          button.removeAttribute('aria-busy');
          toast((result.data && result.data.message) || 'We could not put that version back.', 'error');
        }).catch(function () {
          button.disabled = false;
          button.removeAttribute('aria-busy');
          toast('We could not reach the server. Check your connection and try again.', 'error');
        });
      });
    });

    // --- Leaving with unsaved changes ----------------------------------------

    window.addEventListener('beforeunload', function (event) {
      if (!form || !isDirty()) { return undefined; }
      event.preventDefault();
      event.returnValue = '';
      return '';
    });

    if (form) { refresh(); }
  });
})();
