/**
 * OK Veggies M11 dashboard charts.
 *
 * Exact figures and empty states are rendered by PHP first. This file only
 * adds visual summaries from the permission-filtered JSON already on the page.
 * It does not fetch data, calculate business metrics or contain category
 * colour assignments.
 */
(function () {
  'use strict';

  var source = document.getElementById('okv-dashboard-data');
  if (!source) { return; }

  var payload;
  try {
    payload = JSON.parse(source.textContent || '{}');
  } catch (error) {
    return;
  }

  var charts = payload.charts || {};
  var svgNamespace = 'http://www.w3.org/2000/svg';

  function money(subunit) {
    var amount = Number.isFinite(Number(subunit)) ? Math.trunc(Number(subunit)) : 0;
    var negative = amount < 0;
    var absolute = Math.abs(amount);
    var naira = Math.floor(absolute / 100);
    var kobo = absolute % 100;
    var label = '\u20a6' + naira.toLocaleString('en-NG');
    if (kobo !== 0) { label += '.' + String(kobo).padStart(2, '0'); }
    return (negative ? '-' : '') + label;
  }

  function shortMoney(subunit) {
    var naira = Number(subunit) / 100;
    var absolute = Math.abs(naira);
    var suffix = '';
    var divisor = 1;
    if (absolute >= 1000000) { suffix = 'm'; divisor = 1000000; }
    else if (absolute >= 1000) { suffix = 'k'; divisor = 1000; }
    var value = absolute / divisor;
    var rounded = value >= 10 || Number.isInteger(value) ? value.toFixed(0) : value.toFixed(1);
    return (naira < 0 ? '-' : '') + '\u20a6' + rounded + suffix;
  }

  function svgElement(name, attributes) {
    var node = document.createElementNS(svgNamespace, name);
    Object.keys(attributes || {}).forEach(function (key) {
      node.setAttribute(key, String(attributes[key]));
    });
    return node;
  }

  function appendSvgText(svg, text, x, y, anchor, className) {
    var node = svgElement('text', { x: x, y: y, 'text-anchor': anchor, 'class': className });
    node.textContent = text;
    svg.appendChild(node);
  }

  function renderSales(rows) {
    var host = document.querySelector('[data-okv-chart="sales"]');
    if (!host || !Array.isArray(rows) || rows.length === 0) { return; }

    var width = 760;
    var height = 270;
    var left = 74;
    var right = 18;
    var top = 22;
    var bottom = 42;
    var values = rows.map(function (row) { return Number(row.amount_subunit) || 0; });
    var minimum = Math.min.apply(null, values.concat([0]));
    var maximum = Math.max.apply(null, values.concat([0]));
    var range = maximum - minimum;
    if (range === 0) { range = 1; }
    var pad = Math.max(1, Math.round(range * 0.08));
    minimum -= minimum < 0 ? pad : 0;
    maximum += maximum > 0 ? pad : 0;
    range = maximum - minimum || 1;

    function xAt(index) {
      return left + ((width - left - right) * index / Math.max(1, rows.length - 1));
    }
    function yAt(value) {
      return top + ((maximum - value) / range) * (height - top - bottom);
    }

    var svg = svgElement('svg', {
      viewBox: '0 0 ' + width + ' ' + height,
      preserveAspectRatio: 'xMidYMid meet',
      focusable: 'false',
      'class': 'okv-sales-svg'
    });

    [minimum, 0, maximum].filter(function (value, index, list) {
      return list.indexOf(value) === index;
    }).forEach(function (value) {
      var y = yAt(value);
      svg.appendChild(svgElement('line', {
        x1: left, y1: y, x2: width - right, y2: y,
        'class': value === 0 ? 'okv-chart-zero' : 'okv-chart-grid'
      }));
      appendSvgText(svg, shortMoney(value), left - 10, y + 4, 'end', 'okv-chart-axis-label');
    });

    var points = rows.map(function (row, index) {
      return xAt(index) + ',' + yAt(values[index]);
    }).join(' ');
    svg.appendChild(svgElement('polyline', { points: points, 'class': 'okv-sales-line' }));

    rows.forEach(function (row, index) {
      var point = svgElement('circle', {
        cx: xAt(index), cy: yAt(values[index]), r: rows.length > 30 ? 2.5 : 3.5,
        'class': 'okv-sales-point'
      });
      var title = svgElement('title');
      title.textContent = row.date + ': ' + money(values[index]);
      point.appendChild(title);
      svg.appendChild(point);
    });

    var dateIndexes = [0, Math.floor((rows.length - 1) / 2), rows.length - 1];
    dateIndexes.filter(function (value, index, list) { return list.indexOf(value) === index; })
      .forEach(function (index) {
        var date = new Date(rows[index].date + 'T00:00:00');
        var label = date.toLocaleDateString('en-NG', { day: 'numeric', month: 'short' });
        var anchor = index === 0 ? 'start' : (index === rows.length - 1 ? 'end' : 'middle');
        appendSvgText(svg, label, xAt(index), height - 12, anchor, 'okv-chart-axis-label');
      });

    host.textContent = '';
    host.appendChild(svg);
  }

  function renderProducts(rows) {
    var host = document.querySelector('[data-okv-chart="products"]');
    if (!host || !Array.isArray(rows) || rows.length === 0) { return; }
    var maximum = Math.max.apply(null, rows.map(function (row) { return Number(row.amount_subunit) || 0; }));
    var list = document.createElement('ol');
    list.className = 'okv-product-bars';

    rows.forEach(function (row, index) {
      var item = document.createElement('li');
      var head = document.createElement('div');
      head.className = 'okv-chart-row-head';
      var label = document.createElement('span');
      label.className = 'okv-chart-row-label';
      label.textContent = (index + 1) + '. ' + String(row.label || 'Unnamed item');
      var value = document.createElement('span');
      value.className = 'okv-chart-row-value';
      value.textContent = money(row.amount_subunit);
      head.appendChild(label);
      head.appendChild(value);

      var track = document.createElement('div');
      track.className = 'okv-chart-track';
      var bar = document.createElement('span');
      bar.className = 'okv-product-bar';
      bar.style.width = maximum > 0 ? (Math.max(0, Number(row.amount_subunit)) * 100 / maximum) + '%' : '0%';
      track.appendChild(bar);
      item.appendChild(head);
      item.appendChild(track);
      list.appendChild(item);
    });

    host.textContent = '';
    host.appendChild(list);
  }

  function renderCategories(rows) {
    var host = document.querySelector('[data-okv-chart="categories"]');
    if (!host || !Array.isArray(rows) || rows.length === 0) { return; }
    var bar = document.createElement('div');
    bar.className = 'okv-category-bar';

    rows.forEach(function (row) {
      var segment = document.createElement('span');
      var token = String(row.colour_token || '').replace(/[^a-z.-]/g, '').replace('.', '-');
      segment.className = 'okv-category-segment okv-chart-token-' + token;
      segment.style.flexBasis = (Number(row.share_basis_points) / 100) + '%';
      segment.title = String(row.label) + ': ' + (Number(row.share_basis_points) / 100).toFixed(2) + '%';
      bar.appendChild(segment);
    });

    var legend = document.createElement('ul');
    legend.className = 'okv-category-legend';
    rows.forEach(function (row) {
      var token = String(row.colour_token || '').replace(/[^a-z.-]/g, '').replace('.', '-');
      var item = document.createElement('li');
      var key = document.createElement('span');
      key.className = 'okv-chart-key okv-chart-token-' + token;
      key.setAttribute('aria-hidden', 'true');
      var label = document.createElement('span');
      label.textContent = String(row.label) + ' ' + (Number(row.share_basis_points) / 100).toFixed(2) + '%';
      item.appendChild(key);
      item.appendChild(label);
      legend.appendChild(item);
    });

    host.textContent = '';
    host.appendChild(bar);
    host.appendChild(legend);
  }

  try { renderSales(charts.sales_over_time); } catch (error) { /* Exact table remains available. */ }
  try { renderProducts(charts.top_products); } catch (error) { /* Exact table remains available. */ }
  try { renderCategories(charts.order_share); } catch (error) { /* Exact table remains available. */ }
}());
