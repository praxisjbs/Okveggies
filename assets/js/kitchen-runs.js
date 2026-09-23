/**
 * kitchen-runs.js
 * The Kitchen Runs flow: mode cards, then items, then delivery.
 *
 * "Pick from shop" is a picker line per item: one dropdown of the shop's
 * products with their prices, one quantity, and totals that add themselves up
 * as the customer goes. Every other mode types its lines. Both kinds of line
 * live on the same items step, and only the section the chosen mode needs is
 * ever visible or posted: a hidden section has its fields disabled, so a line
 * typed under one mode can never ride along unseen under another.
 *
 * The price shown while picking is today's shop price carried on the option.
 * The server re-reads it from the products table when the list arrives, so the
 * figure the customer approved can never be one the form invented.
 */
(function () {
  'use strict';

  var form = document.querySelector('[data-kr-form]');
  if (!form) return;

  var steps = [1, 2, 3].map(function (n) { return form.querySelector('[data-kr-step="' + n + '"]'); });
  var dots = [1, 2, 3].map(function (n) { return document.querySelector('[data-kr-step-dot="' + n + '"]'); });
  var modeInput = form.querySelector('[data-kr-input-mode]');
  var pricingInput = form.querySelector('[data-kr-pricing-mode]');
  var dateInput = form.querySelector('[data-kr-date]');
  var zoneInput = form.querySelector('[data-kr-zone]');
  var shopHost = form.querySelector('[data-kr-shop-rows]');
  var shopSection = form.querySelector('[data-kr-section-shop]');
  var addShopBtn = form.querySelector('[data-kr-shop-add]');
  var textSection = form.querySelector('[data-kr-section-text]');
  var subLine = form.querySelector('[data-kr-step2-sub]');
  var openBudget = form.querySelector('[data-kr-open]');
  var capField = form.querySelector('[data-kr-cap]');
  var uploadWrap = document.getElementById('kr-upload');
  var errorNote = form.querySelector('[data-kr-error]');
  var step2Error = form.querySelector('[data-kr-error-2]');
  var totalEl = form.querySelector('[data-kr-total]');
  var totalOffshopNote = form.querySelector('[data-kr-total-offshop]');
  var reviewBox = form.querySelector('[data-kr-review]');
  var reviewTotal = form.querySelector('[data-kr-review-total]');
  var reviewNote = form.querySelector('[data-kr-review-note]');
  var backdrop = document.getElementById('kr-backdrop');
  var helpSheet = document.getElementById('kr-help-sheet');
  var helpText = helpSheet ? helpSheet.querySelector('[data-kr-help-text]') : null;

  // A list has an upper bound on the server (KitchenRuns::MAX_LINES). It is
  // rendered onto the form as data-max-lines, so there is one limit and not two
  // that can drift apart, and the count is of lines across both kinds. The
  // server re-checks it, so a form rendered without the attribute never caps
  // here and the server stays the only limit.
  var MAX_ROWS = parseInt(form.getAttribute('data-max-lines') || '', 10);
  if (!isFinite(MAX_ROWS) || MAX_ROWS < 1) { MAX_ROWS = Infinity; }

  var currentStep = 1;

  var SUBTITLES = {
    catalogue: 'Pick from the shop. Prices as marked, total as you go.',
    custom: 'Type each item. We price them and send the quote back.',
    upload: 'Add lines if you like, or just upload your list below.',
    priced: 'Type each item with its price. We confirm and proceed.'
  };

  var HELP = {
    mode: 'Pick how your list comes. Pick from shop shows the prices as you choose.',
    items: 'Add a line for each item. Shop lines price themselves. Typed lines, we price them.',
    delivery: 'Choose day and area. Address in the disclosure.'
  };

  // Kobo to naira, the same way Money::format does it. OKV.money from okv.js
  // is the one formatter; the copy here only stands in if that module failed
  // to load, so a total still reads as money rather than as a raw number.
  function money(subunit) {
    if (window.OKV && typeof window.OKV.money === 'function') {
      return window.OKV.money(subunit);
    }
    var abs = Math.abs(subunit | 0);
    var naira = Math.floor(abs / 100);
    var kobo = abs % 100;
    var text = naira.toLocaleString('en-NG');
    if (kobo !== 0) { text += '.' + String(kobo).padStart(2, '0'); }
    return '\u20A6' + text;
  }

  function showStep(n) {
    currentStep = n;
    steps.forEach(function (el, i) {
      if (!el) return;
      el.hidden = (i + 1) !== n;
    });
    dots.forEach(function (dot, i) {
      if (!dot) return;
      dot.classList.remove('active', 'done');
      if ((i + 1) === n) dot.classList.add('active');
      if ((i + 1) < n) dot.classList.add('done');
      dot.setAttribute('aria-current', (i + 1) === n ? 'step' : 'false');
    });
    if (n === 3) { updateTotals(); }
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function showBackdrop(show) {
    if (!backdrop) return;
    backdrop.hidden = !show;
    backdrop.classList.toggle('hidden', !show);
    document.body.style.overflow = show ? 'hidden' : '';
  }

  function showHelp(key) {
    if (!helpSheet || !helpText) return;
    helpText.textContent = HELP[key] || 'We source market. You approve price.';
    helpSheet.classList.remove('hidden');
    helpSheet.hidden = false;
    showBackdrop(true);
  }

  function hideSheets() {
    if (helpSheet) { helpSheet.classList.add('hidden'); helpSheet.hidden = true; }
    showBackdrop(false);
  }

  // --- The two halves of the items step -------------------------------------

  /** Show one half of the items step and disable whatever it hides, so a
   *  hidden half never posts a line the customer cannot see. */
  function setSection(section, visible) {
    if (!section) return;
    section.hidden = !visible;
    section.classList.toggle('hidden', !visible);
    section.querySelectorAll('input, select, textarea').forEach(function (field) {
      field.disabled = !visible;
    });
  }

  function currentMode() {
    return modeInput ? String(modeInput.value || 'custom') : 'custom';
  }

  function syncMode() {
    var mode = currentMode();
    var isShop = mode === 'catalogue';

    setSection(shopSection, isShop);
    setSection(textSection, !isShop);

    if (uploadWrap) {
      var showUpload = mode === 'upload';
      uploadWrap.hidden = !showUpload;
      uploadWrap.classList.toggle('hidden', !showUpload);
      var file = document.getElementById('kr-attachment');
      if (file) { file.disabled = !showUpload; }
    }

    // Only an "Already priced" list carries the customer's own price per line.
    form.querySelectorAll('[data-kr-price-field]').forEach(function (field) {
      field.hidden = mode !== 'priced';
      field.classList.toggle('hidden', mode !== 'priced');
      var priceInput = field.querySelector('input');
      if (priceInput) { priceInput.disabled = mode !== 'priced'; }
    });

    if (subLine) { subLine.textContent = SUBTITLES[mode] || SUBTITLES.custom; }

    // The numbering follows the visible half of the step: a typed list starts
    // at Item 1, and in "Pick from shop" the typed lines continue the count
    // after the shop lines. Switching modes changes which rows post, so the
    // numbers move with the rows.
    renumber();
    updateTotals();
  }

  // --- Rows: add, remove, renumber ------------------------------------------

  /** Every row that actually posts, in the order the fields are numbered: the
   *  rows of the half of the step the chosen mode is using. A hidden half is
   *  disabled, so its rows take no number at all: in "Type my list" the first
   *  row is Item 1, and in "Pick from shop" the typed rows continue the count
   *  after the shop rows. One shared index, because they all post into the
   *  one items[] list. */
  function allRows() {
    var rows = [];
    function add(host) {
      if (!host) { return; }
      host.querySelectorAll('[data-kr-row]').forEach(function (row) { rows.push(row); });
    }
    var shopVisible = shopSection ? !shopSection.hidden : true;
    var textVisible = textSection ? !textSection.hidden : !shopVisible;
    if (shopVisible) {
      add(shopHost);
      add(shopSection.querySelector('[data-kr-rows]'));
    }
    if (textVisible) {
      add(textSection.querySelector('[data-kr-rows]'));
    }
    return rows;
  }

  function renumberRow(row, idx) {
    row.querySelectorAll('[name]').forEach(function (field) {
      field.name = field.name.replace(/items\[\d+\]/, 'items[' + idx + ']');
    });
    row.querySelectorAll('[id]').forEach(function (field) {
      var fresh = field.id.replace(/-\d+$/, '-' + idx);
      var label = row.querySelector('label[for="' + field.id + '"]');
      if (label) { label.setAttribute('for', fresh); }
      field.id = fresh;
    });
    var label = row.querySelector('[data-kr-label]');
    if (label) { label.textContent = 'Item ' + (idx + 1); }
  }

  function renumber() {
    allRows().forEach(function (row, idx) { renumberRow(row, idx); });
    updateReorderButtons();
  }

  /** Reveal the reorder buttons JavaScript turned on, and hide the ones at the
   *  ends of their own container, so a line can only move where there is room.
   *  Containers are handled one at a time: a shop line and a typed line live in
   *  different sections and never trade places. */
  function updateReorderButtons() {
    form.querySelectorAll('[data-kr-rows], [data-kr-shop-rows]').forEach(function (host) {
      var rows = host.querySelectorAll('[data-kr-row]');
      Array.prototype.forEach.call(rows, function (row, i) {
        var up = row.querySelector('[data-kr-up]');
        var down = row.querySelector('[data-kr-down]');
        if (up) { up.hidden = i === 0; }
        if (down) { down.hidden = i === rows.length - 1; }
      });
    });
  }

  /** Move a row one place up or down inside its own container, then renumber. */
  function moveRow(row, direction) {
    if (!row) { return; }
    var host = row.closest('[data-kr-rows], [data-kr-shop-rows]');
    if (!host) { return; }
    var rows = Array.prototype.slice.call(host.querySelectorAll('[data-kr-row]'));
    var idx = rows.indexOf(row);
    var target = idx + direction;
    if (idx < 0 || target < 0 || target >= rows.length) { return; }
    if (direction < 0) {
      host.insertBefore(row, rows[target]);
    } else {
      host.insertBefore(row, rows[target].nextSibling);
    }
    renumber();
    updateTotals();
  }

  function rowCount() {
    return allRows().length;
  }

  function clearRow(row) {
    row.querySelectorAll('input').forEach(function (field) {
      if (field.type !== 'hidden') { field.value = ''; }
    });
    row.querySelectorAll('select').forEach(function (field) { field.selectedIndex = 0; });
    var total = row.querySelector('[data-kr-line-total]');
    if (total) { total.textContent = money(0); }
    var note = row.querySelector('[data-kr-price-note]');
    if (note) { note.textContent = ''; }
  }

  /** Clone the last row of a host into a fresh empty row after it. */
  function addRowTo(host, focusSelector) {
    if (!host) return;
    var rows = host.querySelectorAll('[data-kr-row]');
    if (!rows.length || rowCount() >= MAX_ROWS) return;
    var clone = rows[rows.length - 1].cloneNode(true);
    clearRow(clone);
    clone.querySelectorAll('input, select, textarea').forEach(function (field) {
      field.disabled = false;
    });
    host.appendChild(clone);
    renumber();
    var focus = clone.querySelector(focusSelector);
    if (focus) { focus.focus(); }
  }

  /** The rows container an "Add item" button belongs to. The button sits beside
   *  its rows, not inside them, so closest() (which only walks ancestors) finds
   *  nothing and the old code silently added no row at all. Walk up to the
   *  nearest block that holds a rows container. */
  function rowsHostNear(btn) {
    var node = btn.parentElement;
    while (node && node !== form) {
      var host = node.querySelector('[data-kr-rows]');
      if (host) { return host; }
      node = node.parentElement;
    }
    return null;
  }

  form.querySelectorAll('[data-kr-add]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      addRowTo(rowsHostNear(btn), '[data-kr-name]');
    });
  });
  if (addShopBtn) {
    addShopBtn.addEventListener('click', function () {
      addRowTo(shopHost, '[data-kr-product]');
    });
  }

  form.addEventListener('click', function (e) {
    var up = e.target.closest('[data-kr-up]');
    if (up) { moveRow(up.closest('[data-kr-row]'), -1); return; }
    var down = e.target.closest('[data-kr-down]');
    if (down) { moveRow(down.closest('[data-kr-row]'), 1); return; }
    var remove = e.target.closest('[data-kr-remove]');
    if (!remove) return;
    var row = remove.closest('[data-kr-row]');
    if (!row) return;
    var host = row.closest('[data-kr-rows], [data-kr-shop-rows]');
    if (!host || host.querySelectorAll('[data-kr-row]').length <= 1) {
      // A host keeps one row, cleared, so there is always a line to type on.
      clearRow(row);
      updateTotals();
      return;
    }
    row.remove();
    renumber();
    updateTotals();
  });

  // --- Totals ----------------------------------------------------------------

  /** The typed rows that count: only the ones in the half of the step the
   *  chosen mode is actually using. */
  function activeTypedRows() {
    var rows = [];
    var section = currentMode() === 'catalogue' ? shopSection : textSection;
    if (section) {
      section.querySelectorAll('[data-kr-rows] [data-kr-row]').forEach(function (row) {
        rows.push(row);
      });
    }
    return rows;
  }

  function readQty(input) {
    var value = parseFloat(String(input ? input.value : '').replace(/,/g, ''));
    return isFinite(value) && value > 0 ? value : null;
  }

  function updateTotals() {
    var total = 0;
    var shopLines = 0;
    var typedLines = 0;

    if (shopHost) {
      shopHost.querySelectorAll('[data-kr-row]').forEach(function (row) {
        var select = row.querySelector('[data-kr-product]');
        var qtyInput = row.querySelector('[data-kr-qty]');
        var lineTotal = row.querySelector('[data-kr-line-total]');
        var note = row.querySelector('[data-kr-price-note]');
        var option = select && select.value ? select.options[select.selectedIndex] : null;
        var price = option ? parseInt(option.getAttribute('data-price') || '0', 10) : 0;
        var unit = option ? String(option.getAttribute('data-unit') || '') : '';
        var qty = readQty(qtyInput);
        if (note) { note.textContent = option ? money(price) + (unit ? ' per ' + unit : '') : ''; }
        if (lineTotal) { lineTotal.textContent = option && qty !== null ? money(Math.round(qty * price)) : money(0); }
        if (option && qty !== null) {
          total += Math.round(qty * price);
          shopLines++;
        }
      });
    }

    activeTypedRows().forEach(function (row) {
      var name = row.querySelector('[data-kr-name]');
      if (name && String(name.value).trim() !== '') { typedLines++; }
    });

    if (totalEl) { totalEl.textContent = money(total); }
    if (totalOffshopNote) { totalOffshopNote.hidden = typedLines === 0; }

    var isShop = currentMode() === 'catalogue';
    if (reviewBox) { reviewBox.hidden = !isShop; }
    if (reviewTotal) { reviewTotal.textContent = money(total); }
    if (reviewNote) { reviewNote.hidden = typedLines === 0; }
  }

  form.addEventListener('input', function (e) {
    if (e.target.matches('[data-kr-qty], [data-kr-name]')) { updateTotals(); }
  });
  form.addEventListener('change', function (e) {
    if (e.target.matches('[data-kr-product]')) { updateTotals(); }
  });

  // --- Mode cards ------------------------------------------------------------

  form.querySelectorAll('[data-kr-mode]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var mode = btn.getAttribute('data-kr-mode');
      if (modeInput) { modeInput.value = mode; }
      if (pricingInput) { pricingInput.value = mode === 'priced' ? 'already_priced' : 'by_us'; }
      form.setAttribute('data-start', mode);
      form.querySelectorAll('[data-kr-mode]').forEach(function (b) {
        var active = b.getAttribute('data-kr-mode') === mode;
        b.setAttribute('aria-pressed', active ? 'true' : 'false');
        b.classList.toggle('border-forest', active);
        b.classList.toggle('bg-forest/5', active);
        b.classList.toggle('ring-1', active);
        b.classList.toggle('ring-forest', active);
      });
      syncMode();
    });
  });

  // --- Validation and step moves ----------------------------------------------

  function showError(msg) {
    var target = currentStep === 2 ? step2Error : errorNote;
    if (!target) return;
    target.textContent = msg;
    target.hidden = !msg;
    target.classList.toggle('hidden', !msg);
    if (msg) { target.scrollIntoView({ block: 'nearest' }); }
  }

  /** The plain sentence for what is missing, or an empty string when the list
   *  can go. The server checks the same rules again with the real ones. */
  function itemsProblem() {
    var mode = currentMode();
    var file = document.getElementById('kr-attachment');
    var hasFile = !!(file && file.files && file.files.length > 0);

    var shopComplete = 0;
    var shopHalf = false;
    if (shopHost) {
      shopHost.querySelectorAll('[data-kr-row]').forEach(function (row) {
        var select = row.querySelector('[data-kr-product]');
        var qty = row.querySelector('[data-kr-qty]');
        var hasItem = !!(select && select.value);
        var hasQty = !!(qty && String(qty.value).trim() !== '');
        if (hasItem && hasQty) { shopComplete++; }
        else if (hasItem || hasQty) { shopHalf = true; }
      });
    }

    var typedComplete = 0;
    var typedNoName = false;
    var typedNoQty = false;
    var typedNoPrice = false;
    activeTypedRows().forEach(function (row) {
      var name = row.querySelector('[data-kr-name]');
      var qty = row.querySelector('[data-kr-qty]');
      var unit = row.querySelector('[data-kr-unit]');
      var price = row.querySelector('[data-kr-price]');
      var note = row.querySelector('[data-kr-note]');
      var hasName = !!(name && String(name.value).trim() !== '');
      var hasQty = !!(qty && String(qty.value).trim() !== '');
      var hasUnit = !!(unit && String(unit.value) !== '');
      var hasPrice = !!(price && String(price.value).trim() !== '');
      var hasNote = !!(note && String(note.value).trim() !== '');
      var touched = hasName || hasQty || hasUnit || hasPrice || hasNote;
      if (!touched) { return; }
      if (hasName && hasQty && hasUnit && (mode !== 'priced' || hasPrice)) {
        typedComplete++;
        return;
      }
      if (hasName) {
        if (!hasQty || !hasUnit) { typedNoQty = true; }
        if (mode === 'priced' && !hasPrice) { typedNoPrice = true; }
      } else {
        typedNoName = true;
      }
    });

    if (mode === 'catalogue') {
      if (shopHalf) { return 'Every shop line needs an item and how much of it.'; }
      if (typedNoName) { return 'Give every typed line an item name.'; }
      if (typedNoQty) { return 'Give a quantity and a unit for every item you typed.'; }
      if (shopComplete === 0 && typedComplete === 0) { return 'Add at least one item to your list.'; }
      return '';
    }

    if (typedNoName) { return 'Give every line an item name.'; }
    if (typedNoQty) { return 'Give a quantity and a unit for every item.'; }
    if (mode === 'priced' && typedNoPrice) { return 'Give your price for every item.'; }
    if (mode === 'upload') {
      if (!hasFile && typedComplete === 0) { return 'Upload your list, or add items to it.'; }
      return '';
    }
    if (typedComplete === 0) { return 'Add at least one item to your list.'; }
    return '';
  }

  form.querySelectorAll('[data-kr-next]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var next = parseInt(btn.getAttribute('data-kr-next'), 10) + 1;
      if (next === 2) {
        if (!modeInput || !modeInput.value) {
          showError('Pick a mode first.');
          return;
        }
      }
      if (next === 3) {
        showStep(2);
        var problem = itemsProblem();
        if (problem) {
          showError(problem);
          return;
        }
      }
      showError('');
      showStep(next);
    });
  });
  form.querySelectorAll('[data-kr-back]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      showError('');
      showStep(parseInt(btn.getAttribute('data-kr-back'), 10));
    });
  });
  dots.forEach(function (dot, i) {
    if (!dot) return;
    dot.addEventListener('click', function () {
      var target = i + 1;
      if (target <= currentStep) showStep(target);
    });
  });

  // --- Open budget, days, areas, help -----------------------------------------

  if (openBudget && capField) {
    var syncCap = function () {
      capField.hidden = !openBudget.checked;
      capField.classList.toggle('hidden', !openBudget.checked);
    };
    openBudget.addEventListener('change', syncCap);
    syncCap();
  }

  form.querySelectorAll('[data-kr-day]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var d = btn.getAttribute('data-kr-day');
      if (dateInput) dateInput.value = d;
      form.querySelectorAll('[data-kr-day]').forEach(function (b) {
        b.classList.remove('border-forest', 'bg-forest', 'text-white');
        b.classList.add('border-ink-10', 'bg-white');
      });
      btn.classList.remove('border-ink-10', 'bg-white');
      btn.classList.add('border-forest', 'bg-forest', 'text-white');
    });
  });
  form.querySelectorAll('[data-kr-zone-btn]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var z = btn.getAttribute('data-kr-zone-btn');
      if (zoneInput) zoneInput.value = z;
      form.querySelectorAll('[data-kr-zone-btn]').forEach(function (b) {
        b.classList.remove('border-forest', 'bg-forest', 'text-white');
        b.classList.add('border-ink-10', 'bg-white');
      });
      btn.classList.remove('border-ink-10', 'bg-white');
      btn.classList.add('border-forest', 'bg-forest', 'text-white');
    });
  });

  form.querySelectorAll('[data-kr-help]').forEach(function (btn) {
    btn.addEventListener('click', function () { showHelp(btn.getAttribute('data-kr-help')); });
  });
  if (backdrop) {
    backdrop.addEventListener('click', hideSheets);
  }
  document.querySelectorAll('[data-kr-close]').forEach(function (btn) {
    btn.addEventListener('click', hideSheets);
  });

  // --- Send -------------------------------------------------------------------

  function endpoint() { return form.getAttribute('action') || '/api/v1/kitchen_runs.php'; }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    showError('');
    var problem = itemsProblem();
    if (problem) {
      showStep(2);
      showError(problem);
      return;
    }
    var btn = form.querySelector('button[type="submit"]');
    if (btn) btn.disabled = true;
    fetch(endpoint(), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
      body: new FormData(form)
    }).then(function (res) {
      return res.json().then(function (body) { return { ok: res.ok, body: body }; });
    }).then(function (result) {
      if (result.ok && result.body && result.body.id) {
        // The server says where a successful send lands, with the right
        // success flag on the URL: quoted for a shop-priced list, submitted
        // for one waiting on the team.
        window.location.href = result.body.redirect
          || '/kitchen-runs.php?request=' + encodeURIComponent(result.body.id) + '&submitted=1';
        return;
      }
      if (btn) btn.disabled = false;
      showError((result.body && result.body.message) || 'Could not send list. Try again.');
    }).catch(function () {
      if (btn) btn.disabled = false;
      showError('No connection. Check and try again.');
    });
  });

  // --- Start ------------------------------------------------------------------

  syncMode();
  renumber();
  updateTotals();
  showStep(1);
})();
