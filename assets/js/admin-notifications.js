/** Shared permission-filtered admin notification bell and panel. */
(function () {
  'use strict';

  var root = document.querySelector('[data-admin-notifications]');
  if (!root) { return; }
  var trigger = root.querySelector('[data-notification-open]');
  var backdrop = root.querySelector('[data-notification-backdrop]');
  var panel = backdrop.querySelector('[role="dialog"]');
  var state = root.querySelector('[data-notification-state]');
  var attentionSection = root.querySelector('[data-notification-attention]');
  var attentionList = root.querySelector('[data-notification-attention-list]');
  var recentSection = root.querySelector('[data-notification-recent]');
  var recentList = root.querySelector('[data-notification-list]');
  var markAll = root.querySelector('[data-notification-mark-all]');
  var badge = root.querySelector('[data-notification-badge]');
  var live = root.querySelector('[data-notification-live]');
  var opener = null;
  var loading = false;

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
    trigger.setAttribute('aria-label', 'Notifications, ' + value + ' unread');
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
      attentionList.appendChild(text('p', 'text-sm text-ink-60', 'Nothing needs your attention right now.'));
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
    var body = new URLSearchParams({
      action: 'mark_read', delivery_id: String(deliveryId), okv_csrf: csrf()
    });
    return window.fetch('/api/v1/admin_notifications.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }, body: body
    });
  }

  function bindNotificationLink(link) {
    if (link.dataset.notificationBound === 'true') { return; }
    link.dataset.notificationBound = 'true';
    link.addEventListener('click', function (event) {
      event.preventDefault();
      var href = link.getAttribute('href') || '/admin/';
      markOne(link.dataset.deliveryId).catch(function () {}).finally(function () {
        window.location.assign(href);
      });
    });
  }

  function renderRecent(items) {
    clear(recentList);
    if (!items.length) {
      recentList.appendChild(text('p', 'py-4 text-sm text-ink-60', 'No notification messages yet.'));
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
      state.textContent = 'Loading notifications...';
    }
    return window.fetch('/api/v1/admin_notifications.php?action=list', {
      credentials: 'same-origin', headers: { 'Accept': 'application/json' }
    }).then(function (response) {
      if (!response.ok) { throw new Error('load_failed'); }
      return response.json();
    }).then(function (data) {
      setBadge(data.unread_count);
      renderAttention(Array.isArray(data.attention) ? data.attention : []);
      renderRecent(Array.isArray(data.notifications) ? data.notifications : []);
      state.hidden = true;
    }).catch(function () {
      if (!silent) {
        state.hidden = false;
        state.textContent = 'Notifications could not be loaded. Try again.';
      }
    }).finally(function () { loading = false; });
  }

  function focusable() {
    return Array.prototype.slice.call(panel.querySelectorAll('a[href], button:not([disabled])'))
      .filter(function (item) { return item.offsetParent !== null; });
  }

  function openPanel() {
    var other = Array.prototype.find.call(document.querySelectorAll('[role="dialog"][aria-modal="true"]'), function (dialog) {
      return dialog !== panel && !dialog.closest('[hidden]');
    });
    if (other) { return; }
    opener = document.activeElement;
    backdrop.hidden = false;
    trigger.setAttribute('aria-expanded', 'true');
    document.body.style.overflow = 'hidden';
    panel.focus();
    load(false);
  }

  function closePanel() {
    if (backdrop.hidden) { return; }
    backdrop.hidden = true;
    trigger.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
    if (opener && typeof opener.focus === 'function' && document.contains(opener)) { opener.focus(); }
    else { trigger.focus(); }
  }

  trigger.addEventListener('click', openPanel);
  root.querySelector('[data-notification-close]').addEventListener('click', closePanel);
  backdrop.addEventListener('click', function (event) { if (event.target === backdrop) { closePanel(); } });
  panel.addEventListener('keydown', function (event) {
    if (event.key !== 'Tab') { return; }
    var items = focusable();
    if (!items.length) { event.preventDefault(); panel.focus(); return; }
    if (event.shiftKey && document.activeElement === items[0]) { event.preventDefault(); items[items.length - 1].focus(); }
    else if (!event.shiftKey && document.activeElement === items[items.length - 1]) { event.preventDefault(); items[0].focus(); }
  });

  markAll.addEventListener('click', function () {
    markAll.disabled = true;
    window.fetch('/api/v1/admin_notifications.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
      body: new URLSearchParams({ action: 'mark_all_read', okv_csrf: csrf() })
    }).then(function (response) {
      if (!response.ok) { throw new Error('mark_failed'); }
      setBadge(0);
      announce('All notifications are marked as read.');
      return load(true);
    }).catch(function () {
      announce('Notifications could not be updated. Try again.');
    }).finally(function () { markAll.disabled = false; });
  });

  document.querySelectorAll('[data-notification-link]').forEach(bindNotificationLink);

  window.setInterval(function () {
    if (document.visibilityState === 'visible') { load(true); }
  }, 60000);
}());
