/* SEO Action Plan — app interactions (no build step). */
(function () {
	'use strict';
	if (!window.SAP) { return; }

	function api(path, opts) {
		opts = opts || {};
		opts.headers = Object.assign({ 'X-WP-Nonce': SAP.nonce }, opts.headers || {});
		opts.credentials = 'same-origin';
		return fetch(SAP.rest + path, opts).then(function (r) {
			return r.json().then(function (j) {
				if (!r.ok) { throw new Error((j && j.message) || ('HTTP ' + r.status)); }
				return j;
			});
		});
	}

	function post(path, data) {
		return api(path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data || {}) });
	}

	/* ---- Toast ---- */
	var toastEl;
	function toast(msg, bad) {
		if (!toastEl) {
			toastEl = document.createElement('div');
			toastEl.className = 'sap-toast';
			toastEl.setAttribute('role', 'status');
			document.body.appendChild(toastEl);
		}
		toastEl.textContent = msg;
		toastEl.className = 'sap-toast is-show' + (bad ? ' is-bad' : '');
		clearTimeout(toastEl._t);
		toastEl._t = setTimeout(function () { toastEl.className = 'sap-toast'; }, 2600);
	}

	function fadeOut(el) {
		el.classList.add('is-leaving');
		setTimeout(function () { el.remove(); }, 260);
	}

	/* ---- Mobile navigation ---- */
	document.querySelectorAll('[data-sap-open-nav]').forEach(function (b) {
		b.addEventListener('click', function () { document.body.classList.add('nav-open'); });
	});
	document.querySelectorAll('[data-sap-close-nav]').forEach(function (b) {
		b.addEventListener('click', function () { document.body.classList.remove('nav-open'); });
	});

	/* ---- Sync runner: one REST request per step, in order ---- */
	document.querySelectorAll('[data-sap-sync]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var project = btn.getAttribute('data-sap-sync');
			var force = btn.getAttribute('data-force') === '1';
			var panel = document.querySelector('[data-sap-sync-panel]');
			if (!panel) { return; }
			if (force && !window.confirm('همگام‌سازی کامل، داده‌های سئو سیگنال را با وجود کش هم دوباره می‌گیرد و از سهمیه روزانه مصرف می‌کند. ادامه می‌دهید؟')) { return; }
			panel.hidden = false;
			panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
			document.querySelectorAll('[data-sap-sync]').forEach(function (b) { b.disabled = true; });
			var items = Array.prototype.slice.call(panel.querySelectorAll('[data-step]'));
			items.forEach(function (li) { li.className = ''; li.querySelector('[data-msg]').textContent = ''; });
			var done = panel.querySelector('[data-sap-sync-done]');
			done.hidden = true;
			var i = 0;
			function next() {
				if (i >= items.length) {
					done.hidden = false;
					document.querySelectorAll('[data-sap-sync]').forEach(function (b) { b.disabled = false; });
					toast('همگام‌سازی و تحلیل تمام شد.');
					return;
				}
				var li = items[i++];
				var msg = li.querySelector('[data-msg]');
				li.className = 'is-run';
				msg.textContent = 'در حال اجرا…';
				var started = Date.now();
				api('sync/' + project + '/' + li.getAttribute('data-step') + (force ? '?force=1' : ''), { method: 'POST' })
					.then(function (res) {
						li.className = res.ok ? (res.skipped ? 'is-skip' : 'is-ok') : 'is-err';
						msg.textContent = (res.msg || '') + ' (' + Math.round((Date.now() - started) / 100) / 10 + ' ثانیه)';
					})
					.catch(function (e) {
						li.className = 'is-err';
						msg.textContent = e.message;
					})
					.then(next);
			}
			next();
		});
	});

	/* ---- Task status buttons: change in place ---- */
	function refreshCounts() {
		document.querySelectorAll('.sap-col').forEach(function (col) {
			var n = col.querySelectorAll('.sap-task').length;
			var small = col.querySelector('h3 small');
			if (small) { small.textContent = n.toLocaleString('fa-IR'); }
			var empty = col.querySelector('.sap-col-empty');
			if (empty) { empty.hidden = n > 0; }
		});
	}
	document.addEventListener('click', function (ev) {
		var btn = ev.target.closest('.sap-quick button[name="status"]');
		if (!btn) { return; }
		ev.preventDefault();
		var form = btn.closest('form');
		var id = form.querySelector('input[name="task_id"]').value;
		var status = btn.value;
		btn.disabled = true;
		post('task/' + id + '/status', { status: status }).then(function (res) {
			toast('وضعیت: ' + res.label);
			if (form.hasAttribute('data-sap-remove-on-done')) {
				fadeOut(form.closest('[data-sap-row]'));
				return;
			}
			var card = form.closest('.sap-task');
			card.className = card.className.replace(/\bis-[a-z]+\b/g, '').trim() + ' is-' + status;
			form.querySelectorAll('button[name="status"]').forEach(function (b) { b.hidden = b.value === status; });
			var select = card.querySelector('select[name="status"]');
			if (select) { select.value = status; }
			var col = document.querySelector('.sap-col.is-' + status);
			if (col) {
				card.open = false;
				col.insertBefore(card, col.querySelector('.sap-task'));
				refreshCounts();
			} else if (document.querySelector('.sap-board') || ['done', 'dropped', 'someday'].indexOf(status) !== -1) {
				fadeOut(card);
				setTimeout(refreshCounts, 300);
			}
		}).catch(function (e) { toast(e.message, true); }).then(function () { btn.disabled = false; });
	});

	/* ---- Finding row buttons: act in place ---- */
	document.addEventListener('click', function (ev) {
		var btn = ev.target.closest('button[name="quick"]');
		if (!btn) { return; }
		ev.preventDefault();
		var parts = btn.value.split(':');
		var form = btn.closest('form');
		var period = form.querySelector('select[name="period"]');
		btn.disabled = true;
		post('finding/' + parts[1], { do: parts[0], period: period ? period.value : '' }).then(function (res) {
			toast(res.msg);
			var row = btn.closest('.sap-finding');
			fadeOut(row);
			var counter = document.querySelector('[data-sap-count]');
			if (counter) {
				var n = Math.max(0, parseInt(counter.getAttribute('data-sap-count'), 10) - 1);
				counter.setAttribute('data-sap-count', n);
				counter.textContent = n.toLocaleString('fa-IR');
			}
		}).catch(function (e) { toast(e.message, true); btn.disabled = false; });
	});

	/* ---- Search Console properties ---- */
	document.querySelectorAll('[data-sap-load-gsc]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			btn.disabled = true;
			api('gsc/sites').then(function (res) {
				var dl = document.getElementById('sap-gsc-sites');
				dl.innerHTML = '';
				(res.sites || []).forEach(function (s) {
					var o = document.createElement('option');
					o.value = s.url;
					dl.appendChild(o);
				});
				btn.textContent = (res.sites || []).length.toLocaleString('fa-IR') + ' پراپرتی — روی فیلد کلیک کنید';
			}).catch(function (e) { toast(e.message, true); }).then(function () { btn.disabled = false; });
		});
	});

	/* ---- SEO Signal rank tracker projects ---- */
	document.querySelectorAll('[data-sap-load-ss]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var out = btn.closest('label').querySelector('[data-sap-ss-list]');
			var input = btn.closest('label').querySelector('input');
			btn.disabled = true;
			api('seosignal/projects').then(function (res) {
				out.innerHTML = '';
				(res.projects || []).forEach(function (p) {
					var a = document.createElement('a');
					a.href = '#';
					a.textContent = p.id + ' — ' + p.name + ' (' + p.domain + '، ' + p.keywords + ' کلمه)';
					a.style.display = 'block';
					a.addEventListener('click', function (ev) { ev.preventDefault(); input.value = p.id; });
					out.appendChild(a);
				});
				if (!(res.projects || []).length) { out.textContent = 'پروژه‌ای در ردیاب رتبه نیست.'; }
			}).catch(function (e) { out.textContent = e.message; }).then(function () { btn.disabled = false; });
		});
	});

	/* ---- Select all findings ---- */
	document.querySelectorAll('[data-sap-check-all]').forEach(function (box) {
		box.addEventListener('change', function () {
			box.closest('form').querySelectorAll('input[name="ids[]"]').forEach(function (c) { c.checked = box.checked; });
		});
	});

	/* ---- Copy to clipboard ---- */
	document.querySelectorAll('[data-sap-copy]').forEach(function (el) {
		el.title = 'کلیک برای کپی';
		el.addEventListener('click', function () {
			if (!navigator.clipboard) { return; }
			navigator.clipboard.writeText(el.textContent.trim()).then(function () {
				el.classList.add('is-copied');
				toast('کپی شد');
				setTimeout(function () { el.classList.remove('is-copied'); }, 900);
			});
		});
	});

	/* ---- Flash messages fade; open the task named in the URL ---- */
	var flash = document.querySelector('.sap-flash.is-ok');
	if (flash) { setTimeout(function () { flash.classList.add('is-leaving'); }, 6000); }
	if (location.hash && location.hash.indexOf('#task-') === 0) {
		var t = document.querySelector(location.hash);
		if (t && t.tagName === 'DETAILS') { t.open = true; t.scrollIntoView({ block: 'center' }); }
	}

})();
