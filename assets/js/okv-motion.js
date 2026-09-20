/**
 * assets/js/okv-motion.js
 * -----------------------------------------------------------------------------
 * OK Veggies. PR8 Motion System, fix 30. One owner for every scroll entrance,
 * hero moment, sheet and micro feedback on the storefront and the Pro portal.
 * Pages carry hooks in the markup only; this file does the rest. Scripts, all
 * defer, no npm step: vendor/gsap.min.js, vendor/ScrollTrigger.min.js
 * (self-hosted GSAP 3.12.5 with SRI pins), then okv-motion.min.js. If the
 * vendored files cannot load, the same bytes are retried from pinned backup CDN
 * copies; failing that the page stays still and fully working, and the header's
 * dead man switch uncovers content after 2.5 seconds.
 * House rules: transform and opacity only, never width or height; will-change
 * cleared at tween end; entrances generous at 400 to 650ms; per the client
 * decision of 20 Sep 2026 prefers-reduced-motion does not collapse motion and
 * is ignored on purpose; no colour is set here. Presets: ui-ux-pro-max GSAP
 * catalogue (plan Section 12.4).
 */
(function () {
  'use strict';
  var PENDING = 'okv-motion-pending';
  var ON = 'okv-motion-on';
  var DEBUG = /[?&]okv-motion-debug=1/.test(window.location.search);
  var root = document.documentElement;
  var started = false;
  var touched = [];
  // The scroll entrance family. A component opts in by carrying one of these
  // classes, so new screens move with no JavaScript anywhere.
  var GROUP_SEL = '.okv-panel, .okv-card, .okv-step-card, .okv-empty, .okv-enter, '
    + '[data-product-card], [data-combo-card], [data-faq-item], [data-kr-mode], '
    + '[data-payment-options] > .okv-choice, [data-okv-rise]';
  var SKIP_SEL = '[data-okv-static], .okv-skeleton, .okv-sheet-backdrop, #okv-mini-cart, [hidden]';
  // Backup CDN pins of the exact bytes we vendor; both sources serve the
  // unmodified 3.12.5 distribution, so one integrity pair covers both.
  var FILES = {
    gsap: { file: 'gsap.min.js', hash: 'sha384-g4NTh/Iv5PPU4xPyhEWqPcwtNXOvdaDI8LLnyYfyNZOjKJeYQyjzQ9X5275eBjpt' },
    st: { file: 'ScrollTrigger.min.js', hash: 'sha384-Z3REaz79l2IaAZqJsSABtTbhjgOUYyV3p90XNnAPCSHg3EMTz1fouunq9WZRtj3d' }
  };
  var CDN_BASES = [
    window.OKV_MOTION_CDN_BASE || 'https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/',
    'https://unpkg.com/gsap@3.12.5/dist/'
  ];
  function remember(els) {
    var list = els.length === undefined ? [els] : els;
    for (var i = 0; i < list.length; i++) { if (touched.indexOf(list[i]) === -1) { touched.push(list[i]); } }
    return els;
  }
  function removePending() { root.classList.remove(PENDING); }

  /** If anything faults, hand the page back to the stylesheet untouched. */
  function fallBackToStatic() {
    try {
      if (window.ScrollTrigger) { ScrollTrigger.getAll().forEach(function (t) { t.kill(); }); }
      if (window.gsap) { gsap.killTweensOf('*'); }
    } catch (e) { /* the static page is the goal */ }
    touched.forEach(function (el) { if (el && el.style) { el.style.opacity = ''; el.style.transform = ''; el.style.willChange = ''; } });
    touched = [];
    root.classList.remove(ON);
    removePending();
  }
  function loadScript(base, meta, onLoad, onError) {
    var el = document.createElement('script');
    el.src = base + meta.file;
    el.integrity = meta.hash;
    el.crossOrigin = 'anonymous';
    el.onload = onLoad;
    el.onerror = onError;
    document.head.appendChild(el);
  }
  function loadPair(baseIndex) {
    if (baseIndex >= CDN_BASES.length) { fallBackToStatic(); return; }
    loadScript(CDN_BASES[baseIndex], FILES.gsap, function () {
      loadScript(CDN_BASES[baseIndex], FILES.st, init, function () { loadPair(baseIndex + 1); });
    }, function () { loadPair(baseIndex + 1); });
  }
  function boot() {
    if (started) { return; }
    started = true;
    // Content is ours to time now; if gsap never arrives the header glue's
    // dead man switch uncovers it anyway.
    removePending();
    if (!window.gsap || !window.ScrollTrigger) { loadPair(0); return; }
    init();
  }

  // ---- Hero: the stamp, the words, the rule and the parallax ----------------
  function splitWords(h1) {
    var words = (h1.textContent || '').trim().split(/\s+/);
    h1.textContent = '';
    words.forEach(function (word, i) {
      if (i > 0) { h1.appendChild(document.createTextNode(' ')); }
      var span = document.createElement('span');
      span.setAttribute('data-okv-word', '');
      span.style.display = 'inline-block';
      span.textContent = word;
      h1.appendChild(span);
    });
    return h1.querySelectorAll('[data-okv-word]');
  }
  function initHero() {
    var hero = document.querySelector('[data-okv-hero]');
    if (!hero) { return; }
    var section = hero.closest('section') || hero;
    var tl = gsap.timeline();
    var seal = hero.querySelector('[data-okv-hero-seal]');
    var h1 = hero.querySelector('h1');
    var sub = hero.querySelector('[data-okv-hero-sub]');
    var cta = hero.querySelector('[data-okv-hero-cta]');
    var hairline = hero.querySelector('[data-okv-hero-hairline]');
    if (seal) { remember(seal); tl.fromTo(seal, { opacity: 0, scale: 0.8 }, { opacity: 1, scale: 1, duration: 0.5, ease: 'back.out(1.2)' }, 0); }
    var headingStart = 0.15;
    var headingEnd = headingStart + 0.55;
    if (h1) {
      var spans = remember(splitWords(h1));
      tl.fromTo(spans, { opacity: 0, y: 24 }, { opacity: 1, y: 0, duration: 0.55, ease: 'power2.out', stagger: 0.04 }, headingStart);
      headingEnd = headingStart + 0.55 + 0.04 * Math.max(spans.length - 1, 0);
    }
    var follow = headingEnd + 0.12;
    if (sub) { remember(sub); tl.fromTo(sub, { opacity: 0, y: 24 }, { opacity: 1, y: 0, duration: 0.5, ease: 'power2.out' }, follow); }
    if (cta) { remember(cta); tl.fromTo(cta, { opacity: 0, y: 24 }, { opacity: 1, y: 0, duration: 0.5, ease: 'power2.out' }, follow); }
    if (hairline) { remember(hairline); tl.fromTo(hairline, { scaleX: 0 }, { scaleX: 1, duration: 0.9, ease: 'expo.out', transformOrigin: 'left center' }, follow); }
    // The documentary photograph drifts 8% slower than the page, inside its
    // own clipping figure, so no layout ever moves. The extra scale covers the
    // drift so no edge shows.
    var photo = hero.querySelector('[data-okv-parallax]');
    if (photo) {
      remember(photo);
      gsap.set(photo, { scale: 1.08 });
      gsap.to(photo, { yPercent: -8, ease: 'none', scrollTrigger: { trigger: section, start: 'top top', end: 'bottom top', scrub: 1 } });
    }
  }

  // ---- Scroll entrances: every panel and card rises 20px, staggered ---------
  function staggerFor(els) {
    var first = els[0];
    if (!first) { return 0.06; }
    if (first.hasAttribute('data-kr-mode')) { return 0.08; }
    if (first.closest('[data-payment-options]')) { return 0.05; }
    return 0.06;
  }
  function entranceTargets(scope) {
    var found = [];
    try { found = Array.prototype.slice.call(scope.querySelectorAll(GROUP_SEL)); } catch (e) { return found; }
    return found.filter(function (el) { return !el.hasAttribute('data-okv-done') && !el.closest(SKIP_SEL); });
  }
  function prep(els) {
    els.forEach(function (el) { el.setAttribute('data-okv-done', ''); });
    remember(els);
    gsap.set(els, { opacity: 0, y: 20, willChange: 'transform, opacity' });
  }
  function reveal(batch, instant) {
    if (!batch.length) { return; }
    gsap.to(batch, {
      opacity: 1, y: 0, duration: instant ? 0.2 : 0.5, ease: instant ? 'power1.out' : 'power2.out',
      stagger: instant ? 0 : staggerFor(batch), overwrite: 'auto', clearProps: 'transform,opacity,will-change'
    });
  }
  function batch(targets, start) {
    ScrollTrigger.batch(targets, { start: start || 'top 92%', once: true, interval: 0.08, batchMax: 10, onEnter: function (entered) { reveal(entered); } });
  }
  function initScroll() {
    var first = entranceTargets(document.querySelector('main') || document.body);
    if (first.length) { prep(first); batch(first); }
    // A keyboard user can tab to a card that is still below the fold. Reveal
    // it at once rather than let focus land on something invisible.
    document.addEventListener('focusin', function (event) {
      var el = event.target.closest ? event.target.closest(GROUP_SEL) : null;
      if (el && el.style.opacity === '0') { reveal([el], true); }
    }, true);
  }
  // Nodes from a fetch render (shop results, pagination) join the system the
  // same way, which is also the page transition: swapped-in cards rise and
  // stagger instead of the page flashing.
  function watchNodes() {
    if (!window.MutationObserver) { return; }
    var timer = null;
    var scan = function () {
      var fresh = entranceTargets(document.querySelector('main') || document.body);
      if (fresh.length) { prep(fresh); ScrollTrigger.batch(fresh, { start: 'top 96%', once: true, interval: 0.08, batchMax: 10, onEnter: function (entered) { reveal(entered); } }); }
    };
    new MutationObserver(function () { if (timer) { clearTimeout(timer); } timer = setTimeout(scan, 120); })
      .observe(document.body, { childList: true, subtree: true });
  }

  // ---- Sheets: y 100% to 0, backdrop fade, swipe down to dismiss -----------
  function panelOf(backdrop) {
    return backdrop.querySelector('[role="dialog"]') || backdrop.querySelector('section') || backdrop.firstElementChild;
  }
  function isDrawer(backdrop) { return backdrop.id === 'okv-mini-cart'; }
  function overlayOf(backdrop) { return isDrawer(backdrop) ? backdrop.firstElementChild : backdrop; }
  function slideTo(backdrop, edge) { var to = {}; to[isDrawer(backdrop) ? 'x' : 'y'] = edge; return to; }
  function backdrops() {
    var list = Array.prototype.slice.call(document.querySelectorAll('.okv-sheet-backdrop'));
    var cart = document.getElementById('okv-mini-cart');
    if (cart) { list.push(cart); }
    return list; }
  function openMotion(backdrop) {
    var panel = panelOf(backdrop);
    var overlay = overlayOf(backdrop);
    if (!panel) { return; }
    remember(panel);
    if (overlay !== backdrop) { remember(overlay); }
    gsap.killTweensOf(panel);
    gsap.fromTo(panel, slideTo(backdrop, '104%'), Object.assign({ duration: 0.45, ease: 'expo.out', clearProps: 'transform,will-change' }, slideTo(backdrop, '0%')));
    if (overlay) { gsap.fromTo(overlay, { opacity: 0 }, { opacity: 1, duration: 0.24, ease: 'power2.out', clearProps: 'opacity' }); }
  }
  function closeMotion(backdrop, done) {
    var panel = panelOf(backdrop);
    var overlay = overlayOf(backdrop);
    var finish = function () {
      gsap.set(panel, { clearProps: 'transform,opacity,will-change' });
      if (overlay) { gsap.set(overlay, { clearProps: 'opacity' }); }
      if (typeof done === 'function') { done(); }
    };
    if (overlay) { gsap.to(overlay, { opacity: 0, duration: 0.2, ease: 'power2.in' }); }
    if (!panel) { finish(); return; }
    gsap.to(panel, Object.assign({ duration: 0.28, ease: 'power2.in', willChange: 'transform', onComplete: finish }, slideTo(backdrop, '104%')));
  }
  function refire(target) {
    var again = new MouseEvent('click', { bubbles: true, cancelable: true, view: window });
    again.okvMotionSynthetic = true;
    (target || document).dispatchEvent(again);
  }
  function initSheets() {
    var watcher = new MutationObserver(function (records) {
      records.forEach(function (record) {
        if (record.attributeName === 'hidden' && !record.target.hidden) { openMotion(record.target); }
      });
    });
    backdrops().forEach(function (backdrop) { watcher.observe(backdrop, { attributes: true, attributeFilter: ['hidden'] }); });
    // A close click is held while the panel slides out, then handed on, so the
    // page's own listener still hides the sheet and returns focus.
    document.addEventListener('click', function (event) {
      if (event.okvMotionSynthetic) { return; }
      var backdrop = event.target.closest
        ? event.target.closest('.okv-sheet-backdrop:not([hidden]), #okv-mini-cart:not([hidden])') : null;
      if (!backdrop) { return; }
      var closer = event.target.closest('[data-sheet-close], [data-mini-cart-close], [data-filter-close], [data-okv-close]');
      if (!closer && event.target !== backdrop && event.target !== overlayOf(backdrop)) { return; }
      event.preventDefault();
      event.stopImmediatePropagation();
      closeMotion(backdrop, function () { refire(event.target); });
    }, true);
    initSwipe();
  }
  function initSwipe() {
    var tracking = null;
    document.addEventListener('pointerdown', function (event) {
      if (event.pointerType === 'mouse' && event.button !== 0) { return; }
      var panel = event.target.closest ? event.target.closest('.okv-sheet-backdrop:not([hidden]) .okv-sheet') : null;
      if (panel) { tracking = { panel: panel, backdrop: panel.closest('.okv-sheet-backdrop'), startY: event.clientY, dy: 0, engaged: false, id: event.pointerId }; }
    }, true);
    document.addEventListener('pointermove', function (event) {
      if (!tracking || event.pointerId !== tracking.id) { return; }
      tracking.dy = event.clientY - tracking.startY;
      // Engage only when the panel cannot scroll up any further and the finger
      // is heading down: that gesture means dismiss, not scroll.
      if (!tracking.engaged && tracking.dy > 12 && tracking.panel.scrollTop <= 0) {
        tracking.engaged = true;
        try { tracking.panel.setPointerCapture(tracking.id); } catch (e) { /* the drag still works */ }
      }
      if (!tracking.engaged) { return; }
      event.preventDefault();
      gsap.set(tracking.panel, { y: Math.max(tracking.dy, 0) * 0.55, willChange: 'transform' });
    }, { capture: true, passive: false });
    document.addEventListener('pointerup', function (event) {
      if (!tracking || event.pointerId !== tracking.id) { return; }
      var state = tracking;
      tracking = null;
      if (!state.engaged) { return; }
      if (state.dy <= 96) { gsap.to(state.panel, { y: 0, duration: 0.32, ease: 'elastic.out(1, 0.5)', clearProps: 'will-change' }); return; }
      closeMotion(state.backdrop, function () {
        refire(state.backdrop.querySelector('[data-sheet-close], [data-filter-close]') || state.backdrop);
      });
    }, true);
  }

  // ---- Micro feedback: press, add to basket, shake, success -----------------

  /** The success pop: the Market Bounce, elastic.out, about 320ms. */
  function bounceTimeline(el) {
    remember(el);
    return gsap.timeline({ overwrite: 'auto' }).to(el, { scale: 0.9, duration: 0.1, ease: 'power2.in' })
      .to(el, { scale: 1.08, duration: 0.15, ease: 'power2.out' })
      .to(el, { scale: 1, duration: 0.25, ease: 'elastic.out(1, 0.5)', clearProps: 'transform,will-change' });
  }

  /** The error shake: x -4 to 4, twice, power2.inOut, 300ms in all. */
  function shake(el) {
    if (!el || !window.gsap) { return; }
    remember(el);
    gsap.fromTo(el, { x: -4 }, {
      x: 4, duration: 0.15, ease: 'power2.inOut', repeat: 1, yoyo: true, overwrite: 'auto',
      onComplete: function () { gsap.set(el, { x: 0, clearProps: 'transform,will-change' }); }
    });
  }

  /** Stroke-draw the line icon inside a button, the basket tick moment. */
  function drawIcon(el) {
    var paths = el.querySelectorAll ? el.querySelectorAll('svg path, svg circle, svg polyline') : [];
    Array.prototype.forEach.call(paths, function (path, i) {
      var length = 0;
      try { length = path.getTotalLength(); } catch (e) { return; }
      if (!length || !isFinite(length)) { return; }
      remember(path);
      gsap.fromTo(path, { strokeDasharray: length, strokeDashoffset: length },
        { strokeDashoffset: 0, duration: 0.3, delay: 0.05 * i, ease: 'power2.out', clearProps: 'strokeDasharray,strokeDashoffset' });
    });
  }
  function initMicro() {
    // Press. Buttons that already press with a CSS active:scale are left alone.
    document.addEventListener('pointerdown', function (event) {
      var btn = event.target.closest ? event.target.closest('.okv-btn, .okv-btn-outline, .okv-btn-outline-invert, button') : null;
      if (!btn || btn.disabled || btn.getAttribute('aria-disabled') === 'true' || /active:scale/.test(btn.className)) { return; }
      remember(btn);
      gsap.to(btn, { scale: 0.98, duration: 0.1, ease: 'power2.out', overwrite: 'auto' });
      var release = function () { gsap.to(btn, { scale: 1, duration: 0.25, ease: 'back.out(2)', overwrite: 'auto', clearProps: 'transform' }); };
      window.addEventListener('pointerup', release, { once: true });
      btn.addEventListener('pointerleave', release, { once: true });
    }, true);
    // Add to basket, the Bounce moment, with the basket icon drawing itself.
    document.addEventListener('submit', function (event) {
      var form = event.target;
      if (!form.matches || !form.matches('form[data-add-form]')) { return; }
      var btn = form.querySelector('[data-add-button]') || form.querySelector('button');
      if (btn) { bounceTimeline(btn); drawIcon(btn); }
    }, true);
    // A field that will not validate shakes its form.
    document.addEventListener('invalid', function (event) {
      var host = event.target.closest ? event.target.closest('.okv-panel, form, fieldset') : null;
      if (host) { shake(host); }
    }, true);
    // Server answers: a bad note shakes in, a good note pops, a toast rises.
    if (!window.MutationObserver) { return; }
    new MutationObserver(function (records) {
      records.forEach(function (record) {
        Array.prototype.forEach.call(record.addedNodes, function (node) {
          if (node.nodeType !== 1 || !node.classList) { return; }
          if (node.classList.contains('okv-note-bad')) { shake(node.closest('form, .okv-panel') || node); }
          if (node.classList.contains('okv-note-ok')) { bounceTimeline(node); }
          if (node.id === 'okv-toast') { watchToasts(node); }
        });
      });
    }).observe(document.body, { childList: true, subtree: true });
  }
  function watchToasts(host) {
    new MutationObserver(function (records) {
      records.forEach(function (record) {
        Array.prototype.forEach.call(record.addedNodes, function (toast) {
          if (toast.nodeType !== 1) { return; }
          remember(toast);
          gsap.from(toast, { y: 14, opacity: 0, duration: 0.24, ease: 'power2.out', clearProps: 'transform,opacity' });
        });
      });
    }).observe(host, { childList: true });
  }

  /** Shop results: a view-transition cross fade where the browser has one. */
  function initTransitions() {
    if (!document.startViewTransition) { return; }
    var results = document.querySelector('[data-shop-results]');
    if (!results) { return; }
    try {
      var descriptor = Object.getOwnPropertyDescriptor(Element.prototype, 'innerHTML');
      var swap = function (value) { descriptor.set.call(results, value); };
      Object.defineProperty(results, 'innerHTML', {
        set: function (value) {
          if (results.childNodes.length === 0) { swap(value); return; }
          document.startViewTransition(function () { swap(value); });
        },
        get: function () { return descriptor.get.call(results); }
      });
    } catch (e) { /* the plain swap still works */ }
  }
  function init() {
    try {
      gsap.registerPlugin(ScrollTrigger);
      if (DEBUG) { ScrollTrigger.defaults({ markers: true }); }
      root.classList.add(ON);
      removePending();
      initHero();
      initScroll();
      initSheets();
      initMicro();
      initTransitions();
      watchNodes();
    } catch (err) {
      // Motion is a remark, never a gate: on a fault, hand the page back.
      fallBackToStatic();
    }
  }
  window.OkvMotion = {
    version: '1.0.0', ownsSheets: true, init: boot, shake: shake, pop: bounceTimeline, draw: drawIcon,
    isOn: function () { return root.classList.contains(ON); }
  };
  boot();
})();
