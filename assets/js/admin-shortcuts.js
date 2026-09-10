/**
 * OK Veggies shared admin shortcut controller.
 * PHP supplies only permitted command anchors. Shortcuts can never construct a
 * destination or reach a page that is absent from that server-filtered list.
 */
(function () {
  'use strict';

  var palette = document.querySelector('[data-command-palette]');
  var trigger = document.querySelector('[data-command-open]');
  if (!palette || !trigger) { return; }

  var panel = palette.querySelector('[role="dialog"]');
  var search = palette.querySelector('[data-command-search]');
  var empty = palette.querySelector('[data-command-empty]');
  var paletteStatus = palette.querySelector('[data-command-status]');
  var shortcutStatus = document.querySelector('[data-shortcut-status]');
  var commands = Array.prototype.slice.call(palette.querySelectorAll('[data-command-item]'));
  var shortcutCommands = {};
  var activeIndex = -1;
  var visibleCommands = [];
  var opener = null;
  var pendingPrefix = '';
  var pendingTimer = null;

  commands.forEach(function (command) {
    var shortcut = (command.getAttribute('data-command-shortcut') || '').toLowerCase().trim();
    if (shortcut) { shortcutCommands[shortcut] = command; }
  });

  function announce(message) {
    if (!shortcutStatus) { return; }
    shortcutStatus.textContent = '';
    window.setTimeout(function () { shortcutStatus.textContent = message; }, 10);
  }

  function isEditable(target) {
    return target instanceof Element && Boolean(target.closest('input, textarea, select, [contenteditable]:not([contenteditable="false"])'));
  }

  function isShown(element) {
    return Boolean(element && !element.hidden && !element.closest('[hidden]'));
  }

  function activeOverlay() {
    var overlays = Array.prototype.slice.call(document.querySelectorAll('[role="dialog"][aria-modal="true"]'));
    for (var index = overlays.length - 1; index >= 0; index -= 1) {
      if (isShown(overlays[index])) { return overlays[index]; }
    }
    return null;
  }

  function focusable() {
    return Array.prototype.slice.call(panel.querySelectorAll(
      'button:not([disabled]), input:not([disabled]), a[href]:not([hidden]):not([tabindex="-1"]), [tabindex]:not([tabindex="-1"])'
    )).filter(function (item) { return item.offsetParent !== null; });
  }

  function resetSequence() {
    pendingPrefix = '';
    if (pendingTimer !== null) {
      window.clearTimeout(pendingTimer);
      pendingTimer = null;
    }
  }

  function startSequence() {
    resetSequence();
    pendingPrefix = 'g';
    pendingTimer = window.setTimeout(function () {
      resetSequence();
      announce('Go to shortcut cancelled.');
    }, 1500);
    announce('Go to shortcut started. Press the destination key.');
  }

  function samePage(command) {
    var destination = new URL(command.href, window.location.href);
    var current = new URL(window.location.href);
    var normalise = function (path) { return path.length > 1 ? path.replace(/\/$/, '') : path; };
    return destination.origin === current.origin && normalise(destination.pathname) === normalise(current.pathname);
  }

  function activateCommand(command) {
    if (!command || !document.contains(command)) {
      announce('That page is not available to your role.');
      return;
    }
    if (samePage(command)) {
      var label = command.querySelector('[data-command-label]');
      announce('You are already on ' + (label ? label.textContent.trim() : 'this page') + '.');
      return;
    }
    command.click();
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
    paletteStatus.textContent = visibleCommands.length === 1
      ? '1 permitted page matches.'
      : visibleCommands.length + ' permitted pages match.';
    setActive(0);
  }

  function openPalette(openedBy) {
    resetSequence();
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

  function closeOverlay(overlay) {
    var close = overlay.querySelector('[data-history-close], [data-filter-close], [data-add-close], [data-bulk-close], [data-import-close], [data-command-close]');
    if (!close) { return false; }
    close.click();
    return true;
  }

  function move(change) {
    if (visibleCommands.length !== 0) { setActive(activeIndex + change); }
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
    if (event.repeat) { return; }

    var key = event.key.toLowerCase();
    var paletteShortcut = (event.ctrlKey || event.metaKey) && !event.altKey && key === 'k';
    var overlay = activeOverlay();

    if (paletteShortcut) {
      if (overlay && overlay !== panel) { return; }
      event.preventDefault();
      if (palette.hidden) { openPalette(document.activeElement); }
      else { closePalette(); }
      return;
    }

    if (!palette.hidden) {
      if (event.key === 'Escape') {
        event.preventDefault();
        closePalette();
      } else if (event.key === 'ArrowDown') {
        event.preventDefault();
        move(1);
      } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        move(-1);
      } else if (event.key === 'Enter' && visibleCommands[activeIndex]
          && !document.activeElement.matches('button')) {
        event.preventDefault();
        activateCommand(visibleCommands[activeIndex]);
      } else if (event.key === 'Tab') {
        var items = focusable();
        if (items.length === 0) {
          event.preventDefault();
          panel.focus();
        } else if (event.shiftKey && document.activeElement === items[0]) {
          event.preventDefault();
          items[items.length - 1].focus();
        } else if (!event.shiftKey && document.activeElement === items[items.length - 1]) {
          event.preventDefault();
          items[0].focus();
        }
      }
      return;
    }

    if (event.key === 'Escape' && overlay) {
      if (closeOverlay(overlay)) {
        event.preventDefault();
        event.stopImmediatePropagation();
      }
      return;
    }

    if (overlay || isEditable(event.target) || event.ctrlKey || event.metaKey || event.altKey) {
      resetSequence();
      return;
    }

    if (pendingPrefix === 'g') {
      var command = shortcutCommands['g ' + key];
      resetSequence();
      if (command) {
        event.preventDefault();
        activateCommand(command);
      } else {
        announce('Go to shortcut cancelled.');
      }
      return;
    }

    if (key === 'g') {
      event.preventDefault();
      startSequence();
    }
  });
}());
