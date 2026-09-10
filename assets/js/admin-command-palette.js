/**
 * OK Veggies shared admin command palette.
 * Destinations are already permission-filtered by PHP. This module only
 * searches and navigates the safe anchors that reached the page.
 */
(function () {
  'use strict';

  var palette = document.querySelector('[data-command-palette]');
  var trigger = document.querySelector('[data-command-open]');
  if (!palette || !trigger) { return; }

  var panel = palette.querySelector('[role="dialog"]');
  var search = palette.querySelector('[data-command-search]');
  var empty = palette.querySelector('[data-command-empty]');
  var status = palette.querySelector('[data-command-status]');
  var commands = Array.prototype.slice.call(palette.querySelectorAll('[data-command-item]'));
  var activeIndex = -1;
  var visibleCommands = [];
  var opener = null;

  function focusable() {
    return Array.prototype.slice.call(panel.querySelectorAll(
      'button:not([disabled]), input:not([disabled]), a[href]:not([hidden]):not([tabindex="-1"]), [tabindex]:not([tabindex="-1"])'
    )).filter(function (item) { return item.offsetParent !== null; });
  }

  function setActive(index) {
    if (visibleCommands.length === 0) {
      activeIndex = -1;
      search.removeAttribute('aria-activedescendant');
      return;
    }
    if (index < 0) { index = visibleCommands.length - 1; }
    if (index >= visibleCommands.length) { index = 0; }
    activeIndex = index;
    commands.forEach(function (command) {
      command.setAttribute('aria-selected', command === visibleCommands[index] ? 'true' : 'false');
    });
    search.setAttribute('aria-activedescendant', visibleCommands[index].id);
    visibleCommands[index].scrollIntoView({ block: 'nearest' });
  }

  function filterCommands() {
    var terms = search.value.toLowerCase().trim().split(/\s+/).filter(Boolean);
    visibleCommands = commands.filter(function (command) {
      var haystack = (command.getAttribute('data-command-search-text') || '').toLowerCase();
      var matches = terms.every(function (term) { return haystack.indexOf(term) !== -1; });
      command.hidden = !matches;
      command.setAttribute('aria-selected', 'false');
      return matches;
    });
    empty.hidden = visibleCommands.length !== 0;
    status.textContent = visibleCommands.length === 1
      ? '1 permitted page matches.'
      : visibleCommands.length + ' permitted pages match.';
    setActive(0);
  }

  function openPalette(openedBy) {
    opener = openedBy || document.activeElement;
    palette.hidden = false;
    trigger.setAttribute('aria-expanded', 'true');
    search.setAttribute('aria-expanded', 'true');
    document.body.style.overflow = 'hidden';
    search.value = '';
    filterCommands();
    search.focus();
  }

  function closePalette() {
    if (palette.hidden) { return; }
    palette.hidden = true;
    trigger.setAttribute('aria-expanded', 'false');
    search.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
    search.removeAttribute('aria-activedescendant');
    if (opener && typeof opener.focus === 'function' && document.contains(opener)) {
      opener.focus();
    } else {
      trigger.focus();
    }
  }

  function move(change) {
    if (visibleCommands.length === 0) { return; }
    setActive(activeIndex + change);
  }

  trigger.addEventListener('click', function () { openPalette(trigger); });
  palette.querySelectorAll('[data-command-close]').forEach(function (button) {
    button.addEventListener('click', closePalette);
  });
  palette.addEventListener('click', function (event) {
    if (event.target === palette) { closePalette(); }
  });
  commands.forEach(function (command) {
    command.addEventListener('pointermove', function () {
      var index = visibleCommands.indexOf(command);
      if (index !== -1) { setActive(index); }
    });
  });
  search.addEventListener('input', filterCommands);

  document.addEventListener('keydown', function (event) {
    var shortcut = (event.ctrlKey || event.metaKey) && !event.altKey && event.key.toLowerCase() === 'k';
    if (shortcut) {
      event.preventDefault();
      if (palette.hidden) { openPalette(document.activeElement); }
      else { closePalette(); }
      return;
    }
    if (palette.hidden) { return; }
    if (event.key === 'Escape') {
      event.preventDefault();
      closePalette();
      return;
    }
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      move(1);
      return;
    }
    if (event.key === 'ArrowUp') {
      event.preventDefault();
      move(-1);
      return;
    }
    if (event.key === 'Enter' && visibleCommands[activeIndex]) {
      event.preventDefault();
      visibleCommands[activeIndex].click();
      return;
    }
    if (event.key !== 'Tab') { return; }
    var items = focusable();
    if (items.length === 0) {
      event.preventDefault();
      panel.focus();
      return;
    }
    var first = items[0];
    var last = items[items.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });
}());
