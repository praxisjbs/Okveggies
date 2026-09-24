/**
 * assets/js/zone-picker.js
 * -----------------------------------------------------------------------------
 * OK Veggies. Type-to-search over the delivery-area select that
 * okv_zone_picker() renders (includes/components/shop/delivery_picker.php).
 *
 * The select is the source of truth and the only thing posted. This file
 * reads the zones back out of its options (name as the option text, the
 * "what it covers" note as data-note), so the zones, their order and their
 * notes are whatever the database said when the page was drawn. Nothing about
 * Lagos is written here.
 *
 * With JavaScript on, the select is hidden and an editable combobox with a
 * listbox popup takes its place (the ARIA 1.2 pattern, list autocomplete with
 * manual selection):
 *
 *   typing         filters the list, case-insensitive, by every typed word
 *                  appearing in the name or the note
 *   Arrow Down/Up  opens the list and moves through it, wrapping at the ends;
 *                  Alt + Arrow Down only opens it
 *   Enter          picks the highlighted area (or the only match)
 *   Escape         closes the list and puts back the area already chosen
 *   Tab            picks the highlighted area, if one is, and moves on
 *
 * Focus stays in the text box throughout; the highlighted option is announced
 * through aria-activedescendant. Typing never changes the chosen area on its
 * own: only picking an option writes the select, and leaving the box without
 * picking puts the chosen area's name back. The typed text has no name
 * attribute, so it is never sent to the server.
 *
 * Matching is plain substring search on normalised text. The query is never
 * turned into a regular expression, so a bracket, a dot or a star is just a
 * character to look for.
 *
 * Every value written to the page goes through textContent; nothing typed or
 * read from the database is ever parsed as markup.
 * -----------------------------------------------------------------------------
 */
(function (root) {
  'use strict';

  // ---- Matching (pure, exported for the tests) -------------------------------

  var COMBINING_MARKS = /[\u0300-\u036f]/g;
  var SEPARATORS = /[\s\-\u2010-\u2015_\/,.]+/g;

  /** Lower case, accents dropped, separators and runs of spaces as one space. */
  function normalise(text) {
    var value = String(text == null ? '' : text);
    if (typeof value.normalize === 'function') {
      value = value.normalize('NFD').replace(COMBINING_MARKS, '');
    }
    return value.toLowerCase().replace(SEPARATORS, ' ').trim();
  }

  /** The words a person typed, normalised. An empty query has no words. */
  function words(query) {
    var clean = normalise(query);
    return clean === '' ? [] : clean.split(' ');
  }

  /** True when every typed word appears in the zone's name or note. */
  function matches(zone, query) {
    var wanted = words(query);
    if (!wanted.length) { return true; }
    var haystack = ' ' + normalise((zone.name || '') + ' ' + (zone.note || '')) + ' ';
    for (var i = 0; i < wanted.length; i++) {
      if (haystack.indexOf(wanted[i]) === -1) { return false; }
    }
    return true;
  }

  /** The zones that match, in the order they were given (the database order). */
  function filter(zones, query) {
    var out = [];
    for (var i = 0; i < zones.length; i++) {
      if (matches(zones[i], query)) { out.push(zones[i]); }
    }
    return out;
  }

  function plural(count, one, many) {
    return count + ' ' + (count === 1 ? one : many);
  }

  // ---- The widget ------------------------------------------------------------

  var counter = 0;

  function enhance(picker) {
    if (!picker || picker.getAttribute('data-zone-ready') === '1') { return null; }
    var select = picker.querySelector('[data-zone-select]');
    var combo = picker.querySelector('[data-zone-combo]');
    var input = picker.querySelector('[data-zone-input]');
    var toggle = picker.querySelector('[data-zone-toggle]');
    var popup = picker.querySelector('[data-zone-popup]');
    var list = picker.querySelector('[data-zone-list]');
    var empty = picker.querySelector('[data-zone-empty]');
    var status = picker.querySelector('[data-zone-status]');
    var hint = picker.querySelector('[data-zone-hint]');
    var error = picker.querySelector('[data-zone-error]');
    var label = picker.querySelector('label');
    var checkTemplate = picker.querySelector('template[data-zone-check]');
    if (!select || !combo || !input || !popup || !list) { return null; }
    picker.setAttribute('data-zone-ready', '1');
    counter++;

    var zones = [];
    Array.prototype.forEach.call(select.options, function (option) {
      if (option.value === '') { return; }
      zones.push({
        id: option.value,
        name: option.textContent.replace(/\s+/g, ' ').trim(),
        note: (option.getAttribute('data-note') || '').trim()
      });
    });
    var placeholder = select.options.length && select.options[0].value === ''
      ? select.options[0].textContent.trim()
      : '';
    var required = select.required;
    var shown = [];
    var active = -1;
    var statusTimer = null;
    var optionPrefix = (list.id || 'zone-list-' + counter) + '-opt-';

    // Hand the field over. The select keeps its name and value; it just stops
    // being the thing a person touches. required moves to our own check,
    // because a browser cannot focus a hidden control to complain about it.
    select.hidden = true;
    select.tabIndex = -1;
    select.required = false;
    select.setAttribute('aria-hidden', 'true');
    combo.hidden = false;
    if (hint) { hint.hidden = false; }
    if (label) { label.htmlFor = input.id; }
    if (required) { input.setAttribute('aria-required', 'true'); }

    function chosen() {
      for (var i = 0; i < zones.length; i++) {
        if (zones[i].id === select.value) { return zones[i]; }
      }
      return null;
    }

    function chosenName() {
      var zone = chosen();
      return zone ? zone.name : '';
    }

    function isOpen() { return !popup.hidden; }

    function announce(text) {
      if (!status) { return; }
      if (statusTimer) { clearTimeout(statusTimer); }
      // A short pause, so a fast typist hears the count once, not per letter.
      statusTimer = setTimeout(function () { status.textContent = text; }, 250);
    }

    function setExpanded(open) {
      input.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (toggle) { toggle.setAttribute('aria-expanded', open ? 'true' : 'false'); }
    }

    function optionFor(index) {
      return list.children[index] || null;
    }

    function setActive(index) {
      var previous = optionFor(active);
      if (previous) { previous.classList.remove('is-active'); }
      active = index;
      var current = optionFor(active);
      if (current) {
        current.classList.add('is-active');
        input.setAttribute('aria-activedescendant', current.id);
        if (typeof current.scrollIntoView === 'function') {
          current.scrollIntoView({ block: 'nearest' });
        }
      } else {
        active = -1;
        input.removeAttribute('aria-activedescendant');
      }
    }

    function render(query) {
      shown = filter(zones, query);
      while (list.firstChild) { list.removeChild(list.firstChild); }
      active = -1;
      input.removeAttribute('aria-activedescendant');

      shown.forEach(function (zone) {
        var item = document.createElement('li');
        item.id = optionPrefix + zone.id;
        item.className = 'okv-zone-option';
        item.setAttribute('role', 'option');
        item.setAttribute('data-value', zone.id);
        var isChosen = zone.id === select.value;
        item.setAttribute('aria-selected', isChosen ? 'true' : 'false');

        var text = document.createElement('span');
        text.className = 'min-w-0 flex-1';
        var name = document.createElement('span');
        name.className = 'block text-sm font-semibold text-ink';
        name.textContent = zone.name;
        text.appendChild(name);
        if (zone.note) {
          var note = document.createElement('span');
          note.className = 'block text-xs text-ink-60';
          note.textContent = zone.note;
          text.appendChild(note);
        }
        item.appendChild(text);

        var check = document.createElement('span');
        check.className = 'okv-zone-check';
        check.setAttribute('aria-hidden', 'true');
        if (checkTemplate && checkTemplate.content) {
          check.appendChild(checkTemplate.content.cloneNode(true));
        }
        item.appendChild(check);
        list.appendChild(item);
      });

      var typed = words(query).length > 0;
      if (empty) {
        empty.hidden = shown.length > 0;
        empty.textContent = shown.length > 0 ? ''
          : (zones.length === 0
            ? 'No delivery area is open right now.'
            : 'No area matches "' + String(query).trim() + '". Check the spelling, or try a nearby area or street.');
      }
      list.hidden = shown.length === 0;
      if (shown.length === 0) {
        announce(zones.length === 0 ? 'No delivery area is open right now.' : 'No area matches your search.');
      } else if (typed) {
        announce(plural(shown.length, 'area matches', 'areas match') + '. Use the arrow keys to choose.');
      } else {
        announce(plural(shown.length, 'area', 'areas') + ' to choose from. Type to narrow the list.');
      }
    }

    function open() {
      if (!isOpen()) {
        popup.hidden = false;
        setExpanded(true);
      }
    }

    function close() {
      popup.hidden = true;
      setExpanded(false);
      setActive(-1);
    }

    function revert() {
      input.value = chosenName();
    }

    function showError(text) {
      if (!error) { return; }
      error.textContent = text;
      error.hidden = text === '';
      var described = (input.getAttribute('aria-describedby') || '').split(/\s+/).filter(function (part) {
        return part !== '' && part !== error.id;
      });
      if (text !== '') {
        described.push(error.id);
        input.setAttribute('aria-invalid', 'true');
      } else {
        input.removeAttribute('aria-invalid');
      }
      input.setAttribute('aria-describedby', described.join(' '));
    }

    function commit(zone) {
      if (!zone) { return; }
      var changed = select.value !== zone.id;
      select.value = zone.id;
      input.value = zone.name;
      close();
      showError('');
      announce(zone.name + ' chosen.');
      if (changed) {
        select.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }

    // What the list should show when it opens: everything while the box still
    // reads the chosen area, the filtered list once somebody has typed.
    function currentQuery() {
      return input.value === chosenName() ? '' : input.value;
    }

    function openList() {
      render(currentQuery());
      open();
    }

    input.addEventListener('input', function () {
      showError('');
      render(input.value);
      open();
    });

    input.addEventListener('keydown', function (event) {
      var key = event.key;
      if (key === 'ArrowDown' || key === 'Down') {
        event.preventDefault();
        if (!isOpen()) {
          openList();
          if (event.altKey) { return; }
        }
        if (shown.length) { setActive(active + 1 >= shown.length ? 0 : active + 1); }
      } else if (key === 'ArrowUp' || key === 'Up') {
        event.preventDefault();
        if (!isOpen()) { openList(); }
        if (shown.length) { setActive(active <= 0 ? shown.length - 1 : active - 1); }
      } else if (key === 'Enter') {
        if (!isOpen()) { return; }
        event.preventDefault();
        if (active >= 0) {
          commit(shown[active]);
        } else if (shown.length === 1) {
          commit(shown[0]);
        }
      } else if (key === 'Escape' || key === 'Esc') {
        if (isOpen()) {
          event.preventDefault();
          close();
          revert();
        } else if (input.value !== chosenName()) {
          event.preventDefault();
          revert();
        }
      } else if (key === 'Tab') {
        if (isOpen() && active >= 0) {
          commit(shown[active]);
        } else {
          close();
          revert();
        }
      }
    });

    input.addEventListener('click', function () {
      if (!isOpen()) { openList(); }
    });

    if (toggle) {
      // mousedown would move focus to the button and blur the box first.
      toggle.addEventListener('mousedown', function (event) { event.preventDefault(); });
      toggle.addEventListener('click', function () {
        if (isOpen()) {
          close();
          revert();
        } else {
          render('');
          open();
        }
        input.focus();
      });
    }

    list.addEventListener('mousedown', function (event) { event.preventDefault(); });
    list.addEventListener('click', function (event) {
      var item = event.target && event.target.closest ? event.target.closest('[role="option"]') : null;
      if (!item) { return; }
      var value = item.getAttribute('data-value');
      for (var i = 0; i < shown.length; i++) {
        if (shown[i].id === value) {
          commit(shown[i]);
          input.focus();
          return;
        }
      }
    });

    // Leaving the picker without choosing keeps the area already chosen.
    picker.addEventListener('focusout', function () {
      setTimeout(function () {
        if (!picker.contains(document.activeElement)) {
          close();
          revert();
        }
      }, 0);
    });

    // Somebody else set the select (a script, or the browser restoring the
    // form on Back): follow it.
    select.addEventListener('change', function () {
      if (document.activeElement !== input || !isOpen()) { revert(); }
    });
    window.addEventListener('pageshow', revert);

    // Our own required check, before any other submit handler runs, so a
    // missing area stops the form here with a message beside the field.
    var form = select.form;
    function guard(event) {
      if (!required || select.value !== '' || select.disabled) { return true; }
      if (event) {
        event.preventDefault();
        event.stopImmediatePropagation();
      }
      showError(zones.length === 0
        ? 'No delivery area is open right now. Please message support.'
        : 'Choose your delivery area from the list.');
      input.focus();
      return false;
    }
    if (form) {
      document.addEventListener('submit', function (event) {
        if (event.target === form) { guard(event); }
      }, true);
    }

    input.value = chosenName();
    if (placeholder && !input.getAttribute('placeholder')) { input.setAttribute('placeholder', placeholder); }

    return {
      zones: zones,
      validate: function () { return guard(null); },
      refresh: revert
    };
  }

  function enhanceAll(scope) {
    var found = (scope || document).querySelectorAll('[data-zone-picker]');
    Array.prototype.forEach.call(found, enhance);
  }

  var api = {
    normalise: normalise,
    words: words,
    matches: matches,
    filter: filter,
    enhance: enhance,
    enhanceAll: enhanceAll
  };

  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  }
  if (root) {
    root.OKVZonePicker = api;
  }
  if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function () { enhanceAll(document); });
    } else {
      enhanceAll(document);
    }
  }
})(typeof window !== 'undefined' ? window : null);
