/* SEO Action Plan — admin interactions (no build step). */
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
				btn.textContent = (res.sites || []).length + ' پراپرتی — روی فیلد کلیک کنید';
			}).catch(function (e) { window.alert(e.message); }).then(function () { btn.disabled = false; });
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
				setTimeout(function () { el.classList.remove('is-copied'); }, 900);
			});
		});
	});

	/* ---- Open the task named in the URL hash ---- */
	if (location.hash && location.hash.indexOf('#task-') === 0) {
		var t = document.querySelector(location.hash);
		if (t && t.tagName === 'DETAILS') { t.open = true; t.scrollIntoView({ block: 'center' }); }
	}
})();
