/* Sokan (سُکان) — app interactions (no build step). */
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
						if (res.more) {
							// Resumable step: same step again until it reports done.
							msg.textContent = res.msg || '…';
							i--;
							return;
						}
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
			var card = form.closest('.sap-task') || (document.querySelector('.sap-task.is-active'));
			card.className = card.className.replace(/\bis-[a-z]+\b/g, '').trim() + ' is-' + status;
			form.querySelectorAll('button[name="status"]').forEach(function (b) { b.hidden = b.value === status; });
			var select = card.querySelector('select[name="status"]');
			if (select) { select.value = status; }
			var col = document.querySelector('.sap-col.is-' + status);
			if (col) {
				card.open = false;
				col.insertBefore(card, col.querySelector('.sap-task'));
				refreshCounts();
				document.dispatchEvent(new Event('sap:task-moved'));
			} else if (document.querySelector('.sap-board') || ['done', 'dropped', 'someday'].indexOf(status) !== -1) {
				fadeOut(card);
				setTimeout(function () { refreshCounts(); document.dispatchEvent(new Event('sap:task-moved')); }, 300);
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

	/* ---- Tag input: textarea[data-sap-tags] (one item per line) ---- */
	document.querySelectorAll('textarea[data-sap-tags]').forEach(function (ta) {
		var box = document.createElement('div');
		box.className = 'sap-tags';
		var input = document.createElement('input');
		input.type = 'text';
		input.placeholder = (ta.getAttribute('placeholder') || '').split('\n')[0] + ' — Enter';
		if (ta.getAttribute('dir')) { input.setAttribute('dir', ta.getAttribute('dir')); }
		var items = ta.value.split(/\r?\n|،|,/).map(function (v) { return v.trim(); }).filter(Boolean);
		function sync() { ta.value = items.join('\n'); }
		function render() {
			box.querySelectorAll('.sap-tag').forEach(function (t) { t.remove(); });
			items.forEach(function (v, i) {
				var tag = document.createElement('span');
				tag.className = 'sap-tag';
				tag.textContent = v;
				var x = document.createElement('button');
				x.type = 'button';
				x.textContent = '×';
				x.setAttribute('aria-label', 'حذف');
				x.addEventListener('click', function () { items.splice(i, 1); sync(); render(); });
				tag.appendChild(x);
				box.insertBefore(tag, input);
			});
		}
		function add() {
			input.value.split(/،|,/).map(function (v) { return v.trim(); }).filter(Boolean).forEach(function (v) {
				if (items.indexOf(v) === -1) { items.push(v); }
			});
			input.value = '';
			sync(); render();
		}
		input.addEventListener('keydown', function (ev) {
			if (ev.key === 'Enter' || ev.key === ',' || ev.key === '،') { ev.preventDefault(); add(); }
			else if (ev.key === 'Backspace' && !input.value && items.length) { items.pop(); sync(); render(); }
		});
		input.addEventListener('blur', add);
		box.addEventListener('click', function (ev) { if (ev.target === box) { input.focus(); } });
		box.appendChild(input);
		ta.hidden = true;
		ta.parentNode.insertBefore(box, ta.nextSibling);
		render();
	});

	/* ---- Search Console select: list properties, auto-pick the one matching the site ---- */
	document.querySelectorAll('[data-sap-gsc-select]').forEach(function (sel) {
		var form = sel.closest('form');
		var site = form && form.querySelector('[data-sap-site]');
		var current = sel.getAttribute('data-current');
		var host = function (u) { return (u || '').replace(/^sc-domain:/, '').replace(/^https?:\/\//, '').replace(/^www\./, '').split('/')[0].toLowerCase(); };
		function pick() {
			if (current || !site || !site.value) { return; }
			var h = host(site.value), best = null;
			Array.prototype.forEach.call(sel.options, function (o) {
				if (o.value && host(o.value) === h && (!best || o.value.indexOf('sc-domain:') === 0)) { best = o; }
			});
			if (best) { sel.value = best.value; }
		}
		api('gsc/sites').then(function (res) {
			var keep = sel.value;
			sel.innerHTML = '';
			var auto = document.createElement('option');
			auto.value = ''; auto.textContent = 'خودکار از روی آدرس سایت';
			sel.appendChild(auto);
			(res.sites || []).forEach(function (s) {
				var o = document.createElement('option');
				o.value = s.url; o.textContent = s.url;
				sel.appendChild(o);
			});
			sel.value = keep;
			pick();
		}).catch(function () {});
		if (site) { site.addEventListener('change', pick); }
	});

	/* ---- AI assistant on a task ---- */
	document.addEventListener('click', function (ev) {
		var btn = ev.target.closest('[data-sap-ai-task]');
		if (!btn) { return; }
		var scope = btn.closest('.sap-task-body') || btn.parentNode;
		var box = scope.querySelector('[data-sap-ai-box]');
		btn.classList.add('is-loading');
		var label = btn.lastChild.textContent;
		btn.lastChild.textContent = 'در حال نوشتن…';
		post('ai/task/' + btn.getAttribute('data-sap-ai-task'), {}).then(function (res) {
			box.querySelector('pre').textContent = res.output;
			box.hidden = false;
			btn.lastChild.textContent = 'نوشتن دوباره';
			toast('خروجی آماده شد');
		}).catch(function (e) {
			btn.lastChild.textContent = label;
			toast(e.message, true);
		}).then(function () { btn.classList.remove('is-loading'); });
	});

	/* ---- Copy an AI output block ---- */
	document.addEventListener('click', function (ev) {
		var btn = ev.target.closest('[data-sap-copy-target]');
		if (!btn || !navigator.clipboard) { return; }
		var pre = btn.closest('.sap-ai-output').querySelector('pre');
		navigator.clipboard.writeText(pre.textContent.trim()).then(function () { toast('کپی شد'); });
	});

	/* ---- Buttons that submit a form show progress ---- */
	document.addEventListener('submit', function (ev) {
		var b = ev.submitter;
		if (b && b.classList.contains('sap-btn') && !ev.defaultPrevented) { b.classList.add('is-loading'); }
	});

	/* ---- Show fields for the selected option: data-sap-show="name=value" ---- */
	document.querySelectorAll('[data-sap-show]').forEach(function (el) {
		var parts = el.getAttribute('data-sap-show').split('=');
		var form = el.closest('form');
		function apply() {
			var on = form.querySelector('input[name="' + parts[0] + '"]:checked');
			el.hidden = !on || on.value !== parts[1];
		}
		form.querySelectorAll('input[name="' + parts[0] + '"]').forEach(function (r) { r.addEventListener('change', apply); });
		apply();
	});

	/* ---- Task drawer: open a task beside the board instead of expanding it in place ---- */
	var drawer = document.createElement('aside');
	drawer.className = 'sap-drawer';
	drawer.setAttribute('aria-hidden', 'true');
	drawer.innerHTML = '<div class="sap-drawer-head"><span class="sap-drawer-title"></span><button type="button" class="sap-icon-btn" data-sap-drawer-close aria-label="بستن">✕</button></div><div class="sap-drawer-body"></div>';
	var shade = document.createElement('div');
	shade.className = 'sap-drawer-shade';
	document.body.appendChild(shade);
	document.body.appendChild(drawer);
	var current = null;
	function closeDrawer() {
		if (!current) { return; }
		current.card.appendChild(current.body);
		current.card.classList.remove('is-active');
		current = null;
		document.body.classList.remove('drawer-open');
		drawer.setAttribute('aria-hidden', 'true');
	}
	function openDrawer(card) {
		closeDrawer();
		var body = card.querySelector('.sap-task-body');
		if (!body) { return; }
		current = { card: card, body: body };
		card.classList.add('is-active');
		drawer.querySelector('.sap-drawer-title').textContent = card.querySelector('.sap-task-title').textContent;
		drawer.querySelector('.sap-drawer-body').appendChild(body);
		document.body.classList.add('drawer-open');
		drawer.setAttribute('aria-hidden', 'false');
		drawer.querySelector('.sap-drawer-body').scrollTop = 0;
	}
	document.addEventListener('click', function (ev) {
		var sum = ev.target.closest('.sap-task > summary');
		if (sum && !ev.target.closest('a, button, input')) {
			ev.preventDefault();
			openDrawer(sum.parentNode);
			return;
		}
		if (ev.target.closest('[data-sap-drawer-close]') || ev.target === shade) { closeDrawer(); }
	});
	document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { closeDrawer(); } });
	// A status change moves the card: keep the drawer in sync, close when the card leaves the page.
	document.addEventListener('sap:task-moved', function () { if (current && !document.body.contains(current.card)) { current = null; closeDrawer(); } });
	if (location.hash && location.hash.indexOf('#task-') === 0) {
		var target = document.querySelector(location.hash);
		if (target && target.classList.contains('sap-task')) { target.open = false; openDrawer(target); target.scrollIntoView({ block: 'center' }); }
	}

	/* ---- Run one or more sync steps from a button (data-sap-step="a,b"), then reload ---- */
	document.addEventListener('click', function (ev) {
		var btn = ev.target.closest('[data-sap-step]');
		if (!btn) { return; }
		ev.preventDefault();
		var steps = btn.getAttribute('data-sap-step').split(',');
		var project = btn.getAttribute('data-project');
		var label = btn.innerHTML;
		btn.disabled = true;
		btn.classList.add('is-loading');
		function run(i) {
			if (i >= steps.length) { toast('انجام شد؛ صفحه بروز می‌شود.'); setTimeout(function () { location.reload(); }, 700); return; }
			api('sync/' + project + '/' + steps[i] + '?force=' + (btn.hasAttribute('data-force') ? 1 : 0), { method: 'POST' }).then(function (res) {
				btn.textContent = res.msg || '…';
				if (res.more) { return run(i); }
				if (!res.ok) { throw new Error(res.msg || 'خطا'); }
				run(i + 1);
			}).catch(function (e) {
				toast(e.message, true);
				btn.disabled = false;
				btn.classList.remove('is-loading');
				btn.innerHTML = label;
			});
		}
		run(0);
	});

	/* ---- Keyword map: save / confirm rows in place ---- */
	document.addEventListener('submit', function (ev) {
		var form = ev.target.closest('.sap-km-row');
		if (!form) { return; }
		ev.preventDefault();
		var box = form.closest('[data-sap-keymap]');
		var pid = box ? box.getAttribute('data-sap-keymap') : form.getAttribute('data-sap-keymap-new');
		var data = {};
		new FormData(form).forEach(function (v, k) { data[k] = v; });
		var btn = form.querySelector('[type="submit"]');
		btn.disabled = true;
		post('keymap/' + pid, data).then(function (res) {
			toast(res.msg);
			if (!box) { location.reload(); return; }
			form.classList.add('is-confirmed');
			var chip = form.querySelector('.sap-km-meta .sap-chip');
			if (chip) { chip.textContent = 'تأیید شده'; chip.className = 'sap-chip is-src-manual'; }
		}).catch(function (e) { toast(e.message, true); }).then(function () { btn.disabled = false; });
	});
	document.addEventListener('click', function (ev) {
		var btn = ev.target.closest('[data-sap-keymap-confirm-all]');
		if (!btn) { return; }
		if (!window.confirm('همه کلمه‌های پیشنهادی (خودکار و هوش مصنوعی) به‌عنوان کلمه اصلی صفحه تأیید شوند؟')) { return; }
		post('keymap/' + btn.getAttribute('data-sap-keymap-confirm-all'), { confirm_all: 1 }).then(function (res) {
			toast(res.msg);
			setTimeout(function () { location.reload(); }, 600);
		}).catch(function (e) { toast(e.message, true); });
	});

	/* ---- Content audit & rewrite ---- */
	function contentStart(pid, url, task, btn) {
		if (btn) { btn.disabled = true; btn.classList.add('is-loading'); btn.textContent = 'خواندن محتوای صفحه…'; }
		post('content/' + pid + '/start', { url: url, task_id: task || 0 }).then(function (res) {
			location.href = res.url;
		}).catch(function (e) {
			toast(e.message, true);
			if (btn) { btn.disabled = false; btn.classList.remove('is-loading'); btn.textContent = 'دوباره امتحان کنید'; }
		});
	}
	document.addEventListener('click', function (ev) {
		var btn = ev.target.closest('[data-sap-content-start]');
		if (!btn) { return; }
		ev.preventDefault();
		contentStart(btn.getAttribute('data-sap-content-start'), btn.getAttribute('data-url'), btn.getAttribute('data-task'), btn);
	});
	document.addEventListener('submit', function (ev) {
		var f = ev.target.closest('[data-sap-content-form]');
		if (!f) { return; }
		ev.preventDefault();
		contentStart(f.getAttribute('data-sap-content-form'), f.querySelector('[name="url"]').value, 0, f.querySelector('button'));
	});
	var runBox = document.querySelector('[data-sap-rev-run]');
	if (runBox) {
		var revId = runBox.getAttribute('data-sap-rev-run');
		var bar = runBox.querySelector('[data-bar]');
		var msgEl = runBox.querySelector('[data-msg]');
		(function step() {
			post('revision/' + revId + '/step').then(function (res) {
				msgEl.textContent = res.msg;
				bar.style.width = (res.pct || 50) + '%';
				if (res.more) { step(); } else { setTimeout(function () { location.reload(); }, 500); }
			}).catch(function (e) {
				msgEl.textContent = e.message;
				runBox.classList.add('is-err');
				var again = document.createElement('button');
				again.type = 'button';
				again.className = 'sap-btn is-small is-primary';
				again.textContent = 'ادامه از همین مرحله';
				again.addEventListener('click', function () { again.remove(); runBox.classList.remove('is-err'); step(); });
				runBox.appendChild(again);
			});
		})();
	}
	var rev = document.querySelector('[data-sap-rev]');
	if (rev) {
		var edits = JSON.parse(rev.querySelector('[data-sap-rev-edits]').textContent || '[]');
		var blocks = JSON.parse(rev.querySelector('[data-sap-rev-blocks]').textContent || '[]');
		var accepted = function () {
			return Array.prototype.map.call(rev.querySelectorAll('input[name="accepted[]"]:checked'), function (c) { return parseInt(c.value, 10); });
		};
		var finalHtml = function () {
			var on = accepted(), out = [];
			blocks.forEach(function (b) {
				var html = b.html, add = [];
				edits.forEach(function (e) {
					if (e.block !== b.id || on.indexOf(e.id) === -1) { return; }
					if (e.op === 'replace') { html = e.html; } else if (e.op === 'delete') { html = ''; } else { add.push(e.html); }
				});
				if (html) { out.push(html); }
				out = out.concat(add);
			});
			return out.join('\n\n');
		};
		var setMode = function (mode) {
			rev.querySelectorAll('[data-sap-rev-mode] a').forEach(function (a) { a.classList.toggle('is-active', a.getAttribute('data-mode') === mode); });
			rev.querySelectorAll('.sap-edit [data-v]').forEach(function (v) { v.hidden = v.getAttribute('data-v').split(' ').indexOf(mode) === -1; });
			rev.querySelectorAll('.sap-blk.is-insert').forEach(function (b) { b.hidden = mode === 'old'; });
			rev.className = rev.className.replace(/\bis-mode-\w+/g, '') + ' is-mode-' + mode;
		};
		rev.querySelectorAll('[data-sap-rev-mode] a').forEach(function (a) {
			a.addEventListener('click', function (ev) { ev.preventDefault(); setMode(a.getAttribute('data-mode')); });
		});
		rev.addEventListener('change', function (ev) {
			if (ev.target.name !== 'accepted[]') { return; }
			ev.target.closest('.sap-edit').classList.toggle('is-off', !ev.target.checked);
			rev.querySelector('[data-sap-rev-count]').textContent = accepted().length.toLocaleString('fa-IR');
		});
		rev.addEventListener('submit', function (ev) {
			ev.preventDefault();
			var btn = rev.querySelector('[type="submit"]');
			btn.disabled = true;
			post('revision/' + rev.getAttribute('data-sap-rev') + '/approve', { accepted: accepted() }).then(function (res) {
				toast(res.msg);
				setTimeout(function () { location.reload(); }, 900);
			}).catch(function (e) { toast(e.message, true); btn.disabled = false; });
		});
		var rej = rev.querySelector('[data-sap-rev-reject]');
		if (rej) {
			rej.addEventListener('click', function () {
				if (!window.confirm('کل این پیشنهاد رد شود؟')) { return; }
				post('revision/' + rev.getAttribute('data-sap-rev') + '/reject').then(function () { location.reload(); });
			});
		}
		rev.querySelector('[data-sap-copy-html]').addEventListener('click', function () {
			var html = finalHtml();
			(navigator.clipboard ? navigator.clipboard.writeText(html) : Promise.reject()).then(function () {
				toast('HTML نهایی (با تغییرات انتخاب‌شده) کپی شد.');
			}).catch(function () {
				var ta = rev.querySelector('[data-sap-final-html]');
				ta.value = html; ta.hidden = false; ta.select(); document.execCommand('copy'); ta.hidden = true;
				toast('HTML نهایی کپی شد.');
			});
		});
	}

})();
