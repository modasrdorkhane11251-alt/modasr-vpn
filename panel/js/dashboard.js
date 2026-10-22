(function () {
    var svgNs = 'http://www.w3.org/2000/svg';
    var chartIndex = 0;

    var make = function (name, attrs, parent) {
        var node = document.createElementNS(svgNs, name);
        Object.keys(attrs).forEach(function (key) {
            node.setAttribute(key, attrs[key]);
        });
        parent.appendChild(node);
        return node;
    };

    var compact = function (value) {
        var abs = Math.abs(value);
        if (abs >= 1e9) return +(value / 1e9).toFixed(1) + 'B';
        if (abs >= 1e6) return +(value / 1e6).toFixed(1) + 'M';
        if (abs >= 1e3) return +(value / 1e3).toFixed(1) + 'K';
        return String(value);
    };

    var niceMax = function (max) {
        if (max <= 0) return 4;
        var magnitude = Math.pow(10, Math.floor(Math.log10(max / 4)));
        var steps = [1, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10];
        for (var i = 0; i < steps.length; i++) {
            if (magnitude * steps[i] * 4 >= max) return magnitude * steps[i] * 4;
        }
        return magnitude * 40;
    };

    var draw = function (box) {
        var points = JSON.parse(box.dataset.series || '[]');
        var unit = box.dataset.unit || '';
        var gradientId = box.dataset.gradientId || (box.dataset.gradientId = 'dayChartFill' + chartIndex++);
        box.textContent = '';
        if (!points.length) return;

        var width = box.clientWidth - 12;
        var height = box.clientHeight - 12;
        var pad = { left: 40, right: 10, top: 8, bottom: 22 };
        var plotWidth = width - pad.left - pad.right;
        var plotHeight = height - pad.top - pad.bottom;
        var top = niceMax(Math.max.apply(null, points.map(function (p) { return p.v; })));
        var x = function (i) { return pad.left + (points.length === 1 ? plotWidth / 2 : i * plotWidth / (points.length - 1)); };
        var y = function (v) { return pad.top + plotHeight - v / top * plotHeight; };

        var svg = make('svg', { viewBox: '0 0 ' + width + ' ' + height, 'aria-hidden': 'true' }, box);
        var gradient = make('linearGradient', { id: gradientId, x1: '0', y1: '0', x2: '0', y2: '1' }, make('defs', {}, svg));
        make('stop', { offset: '0%', style: 'stop-color:var(--ac);stop-opacity:.32' }, gradient);
        make('stop', { offset: '100%', style: 'stop-color:var(--ac);stop-opacity:0' }, gradient);

        for (var t = 0; t <= 4; t++) {
            var tickValue = top / 4 * t;
            make('line', { class: 'grid-line', x1: pad.left, x2: width - pad.right, y1: y(tickValue), y2: y(tickValue) }, svg);
            make('text', { class: 'axis-text', x: pad.left - 6, y: y(tickValue) + 3, 'text-anchor': 'end' }, svg).textContent = compact(tickValue);
        }
        [0, 1, 2, 3, 4].map(function (part) { return Math.round(part * (points.length - 1) / 4); }).filter(function (i, pos, list) { return list.indexOf(i) === pos; }).forEach(function (i) {
            var anchor = i === 0 ? 'start' : (i === points.length - 1 ? 'end' : 'middle');
            make('text', { class: 'axis-text', x: x(i), y: height - 6, 'text-anchor': anchor }, svg).textContent = points[i].d;
        });

        var line = points.map(function (p, i) { return (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(p.v).toFixed(1); }).join(' ');
        make('path', { d: line + ' L' + x(points.length - 1).toFixed(1) + ' ' + y(0) + ' L' + x(0) + ' ' + y(0) + ' Z', fill: 'url(#' + gradientId + ')' }, svg);
        make('path', { class: 'series-line', d: line }, svg);
        var last = points.length - 1;
        make('circle', { class: 'series-dot', cx: x(last), cy: y(points[last].v), r: 4 }, svg);

        var cross = make('line', { class: 'cross-line', y1: pad.top, y2: pad.top + plotHeight, style: 'display:none' }, svg);
        var marker = make('circle', { class: 'series-dot', r: 5, style: 'display:none' }, svg);
        var hit = make('rect', { x: pad.left, y: 0, width: plotWidth, height: height, fill: 'transparent' }, svg);

        var tip = document.createElement('div');
        tip.className = 'chart-tip';
        var tipValue = document.createElement('b');
        var tipDate = document.createElement('span');
        tip.appendChild(tipValue);
        tip.appendChild(tipDate);
        box.appendChild(tip);

        var show = function (event) {
            var rect = svg.getBoundingClientRect();
            var pointerX = (event.clientX - rect.left) * width / rect.width;
            var i = Math.max(0, Math.min(last, Math.round((pointerX - pad.left) / plotWidth * last)));
            cross.setAttribute('x1', x(i));
            cross.setAttribute('x2', x(i));
            cross.style.display = '';
            marker.setAttribute('cx', x(i));
            marker.setAttribute('cy', y(points[i].v));
            marker.style.display = '';
            tipValue.textContent = Number(points[i].v).toLocaleString('en-US') + ' ' + unit;
            tipDate.textContent = points[i].d;
            tip.style.display = 'block';
            var crossLeft = x(i) * rect.width / width + 6;
            var left = i > last / 2 ? crossLeft - tip.offsetWidth - 12 : crossLeft + 12;
            tip.style.left = Math.max(4, Math.min(left, box.clientWidth - tip.offsetWidth - 4)) + 'px';
        };
        var hide = function () {
            cross.style.display = 'none';
            marker.style.display = 'none';
            tip.style.display = 'none';
        };
        hit.addEventListener('pointermove', show);
        hit.addEventListener('pointerdown', show);
        hit.addEventListener('pointerleave', hide);
    };

    var boxes = document.querySelectorAll('.day-chart');
    var totals = document.querySelectorAll('.chart-total');
    var grid = document.querySelector('.chart-grid');
    boxes.forEach(draw);

    document.querySelectorAll('.range-tabs a').forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            if (link.classList.contains('active') || grid.classList.contains('loading')) return;
            var url = new URL(link.href);
            url.hash = '';
            url.searchParams.set('ajax', '1');
            grid.classList.add('loading');
            fetch(url, { credentials: 'same-origin' })
                .then(function (response) { return response.json(); })
                .then(function (charts) {
                    charts.forEach(function (chart, i) {
                        boxes[i].dataset.series = JSON.stringify(chart.points);
                        totals[i].textContent = chart.total;
                        draw(boxes[i]);
                    });
                    document.querySelectorAll('.range-tabs a.active').forEach(function (active) {
                        active.classList.remove('active');
                    });
                    link.classList.add('active');
                    url.searchParams.delete('ajax');
                    history.replaceState(null, '', url.pathname + url.search);
                })
                .catch(function () {
                    window.location.href = link.href;
                })
                .finally(function () {
                    grid.classList.remove('loading');
                });
        });
    });
    var resizeTimer;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () { boxes.forEach(draw); }, 150);
    });
})();
