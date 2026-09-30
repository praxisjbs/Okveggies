/** Customer notification bell and panel, for the storefront and Pro shells. */
(function () {
  'use strict';

  // The button renders inside the header, where it belongs. The panel renders
  // outside it, beside the mini-cart drawer, because the storefront header's
  // backdrop-blur makes that header the containing block for fixed descendants
  // and would trap the phone sheet in its 64px bar. So the panel is found by
  // its id rather than looked for inside the button's wrapper.
  var root = document.querySelector('[data-customer-notifications]');
  var backdrop = document.getElementById('okv-customer-notification-panel');
  if (!root || !backdrop) { return; }
  var endpoint = '/api/v1/notifications.php';
  var trigger = root.querySelector('[data-notification-open]');
  var panel = backdrop.querySelector('[role="dialog"]');
  var state = backdrop.querySelector('[data-notification-state]');
  var attentionSection = backdrop.querySelector('[data-notification-attention]');
  var attentionList = backdrop.querySelector('[data-notification-attention-list]');
  var recentSection = backdrop.querySelector('[data-notification-recent]');
  var recentList = backdrop.querySelector('[data-notification-list]');
  var markAll = backdrop.querySelector('[data-notification-mark-all]');
  var badge = root.querySelector('[data-notification-badge]');
  var live = root.querySelector('[data-notification-live]');
  var opener = null;
  var loading = false;
  var narrow = window.matchMedia('(max-width: 767px)');

  function csrf() {
    return window.OKV && window.OKV.csrf ? window.OKV.csrf : '';
  }

  function announce(message) {
    live.textContent = '';
    window.setTimeout(function () { live.textContent = message; }, 10);
  }

  function setBadge(count) {
    var value = Math.max(0, Number(count) || 0);
    badge.textContent = value > 99 ? '99+' : String(value);
    badge.classList.toggle('hidden', value < 1);
    trigger.setAttribute('aria-label', 'Updates, ' + value + ' unread');
  }

  // On desktop the panel is fixed to the viewport instead of absolute inside
  // the bell's wrapper, because it no longer sits in that wrapper. The
  // backdrop is the fixed box, so it carries the coordinates: place it under
  // the bell with the right edges aligned, and when a short window leaves no
  // room below, slide it up so the whole panel stays on screen. The sticky
  // header is 64px, so there is never room above the bell to flip into. On a
  // phone the stylesheet's bottom sheet stands, so the coordinates are
  // cleared.
  function anchorPanel() {
    backdrop.style.top = '';
    backdrop.style.right = '';
    if (narrow.matches) { return; }
    var box = trigger.getBoundingClientRect();
    var gap = 8;
    var below = box.bottom + gap;
    var top = below;
    if (below + panel.offsetHeight > window.innerHeight - gap) {
      top = Math.max(gap, window.innerHeight - panel.offsetHeight - gap);
    }
    backdrop.style.top = Math.round(top) + 'px';
    backdrop.style.right = Math.round(Math.max(gap, window.innerWidth - box.right)) + 'px';
  }

  function clear(element) {
    while (element.firstChild) { element.removeChild(element.firstChild); }
  }

  function text(tag, className, value) {
    var node = document.createElement(tag);
    if (className) { node.className = className; }
    node.textContent = value;
    return node;
  }

  function timeLabel(value) {
    var date = new Date(String(value).replace(' ', 'T') + '+01:00');
    if (Number.isNaN(date.getTime())) { return ''; }
    return new Intl.DateTimeFormat('en-NG', {
      day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit'
    }).format(date);
  }

  function renderAttention(items) {
    clear(attentionList);
    if (!items.length) {
      attentionList.appendChild(text('p', 'text-sm text-ink-60', 'Nothing needs you right now.'));
    } else {
      items.forEach(function (item) {
        var link = document.createElement('a');
        link.href = item.href;
        link.className = 'flex min-h-[44px] items-center justify-between gap-3 rounded-md border border-mist px-3 py-2 text-sm hover:border-forest';
        link.appendChild(text('span', 'font-medium text-ink', item.label));
        link.appendChild(text('span', 'font-mono tabular-nums text-forest', String(item.count)));
        attentionList.appendChild(link);
      });
    }
    attentionSection.classList.remove('hidden');
  }

  function markOne(deliveryId) {
    return window.fetch(endpoint, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
      body: new URLSearchParams({ action: 'mark_read', delivery_id: String(deliveryId), okv_csrf: csrf() })
    });
  }

  function bindNotificationLink(link) {
    if (link.dataset.notificationBound === 'true') { return; }
    link.dataset.notificationBound = 'true';
    link.addEventListener('click', function (event) {
      event.preventDefault();
      var href = link.getAttribute('href') || '/account.php';
      markOne(link.dataset.deliveryId).catch(function () {}).finally(function () {
        window.location.assign(href);
      });
    });
  }

  function renderRecent(items) {
    clear(recentList);
    if (!items.length) {
      recentList.appendChild(text('p', 'py-4 text-sm text-ink-60', 'No updates yet.'));
    } else {
      items.forEach(function (item) {
        var link = document.createElement('a');
        link.href = item.href;
        link.dataset.notificationLink = '';
        link.dataset.deliveryId = String(item.delivery_id);
        link.className = 'block min-h-[44px] py-3';
        if (!item.is_read) { link.classList.add('bg-forest-tint'); }
        var head = document.createElement('span');
        head.className = 'flex items-start justify-between gap-3';
        head.appendChild(text('strong', 'text-sm text-ink', item.title || 'OK Veggies update'));
        head.appendChild(text('span', 'shrink-0 text-xs text-ink-40', timeLabel(item.created_at)));
        link.appendChild(head);
        var body = String(item.body || '');
        link.appendChild(text('span', 'mt-1 block text-xs text-ink-60', body.length > 180 ? body.slice(0, 177) + '...' : body));
        if (!item.is_read) { link.appendChild(text('span', 'mt-1 block text-xs font-medium text-forest', 'Unread')); }
        bindNotificationLink(link);
        recentList.appendChild(link);
      });
    }
    recentSection.classList.remove('hidden');
  }

  function load(silent) {
    if (loading) { return Promise.resolve(); }
    loading = true;
    if (!silent) {
      state.hidden = false;
      state.textContent = 'Loading updates...';
    }
    return window.fetch(endpoint + '?action=list', {
      credentials: 'same-origin', headers: { 'Accept': 'application/json' }
    }).then(function (response) {
      if (!response.ok) { throw new Error('load_failed'); }
      return response.json();
    }).then(function (data) {
      setBadge(data.unread_count);
      renderAttention(Array.isArray(data.attention) ? data.attention : []);
      renderRecent(Array.isArray(data.notifications) ? data.notifications : []);
      state.hidden = true;
      // The rows make the panel taller than the loading state it was placed
      // at, so on desktop it is placed again now that it has its real height.
      if (!backdrop.hidden) { anchorPanel(); }
    }).catch(function () {
      if (!silent) {
        state.hidden = false;
        state.textContent = 'Your updates could not be loaded. Try again.';
      }
    }).finally(function () { loading = false; });
  }

  function focusable() {
    return Array.prototype.slice.call(panel.querySelectorAll('a[href], button:not([disabled])'))
      .filter(function (item) { return item.offsetParent !== null; });
  }

  function openPanel() {
    var other = Array.prototype.find.call(document.querySelectorAll('[role="dialog"][aria-modal="true"]'), function (dialog) {
      return dialog !== panel && !dialog.closest('[hidden], .hidden');
    });
    if (other) { return; }
    opener = document.activeElement;
    backdrop.hidden = false;
    trigger.setAttribute('aria-expanded', 'true');
    document.body.style.overflow = 'hidden';
    panel.focus();
    anchorPanel();
    load(false);
  }

  function closePanel() {
    if (backdrop.hidden) { return; }
    backdrop.hidden = true;
    trigger.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
    backdrop.style.top = '';
    backdrop.style.right = '';
    if (opener && typeof opener.focus === 'function' && document.contains(opener)) { opener.focus(); }
    else { trigger.focus(); }
  }

  trigger.addEventListener('click', openPanel);
  backdrop.querySelector('[data-notification-close]').addEventListener('click', closePanel);
  backdrop.addEventListener('click', function (event) { if (event.target === backdrop) { closePanel(); } });
  panel.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') { event.preventDefault(); closePanel(); return; }
    if (event.key !== 'Tab') { return; }
    var items = focusable();
    if (!items.length) { event.preventDefault(); panel.focus(); return; }
    if (event.shiftKey && document.activeElement === items[0]) { event.preventDefault(); items[items.length - 1].focus(); }
    else if (!event.shiftKey && document.activeElement === items[items.length - 1]) { event.preventDefault(); items[0].focus(); }
  });

  markAll.addEventListener('click', function () {
    markAll.disabled = true;
    window.fetch(endpoint, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
      body: new URLSearchParams({ action: 'mark_all_read', okv_csrf: csrf() })
    }).then(function (response) {
      if (!response.ok) { throw new Error('mark_failed'); }
      setBadge(0);
      announce('All updates are marked as read.');
      return load(true);
    }).catch(function () {
      announce('Your updates could not be changed. Try again.');
    }).finally(function () { markAll.disabled = false; });
  });

  // The panel is fixed to the viewport on desktop, so a resize while it is
  // open has to place it again against the bell's new position.
  window.addEventListener('resize', function () {
    if (!backdrop.hidden) { anchorPanel(); }
  });

  // Parity with the admin bell: refresh in the background on the same cadence
  // while the tab is visible, so a page left open keeps an honest badge.
  window.setInterval(function () {
    if (document.visibilityState === 'visible') { load(true); }
  }, 60000);
}());
