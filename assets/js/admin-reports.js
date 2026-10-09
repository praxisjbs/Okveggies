/**
 * assets/js/admin-reports.js
 * OK Veggies. Draws the Reporting Dashboard charts from the JSON payload PHP
 * printed, using the self-hosted Chart.js. It computes no business figures: the
 * exact numbers are already on the page. Colours are read from brand-token CSS
 * variables, so this file carries no hex.
 */
(function () {
  'use strict';

  if (typeof window.Chart === 'undefined') { return; }

  var source = document.getElementById('okv-report-data');
  if (!source) { return; }

  var payload;
  try {
    payload = JSON.parse(source.textContent || '{}');
  } catch (error) {
    return;
  }

  var root = document.documentElement;
  function token(name) {
    return (window.getComputedStyle(root).getPropertyValue('--okv-c-' + name) || '').trim();
  }
  var COLOUR = {
    forest: token('forest'), foliage: token('foliage'), gold: token('gold'),
    tomato: token('tomato'), clay: token('clay'), ink: token('ink'), mist: token('mist')
  };

  function money(subunit) {
    var amount = Math.trunc(Number(subunit) || 0);
    var negative = amount < 0;
    var naira = Math.floor(Math.abs(amount) / 100);
    return (negative ? '-' : '') + '₦' + naira.toLocaleString('en-NG');
  }

  function shortMoney(subunit) {
    var naira = (Number(subunit) || 0) / 100;
    var abs = Math.abs(naira);
    var suffix = '', divisor = 1;
    if (abs >= 1000000) { suffix = 'm'; divisor = 1000000; }
    else if (abs >= 1000) { suffix = 'k'; divisor = 1000; }
    var v = naira / divisor;
    var rounded = (abs >= 10 * divisor || Number.isInteger(v)) ? v.toFixed(0) : v.toFixed(1);
    return '₦' + rounded + suffix;
  }

  function monthLabel(key) {
    var parts = String(key).split('-');
    if (parts.length !== 2) { return key; }
    var d = new Date(Number(parts[0]), Number(parts[1]) - 1, 1);
    return isNaN(d.getTime()) ? key : d.toLocaleString('en-GB', { month: 'short' });
  }

  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var moneyTooltip = {
    callbacks: {
      label: function (ctx) {
        var label = ctx.dataset.label ? ctx.dataset.label + ': ' : '';
        return label + money(ctx.parsed.y != null ? ctx.parsed.y : ctx.parsed);
      }
    }
  };

  function drawTrend(series) {
    var canvas = document.querySelector('[data-report-trend]');
    if (!canvas || !series.length) { return; }
    var labels = series.map(function (p) { return monthLabel(p.month); });
    new window.Chart(canvas.getContext('2d'), {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [
          { label: 'Revenue', data: series.map(function (p) { return p.revenue; }), backgroundColor: COLOUR.forest, borderRadius: 4, order: 2 },
          { label: 'Expenses', data: series.map(function (p) { return p.expense; }), backgroundColor: COLOUR.clay, borderRadius: 4, order: 2 },
          { label: 'Profit', data: series.map(function (p) { return p.profit; }), type: 'line', borderColor: COLOUR.ink, backgroundColor: COLOUR.ink, tension: 0.3, borderWidth: 2, pointRadius: 3, order: 1 }
        ]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        animation: reduceMotion ? false : { duration: 240 },
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
          tooltip: moneyTooltip
        },
        scales: {
          x: { grid: { display: false } },
          y: { ticks: { callback: function (v) { return shortMoney(v); }, font: { size: 11 } }, grid: { color: COLOUR.mist } }
        }
      }
    });
  }

  function drawDonut(categories) {
    var canvas = document.querySelector('[data-report-donut]');
    if (!canvas || !categories.length) { return; }
    new window.Chart(canvas.getContext('2d'), {
      type: 'doughnut',
      data: {
        labels: categories.map(function (c) { return c.name; }),
        datasets: [{
          data: categories.map(function (c) { return c.amount; }),
          backgroundColor: categories.map(function (c) { return COLOUR[c.colour] || COLOUR.ink; }),
          borderWidth: 2, borderColor: 'white'
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false, cutout: '62%',
        animation: reduceMotion ? false : { duration: 240 },
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: function (ctx) { return ctx.label + ': ' + money(ctx.parsed); } } }
        }
      }
    });
  }

  function init() {
    drawTrend(payload.series || []);
    drawDonut(payload.categories || []);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());
