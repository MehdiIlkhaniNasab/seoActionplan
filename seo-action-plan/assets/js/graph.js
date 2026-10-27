/* Sokan — keyword & page graph: a small force layout on SVG (no libraries). */
(function () {
	'use strict';
	var svg = document.querySelector('[data-sap-graph]');
	var dataEl = document.querySelector('[data-sap-graph-data]');
	if (!svg || !dataEl) { return; }
	var data = JSON.parse(dataEl.textContent);
	var NS = 'http://www.w3.org/2000/svg';
	var palette = ['#2563EB', '#0EA5E9', '#7C3AED', '#DB2777', '#EA580C', '#16A34A', '#0D9488', '#CA8A04', '#4F46E5', '#BE123C', '#0891B2', '#65A30D'];
	var stateColor = { top3: '#059669', top10: '#2563EB', near: '#D97706', far: '#DC2626', none: '#94A3B8', planned: '#7C3AED' };
	var topicColor = {};
	data.topics.forEach(function (t, i) { topicColor[t] = t === 'سایر' ? '#64748B' : palette[i % palette.length]; });

	var W = svg.clientWidth || 900, H = svg.clientHeight || 620;
	var maxW = 1;
	data.nodes.forEach(function (n) { maxW = Math.max(maxW, n.w || 0); });
	var byId = {};
	var nodes = data.nodes.map(function (n, i) {
		var base = n.type === 'page' ? 9 : (n.type === 'idea' ? 6 : 4);
		var span = n.type === 'page' ? 20 : 9;
		var r = base + span * Math.sqrt((n.w || 0) / maxW);
		var o = Object.assign({}, n, { r: r, x: W / 2 + Math.cos(i) * 200 * Math.random(), y: H / 2 + Math.sin(i) * 200 * Math.random(), vx: 0, vy: 0, nb: [] });
		byId[n.id] = o;
		return o;
	});
	var edges = data.edges.filter(function (e) { return byId[e.s] && byId[e.t]; }).map(function (e) {
		var a = byId[e.s], b = byId[e.t];
		if (a.nb.indexOf(b.id) === -1) { a.nb.push(b.id); b.nb.push(a.id); }
		return { a: a, b: b, type: e.type };
	});

	// Topic centres on a circle; the biggest topic in the middle.
	var centers = {};
	data.topics.forEach(function (t, i) {
		if (i === 0) { centers[t] = { x: W / 2, y: H / 2 }; return; }
		var ang = (i / Math.max(1, data.topics.length - 1)) * Math.PI * 2;
		var rad = Math.min(W, H) * 0.3;
		centers[t] = { x: W / 2 + Math.cos(ang) * rad, y: H / 2 + Math.sin(ang) * rad };
	});

	/* ---- SVG scene ---- */
	var view = { x: 0, y: 0, k: 1 };
	var root = document.createElementNS(NS, 'g');
	var gEdges = document.createElementNS(NS, 'g');
	var gNodes = document.createElementNS(NS, 'g');
	root.appendChild(gEdges); root.appendChild(gNodes); svg.appendChild(root);
	var defs = document.createElementNS(NS, 'defs');
	defs.innerHTML = '<filter id="sapGlow" x="-50%" y="-50%" width="200%" height="200%"><feGaussianBlur stdDeviation="3" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter>';
	svg.appendChild(defs);

	edges.forEach(function (e) {
		var l = document.createElementNS(NS, 'line');
		l.setAttribute('class', 'ge ge-' + e.type);
		e.el = l; gEdges.appendChild(l);
	});
	nodes.forEach(function (n) {
		var g = document.createElementNS(NS, 'g');
		g.setAttribute('class', 'gn gn-' + n.type + (n.orphan ? ' is-orphan' : '') + (n.kind ? ' k-' + n.kind : ''));
		var c = document.createElementNS(NS, n.type === 'idea' ? 'rect' : 'circle');
		if (n.type === 'idea') {
			c.setAttribute('width', n.r * 2); c.setAttribute('height', n.r * 2); c.setAttribute('x', -n.r); c.setAttribute('y', -n.r); c.setAttribute('rx', 3);
			c.setAttribute('transform', 'rotate(45)');
		} else { c.setAttribute('r', n.r); }
		var fill = n.type === 'page' ? (topicColor[n.topic] || '#2563EB') : stateColor[n.state] || '#94A3B8';
		c.setAttribute('fill', fill);
		if (n.type === 'page') { c.setAttribute('fill-opacity', '0.9'); }
		g.appendChild(c);
		var t = document.createElementNS(NS, 'text');
		t.textContent = n.label.length > 34 ? n.label.slice(0, 32) + '…' : n.label;
		t.setAttribute('y', n.r + 12);
		t.setAttribute('class', 'gl' + (n.type === 'page' || n.r > 8 ? ' is-on' : ''));
		g.appendChild(t);
		n.el = g; gNodes.appendChild(g);
		g.addEventListener('pointerenter', function () { focus(n); showTip(n); });
		g.addEventListener('pointerleave', function () { if (!pinned) { focus(null); } hideTip(); });
		g.addEventListener('pointerdown', function (ev) { startDrag(ev, n); });
		g.addEventListener('click', function (ev) { ev.stopPropagation(); if (!dragMoved) { pin(n); } });
	});

	/* ---- Force simulation ---- */
	var alpha = 1, floating = true, layers = { kw: true, link: true, suggested: true, idea: true }, hiddenTopics = {};
	var springLen = { primary: 40, secondary: 52, query: 58, link: 120, suggested: 130, planned: 90 };
	function visible(n) {
		if (hiddenTopics[n.topic]) { return false; }
		if (n.type === 'kw' && !layers.kw) { return false; }
		if (n.type === 'idea' && !layers.idea) { return false; }
		return true;
	}
	function edgeVisible(e) {
		if (!visible(e.a) || !visible(e.b)) { return false; }
		if ((e.type === 'link' && !layers.link) || (e.type === 'suggested' && !layers.suggested)) { return false; }
		return true;
	}
	function tick() {
		var live = nodes.filter(visible);
		var i, j, a, b, dx, dy, d2, d, f;
		// Repulsion.
		for (i = 0; i < live.length; i++) {
			a = live[i];
			for (j = i + 1; j < live.length; j++) {
				b = live[j];
				dx = a.x - b.x; dy = a.y - b.y; d2 = dx * dx + dy * dy + 0.01;
				var min = (a.r + b.r + 6);
				if (d2 > 90000) { continue; } // Far apart: no need to push.
				f = (a.type === 'page' && b.type === 'page' ? 1400 : 380) / d2;
				if (d2 < min * min) { f += 0.6 * (min - Math.sqrt(d2)) / Math.sqrt(d2); }
				a.vx += dx * f * alpha; a.vy += dy * f * alpha;
				b.vx -= dx * f * alpha; b.vy -= dy * f * alpha;
			}
		}
		// Springs.
		edges.forEach(function (e) {
			if (!edgeVisible(e)) { return; }
			dx = e.b.x - e.a.x; dy = e.b.y - e.a.y; d = Math.sqrt(dx * dx + dy * dy) + 0.01;
			var L = springLen[e.type] || 60, k = e.type === 'link' || e.type === 'suggested' ? 0.004 : 0.03;
			f = (d - L) * k * alpha;
			e.a.vx += dx / d * f; e.a.vy += dy / d * f;
			e.b.vx -= dx / d * f; e.b.vy -= dy / d * f;
		});
		// Topic gravity + centre.
		live.forEach(function (n) {
			var c = centers[n.topic] || { x: W / 2, y: H / 2 };
			var pull = n.nb.length ? 0.02 : 0.05; // Unconnected nodes stay near their topic.
			n.vx += (c.x - n.x) * pull * alpha + (W / 2 - n.x) * 0.004 * alpha;
			n.vy += (c.y - n.y) * pull * alpha + (H / 2 - n.y) * 0.004 * alpha;
			if (floating) { n.vx += (Math.random() - 0.5) * 0.18; n.vy += (Math.random() - 0.5) * 0.18; }
			if (n === dragging) { n.vx = 0; n.vy = 0; return; }
			n.vx *= 0.82; n.vy *= 0.82;
			n.x += Math.max(-12, Math.min(12, n.vx)); n.y += Math.max(-12, Math.min(12, n.vy));
			n.x = Math.max(-W * 0.3, Math.min(W * 1.3, n.x)); n.y = Math.max(-H * 0.3, Math.min(H * 1.3, n.y));
		});
		alpha = Math.max(floating ? 0.06 : 0.0, alpha * 0.985);
	}
	function draw() {
		nodes.forEach(function (n) {
			var v = visible(n);
			n.el.style.display = v ? '' : 'none';
			if (v) { n.el.setAttribute('transform', 'translate(' + n.x.toFixed(1) + ',' + n.y.toFixed(1) + ')'); }
		});
		edges.forEach(function (e) {
			var v = edgeVisible(e);
			e.el.style.display = v ? '' : 'none';
			if (v) { e.el.setAttribute('x1', e.a.x.toFixed(1)); e.el.setAttribute('y1', e.a.y.toFixed(1)); e.el.setAttribute('x2', e.b.x.toFixed(1)); e.el.setAttribute('y2', e.b.y.toFixed(1)); }
		});
	}
	function loop() {
		if (alpha > 0.001 || floating) { tick(); draw(); }
		requestAnimationFrame(loop);
	}
	for (var w = 0; w < 120; w++) { tick(); }
	alpha = 0.6;
	draw();
	requestAnimationFrame(loop);

	/* ---- Zoom / pan / drag ---- */
	function applyView() { root.setAttribute('transform', 'translate(' + view.x + ',' + view.y + ') scale(' + view.k + ')'); svg.classList.toggle('is-zoomed', view.k > 1.4); }
	function toWorld(ev) { var r = svg.getBoundingClientRect(); return { x: (ev.clientX - r.left - view.x) / view.k, y: (ev.clientY - r.top - view.y) / view.k }; }
	svg.addEventListener('wheel', function (ev) {
		ev.preventDefault();
		var r = svg.getBoundingClientRect(), mx = ev.clientX - r.left, my = ev.clientY - r.top;
		var k = Math.max(0.3, Math.min(4, view.k * (ev.deltaY < 0 ? 1.12 : 0.89)));
		view.x = mx - (mx - view.x) * (k / view.k); view.y = my - (my - view.y) * (k / view.k); view.k = k;
		applyView();
	}, { passive: false });
	var dragging = null, panning = null, dragMoved = false;
	function startDrag(ev, n) { ev.stopPropagation(); dragging = n; dragMoved = false; }
	svg.addEventListener('pointerdown', function (ev) { dragMoved = false; if (!dragging) { panning = { x: ev.clientX - view.x, y: ev.clientY - view.y }; } });
	window.addEventListener('pointermove', function (ev) {
		if (dragging) { var p = toWorld(ev); dragging.x = p.x; dragging.y = p.y; dragMoved = true; alpha = Math.max(alpha, 0.3); draw(); }
		else if (panning) { var nx = ev.clientX - panning.x, ny = ev.clientY - panning.y; if (Math.abs(nx - view.x) + Math.abs(ny - view.y) > 1) { dragMoved = true; } view.x = nx; view.y = ny; applyView(); }
	});
	window.addEventListener('pointerup', function () { dragging = null; panning = null; });
	svg.addEventListener('click', function () { if (!dragMoved) { pin(null); } });

	function fit() {
		var live = nodes.filter(visible);
		if (!live.length) { return; }
		var x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity;
		live.forEach(function (n) { x0 = Math.min(x0, n.x - n.r); y0 = Math.min(y0, n.y - n.r); x1 = Math.max(x1, n.x + n.r); y1 = Math.max(y1, n.y + n.r + 14); });
		var k = Math.min(2, 0.92 * Math.min(W / (x1 - x0), H / (y1 - y0)));
		view.k = k; view.x = W / 2 - k * (x0 + x1) / 2; view.y = H / 2 - k * (y0 + y1) / 2;
		applyView();
	}
	setTimeout(fit, 1500);

	/* ---- Highlight, tooltip, info panel ---- */
	var pinned = null;
	var tip = document.querySelector('[data-sap-graph-tip]');
	var info = document.querySelector('[data-sap-graph-info]');
	function focus(n) {
		var on = {};
		if (n) { on[n.id] = true; n.nb.forEach(function (id) { on[id] = true; }); }
		svg.classList.toggle('is-focus', !!n);
		nodes.forEach(function (m) { m.el.classList.toggle('is-hl', !!n && !!on[m.id]); });
		edges.forEach(function (e) { e.el.classList.toggle('is-hl', !!n && (e.a === n || e.b === n)); });
	}
	function fmt(v) { return (v || 0).toLocaleString('fa-IR'); }
	function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
	function describe(n) {
		var i = n.info || {}, s = '<b>' + esc(n.label) + '</b><br>';
		if (n.type === 'page') {
			s += esc(i.pt || 'صفحه') + (i.kw ? ' · کلمه اصلی: ' + esc(i.kw) : '') + '<br>' + fmt(i.impr) + ' نمایش' + (i.pos ? ' · جایگاه کلمه اصلی ' + fmt(i.pos) : '') + (n.orphan ? '<br><span class="bad">یتیم: هیچ صفحه مهمی به آن لینک نمی‌دهد</span>' : '');
		} else if (n.type === 'idea') {
			s += 'مقاله برنامه‌ریزی‌شده' + (i.period ? ' · ' + esc(i.period) : '') + (i.kp ? ' · KP ' + fmt(i.kp) : '') + (i.title ? '<br>' + esc(i.title) : '');
		} else {
			s += ({ primary: 'کلمه اصلی صفحه', secondary: 'کلمه فرعی', query: 'کوئری سرچ کنسول' })[n.kind] + (i.impr ? ' · ' + fmt(i.impr) + ' نمایش' : '') + (i.pos ? ' · جایگاه ' + fmt(i.pos) : ' · بدون رتبه');
		}
		return s + '<br><small>موضوع: ' + esc(n.topic) + ' · ' + fmt(n.nb.length) + ' ارتباط</small>';
	}
	function showTip(n) {
		tip.innerHTML = describe(n); tip.hidden = false;
		var r = svg.getBoundingClientRect();
		var x = n.x * view.k + view.x, y = n.y * view.k + view.y;
		tip.style.left = Math.min(r.width - 260, Math.max(0, x + 14)) + 'px'; tip.style.top = Math.max(0, y - 10) + 'px';
	}
	function hideTip() { tip.hidden = true; }
	function pin(n) {
		pinned = n; focus(n);
		if (!n) { info.hidden = true; return; }
		var links = n.nb.map(function (id) { return byId[id]; }).filter(Boolean).sort(function (a, b) { return (b.w || 0) - (a.w || 0); }).slice(0, 14);
		var html = '<button type="button" class="sap-icon-btn" data-close>✕</button>' + describe(n);
		if (n.url) { html += '<p><a href="' + esc(n.url) + '" target="_blank" rel="noopener">مشاهده صفحه ↗</a>' + (n.type === 'page' ? ' · <a href="' + esc(data.content) + (data.content.indexOf('?') === -1 ? '?' : '&') + 'url=' + encodeURIComponent(n.url) + '">تحلیل محتوا</a>' : '') + '</p>'; }
		if (links.length) {
			html += '<p class="sap-label">ارتباط‌ها</p><ul>' + links.map(function (m) { return '<li data-id="' + esc(m.id) + '"><i style="background:' + (m.type === 'page' ? topicColor[m.topic] : stateColor[m.state]) + '"></i>' + esc(m.label) + '</li>'; }).join('') + '</ul>';
		}
		info.innerHTML = html; info.hidden = false;
	}
	info.addEventListener('click', function (ev) {
		if (ev.target.closest('[data-close]')) { pin(null); return; }
		var li = ev.target.closest('li[data-id]');
		if (li && byId[li.getAttribute('data-id')]) { pin(byId[li.getAttribute('data-id')]); }
	});

	/* ---- Controls ---- */
	var topicsBox = document.querySelector('[data-sap-graph-topics]');
	data.topics.forEach(function (t) {
		var count = nodes.filter(function (n) { return n.topic === t && n.type === 'page'; }).length;
		if (!count) { return; }
		var b = document.createElement('button');
		b.type = 'button'; b.className = 'sap-graph-topic';
		b.innerHTML = '<i style="background:' + topicColor[t] + '"></i>' + esc(t) + ' <small>' + fmt(count) + '</small>';
		b.addEventListener('click', function () {
			hiddenTopics[t] = !hiddenTopics[t]; b.classList.toggle('is-off', hiddenTopics[t]); alpha = Math.max(alpha, 0.4);
		});
		b.addEventListener('dblclick', function () {
			data.topics.forEach(function (x) { hiddenTopics[x] = x !== t; });
			topicsBox.querySelectorAll('.sap-graph-topic').forEach(function (x) { x.classList.add('is-off'); });
			b.classList.remove('is-off'); alpha = 0.6; setTimeout(fit, 900);
		});
		topicsBox.appendChild(b);
	});
	document.querySelectorAll('[data-layer]').forEach(function (cb) {
		cb.addEventListener('change', function () { layers[cb.getAttribute('data-layer')] = cb.checked; alpha = Math.max(alpha, 0.4); draw(); });
	});
	var fl = document.querySelector('[data-sap-graph-float]');
	if (fl) { fl.addEventListener('change', function () { floating = fl.checked; if (floating) { alpha = Math.max(alpha, 0.1); } }); }
	var fitBtn = document.querySelector('[data-sap-graph-fit]');
	if (fitBtn) { fitBtn.addEventListener('click', fit); }
	var search = document.querySelector('[data-sap-graph-search]');
	if (search) {
		search.addEventListener('input', function () {
			var q = search.value.trim();
			nodes.forEach(function (n) { n.el.classList.toggle('is-match', q.length > 1 && n.label.indexOf(q) !== -1); });
			svg.classList.toggle('is-searching', q.length > 1);
		});
		search.addEventListener('keydown', function (ev) {
			if (ev.key !== 'Enter') { return; }
			var q = search.value.trim(), hit = nodes.find(function (n) { return visible(n) && n.label.indexOf(q) !== -1; });
			if (hit) { view.k = 1.8; view.x = W / 2 - hit.x * view.k; view.y = H / 2 - hit.y * view.k; applyView(); pin(hit); }
		});
	}
	window.addEventListener('resize', function () { W = svg.clientWidth || W; H = svg.clientHeight || H; });
})();
