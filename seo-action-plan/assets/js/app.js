/* Sokan (سُکان) — app interactions (no build step). */
(function () {
	'use strict';
	if (!window.SAP) { return; }

	function api(path, opts) {
		opts = opts || {};
		opts.headers = Object.assign({ 'X-WP-Nonce': SAP.nonce }, opts.headers || {});
		opts.credentials = 'same-origin';
		return fetch(SAP.rest + path, opts).then(function (r) {
			return r.text().then(function (txt) {
				var j;
				try {
					j = JSON.parse(txt);
				} catch (e) {
					// The server printed something else (a PHP error page or warning): show its text, not «Unexpected token».
					var start = txt.indexOf('{"');
					try { j = start > 0 ? JSON.parse(txt.slice(start)) : null; } catch (e2) { j = null; }
					if (!j) {
						var plain = (new DOMParser().parseFromString(txt, 'text/html').body.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 300);
						throw new Error('پاسخ نامعتبر از سرور (HTTP ' + r.status + '): ' + (plain || 'خالی'));
					}
				}
				if (!r.ok) { var err = new Error((j && j.message) || ('HTTP ' + r.status)); err.status = r.status; err.code = j && j.code; throw err; }
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
		// Plain text, but URLs become links (e.g. «enable this Google API» errors).
		toastEl.textContent = '';
		String(msg).split(/(https?:\/\/[^\s]+)/).forEach(function (part, i) {
			if (i % 2) {
				var a = document.createElement('a');
				a.href = part.replace(/[.,)]+$/, '');
				a.target = '_blank';
				a.rel = 'noopener';
				a.textContent = 'باز کردن لینک ↗';
				toastEl.appendChild(a);
			} else {
				toastEl.appendChild(document.createTextNode(part));
			}
		});
		var long = String(msg).length > 90 || /https?:/.test(msg);
		toastEl.className = 'sap-toast is-show' + (bad ? ' is-bad' : '') + (long ? ' is-long' : '');
		clearTimeout(toastEl._t);
		toastEl._t = setTimeout(function () { toastEl.className = 'sap-toast'; }, long ? 14000 : 2600);
	}

	function fadeOut(el) {
		if (!el) { return; }
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
			items.forEach(function (li) { li.className = ''; li.sapStarted = 0; li.querySelector('[data-msg]').textContent = ''; });
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
				var askBox = panel.querySelector('[data-sap-sync-ask]');
				// SEO Signal steps: show what they cost and wait for the user's answer.
				if (li.hasAttribute('data-ask') && askBox && askBox.checked && !li.hasAttribute('data-approved')) {
					li.className = 'is-ask';
					msg.textContent = 'در حال محاسبه مصرف سهمیه…';
					api('estimate/' + project + '/' + li.getAttribute('data-step')).then(function (est) {
						if (!est.connected || (est.free && !force)) { li.setAttribute('data-approved', '1'); i--; next(); return; }
						msg.innerHTML = '';
						var t = document.createElement('span');
						t.textContent = est.msg + ' — ' + (est.units ? est.units.toLocaleString('fa-IR') + ' واحد از ' + est.left.toLocaleString('fa-IR') + ' واحد باقی‌مانده امروز' : 'بدون مصرف سهمیه') + ' ';
						var yes = document.createElement('button'); yes.type = 'button'; yes.className = 'sap-btn is-small is-primary'; yes.textContent = 'اجرا';
						var no = document.createElement('button'); no.type = 'button'; no.className = 'sap-btn is-small is-ghost'; no.textContent = 'رد این بخش';
						msg.appendChild(t); msg.appendChild(yes); msg.appendChild(no);
						yes.addEventListener('click', function () { li.setAttribute('data-approved', '1'); i--; next(); });
						no.addEventListener('click', function () { li.className = 'is-skip'; msg.textContent = 'به انتخاب شما رد شد (بدون مصرف سهمیه).'; next(); });
					}).catch(function () { li.setAttribute('data-approved', '1'); i--; next(); });
					return;
				}
				li.removeAttribute('data-approved');
				li.className = 'is-run';
				// Keep the progress of a resumable step and show a live clock, so a long step
				// (e.g. 10,000 products) visibly moves instead of a bare «در حال اجرا…».
				if (!li.sapStarted) { li.sapStarted = Date.now(); li.sapNote = 'در حال اجرا…'; }
				var started = li.sapStarted;
				var tick = function () {
					var sec = Math.round((Date.now() - started) / 1000);
					msg.textContent = li.sapNote + ' · ' + clock(sec);
				};
				tick();
				var timer = setInterval(tick, 1000);
				api('sync/' + project + '/' + li.getAttribute('data-step') + (force ? '?force=1' : ''), { method: 'POST' })
					.then(function (res) {
						clearInterval(timer);
						if (res.more) {
							// Resumable step: same step again until it reports done.
							li.sapNote = res.msg || '…';
							tick();
							i--;
							return;
						}
						li.sapStarted = 0;
						li.className = res.ok ? (res.skipped ? 'is-skip' : 'is-ok') : 'is-err';
						msg.textContent = (res.msg || '') + ' (' + clock(Math.round((Date.now() - started) / 1000)) + ')';
						if (!res.ok) { retryButton(li, msg); }
					})
					.catch(function (e) {
						clearInterval(timer);
						li.sapStarted = 0;
						li.className = 'is-err';
						msg.textContent = e.message;
						retryButton(li, msg);
					})
					.then(next);
			}
			function clock(sec) {
				return sec < 60 ? sec.toLocaleString('fa-IR') + ' ثانیه' : Math.floor(sec / 60).toLocaleString('fa-IR') + ' دقیقه و ' + (sec % 60).toLocaleString('fa-IR') + ' ثانیه';
			}
			// A failed step can be run again on its own; resumable steps go on from where they stopped.
			function retryButton(li, msg) {
				var again = document.createElement('button');
				again.type = 'button';
				again.className = 'sap-btn is-small is-primary';
				again.textContent = 'ادامه از همین نقطه';
				msg.appendChild(document.createTextNode(' '));
				msg.appendChild(again);
				again.addEventListener('click', function () {
					again.remove();
					li.className = 'is-run';
					var t0 = Date.now();
					msg.textContent = 'در حال اجرا…';
					(function once() {
						api('sync/' + project + '/' + li.getAttribute('data-step') + (force ? '?force=1' : ''), { method: 'POST' }).then(function (res) {
							if (res.more) { msg.textContent = (res.msg || '…') + ' · ' + clock(Math.round((Date.now() - t0) / 1000)); return once(); }
							li.className = res.ok ? (res.skipped ? 'is-skip' : 'is-ok') : 'is-err';
							msg.textContent = res.msg || '';
							if (!res.ok) { retryButton(li, msg); }
						}).catch(function (e) { li.className = 'is-err'; msg.textContent = e.message; retryButton(li, msg); });
					})();
				});
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
			var row = btn.closest('.sap-finding, .sap-proposal');
			if (row && row.classList.contains('sap-proposal')) {
				var list = row.parentNode;
				fadeOut(row);
				setTimeout(function () { if (list && !list.querySelector('.sap-proposal:not(.is-leaving)')) { location.reload(); } }, 400);
			} else {
				fadeOut(row);
			}
			var counter = document.querySelector('[data-sap-count]');
			if (counter) {
				var n = Math.max(0, parseInt(counter.getAttribute('data-sap-count'), 10) - 1);
				counter.setAttribute('data-sap-count', n);
				counter.textContent = n.toLocaleString('fa-IR');
			}
		}).catch(function (e) { toast(e.message, true); btn.disabled = false; });
	});

	/* ---- SERP analysis: run (resumable), pick, turn into a brief ---- */
	(function () {
		var form = document.querySelector('[data-sap-serp-form]');
		var prog = document.querySelector('[data-sap-serp-progress]');
		var run = function (pid, kw, url, restart) {
			if (prog) { prog.hidden = false; prog.querySelector('span').textContent = 'گرفتن ۱۰ نتیجه اول گوگل…'; prog.querySelector('i').style.width = '4%'; }
			var step = function (first) {
				post('serp/' + pid, { keyword: kw, url: url, restart: first && restart ? 1 : 0 }).then(function (res) {
					if (prog) { prog.querySelector('span').textContent = res.msg; prog.querySelector('i').style.width = (res.pct || 50) + '%'; }
					if (res.more) { return step(false); }
					toast(res.msg);
					location.href = res.url;
				}).catch(function (e) { toast(e.message, true); if (prog) { prog.querySelector('span').textContent = e.message; } });
			};
			step(true);
		};
		if (form) {
			form.addEventListener('submit', function (ev) {
				ev.preventDefault();
				run(form.getAttribute('data-sap-serp-form'), form.keyword.value.trim(), form.url.value.trim(), true);
			});
		}
		document.addEventListener('click', function (ev) {
			var pick = ev.target.closest('[data-sap-serp-pick]');
			var re = ev.target.closest('[data-sap-serp-rerun]');
			var idea = ev.target.closest('[data-sap-serp-idea]');
			var upd = ev.target.closest('[data-sap-serp-update]');
			if (pick && form) { form.keyword.value = pick.getAttribute('data-sap-serp-pick'); form.keyword.focus(); return; }
			if (re) { run(re.getAttribute('data-sap-serp-rerun'), re.getAttribute('data-keyword'), re.getAttribute('data-url') || '', true); return; }
			if (idea) {
				idea.disabled = true; idea.classList.add('is-loading');
				post('ideas/' + idea.getAttribute('data-sap-serp-idea') + '/keyword', { keyword: idea.getAttribute('data-keyword') }).then(function (res) { toast(res.msg); idea.classList.remove('is-loading'); }).catch(function (e) { toast(e.message, true); idea.disabled = false; idea.classList.remove('is-loading'); });
				return;
			}
			if (upd) {
				upd.disabled = true;
				post('updates/' + upd.getAttribute('data-sap-serp-update'), { url: upd.getAttribute('data-url'), reason: upd.getAttribute('data-reason') || '', source: 'serp' }).then(function (res) { toast(res.msg); }).catch(function (e) { toast(e.message, true); upd.disabled = false; });
			}
		});
	})();

	/* ---- My tasks: filter, status, finish with a work report ---- */
	(function () {
		var groups = document.querySelectorAll('[data-sap-my-group]');
		if (!groups.length && !document.querySelector('[data-sap-remind-now]')) { return; }
		var proj = '';
		var q = '';
		var apply = function () {
			document.querySelectorAll('[data-sap-mytask]').forEach(function (c) {
				var ok = (!proj || c.getAttribute('data-project') === proj) && (!q || c.getAttribute('data-text').indexOf(q) !== -1);
				c.hidden = !ok;
			});
			groups.forEach(function (g) { g.hidden = !g.querySelector('[data-sap-mytask]:not([hidden])'); });
		};
		document.querySelectorAll('[data-sap-my-project]').forEach(function (b) {
			b.addEventListener('click', function () {
				document.querySelectorAll('[data-sap-my-project]').forEach(function (x) { x.classList.remove('is-active'); });
				b.classList.add('is-active');
				proj = b.getAttribute('data-sap-my-project');
				apply();
			});
		});
		var s = document.querySelector('[data-sap-my-search]');
		if (s) { s.addEventListener('input', function () { q = s.value.trim().toLowerCase(); apply(); }); }
		document.addEventListener('click', function (ev) {
			var st = ev.target.closest('[data-sap-my-status]');
			var dn = ev.target.closest('[data-sap-my-done]');
			var cn = ev.target.closest('[data-sap-my-cancel]');
			var rm = ev.target.closest('[data-sap-remind-now]');
			if (rm) {
				rm.disabled = true;
				post('team/remind', {}).then(function (res) { toast(res.msg); rm.disabled = false; }).catch(function (e) { toast(e.message, true); rm.disabled = false; });
				return;
			}
			var card = ev.target.closest('[data-sap-mytask]');
			if (!card) { return; }
			if (st) {
				st.disabled = true;
				post('task/' + card.getAttribute('data-sap-mytask') + '/status', { status: st.getAttribute('data-sap-my-status') }).then(function (res) {
					toast(res.msg);
					setTimeout(function () { location.reload(); }, 500);
				}).catch(function (e) { toast(e.message, true); st.disabled = false; });
			} else if (dn) {
				card.querySelector('[data-sap-my-report]').hidden = false;
				card.querySelector('[data-sap-my-report] textarea').focus();
			} else if (cn) {
				card.querySelector('[data-sap-my-report]').hidden = true;
			}
		});
		document.addEventListener('submit', function (ev) {
			var f = ev.target.closest('[data-sap-my-report]');
			if (!f) { return; }
			ev.preventDefault();
			var card = f.closest('[data-sap-mytask]');
			var btn = f.querySelector('button[type="submit"]');
			btn.disabled = true;
			post('task/' + card.getAttribute('data-sap-mytask') + '/status', { status: 'done', result: f.result.value, spent: f.spent.value }).then(function (res) {
				toast(res.msg);
				fadeOut(card);
				setTimeout(function () { card.remove(); apply(); }, 450);
			}).catch(function (e) { toast(e.message, true); btn.disabled = false; });
		});
	})();

	/* ---- Alerts: act in place, check now, test channels ---- */
	document.addEventListener('click', function (ev) {
		var act = ev.target.closest('[data-sap-alert-do]');
		var chk = ev.target.closest('[data-sap-alerts-check]');
		var tst = ev.target.closest('[data-sap-notify-test]');
		if (!act && !chk && !tst) { return; }
		ev.preventDefault();
		var b = act || chk || tst;
		b.disabled = true;
		b.classList.add('is-loading');
		var done = function () { b.disabled = false; b.classList.remove('is-loading'); };
		if (act) {
			var li = act.closest('[data-sap-alert]');
			post('alert/' + li.getAttribute('data-sap-alert'), { do: act.getAttribute('data-sap-alert-do'), days: 7 }).then(function (res) {
				toast(res.msg);
				var what = act.getAttribute('data-sap-alert-do');
				if (what === 'task' || what === 'ack') { setTimeout(function () { location.reload(); }, 500); } else { fadeOut(li); }
			}).catch(function (e) { toast(e.message, true); done(); });
		} else if (chk) {
			post('alerts/' + chk.getAttribute('data-sap-alerts-check') + '/check', {}).then(function (res) {
				toast(res.msg);
				setTimeout(function () { location.reload(); }, 900);
			}).catch(function (e) { toast(e.message, true); done(); });
		} else {
			post('notify/test', { channel: tst.getAttribute('data-sap-notify-test') }).then(function (res) { toast(res.msg); done(); }).catch(function (e) { toast(e.message, true); done(); });
		}
	});

	/* ---- Title / meta straight to the client site ---- */
	document.addEventListener('click', function (ev) {
		var b = ev.target.closest('[data-sap-seo-apply]');
		if (!b) { return; }
		ev.preventDefault();
		if (!window.confirm('تایتل و متای پیشنهادی همین الان روی سایت کارفرما منتشر شود؟ (قابل برگشت است)')) { return; }
		b.disabled = true;
		b.classList.add('is-loading');
		post('finding/' + b.getAttribute('data-sap-seo-apply'), { do: 'seo_publish' }).then(function (res) {
			toast(res.msg);
			var li = b.closest('[data-sap-pp-item], .sap-proposal');
			if (li) { fadeOut(li); setTimeout(function () { li.remove(); }, 400); }
		}).catch(function (e) { toast(e.message, true); b.disabled = false; b.classList.remove('is-loading'); });
	});

	/* ---- Page cards: approve the checked actions of one page as one task ---- */
	document.addEventListener('click', function (ev) {
		var ok = ev.target.closest('[data-sap-pp-approve]');
		var rej = ev.target.closest('[data-sap-pp-reject]');
		var drop = ev.target.closest('[data-sap-pp-drop]');
		if (!ok && !rej && !drop) { return; }
		ev.preventDefault();
		var card = ev.target.closest('[data-sap-pagecard]');
		if (!card) { return; }
		var done = function () {
			if (!card.querySelector('[data-sap-pp-item]')) {
				fadeOut(card);
				setTimeout(function () { if (!document.querySelector('[data-sap-pagecard]:not(.is-leaving)')) { location.reload(); } }, 450);
			}
		};
		if (drop) {
			drop.disabled = true;
			post('finding/' + drop.getAttribute('data-sap-pp-drop'), { do: 'dismiss' }).then(function () {
				fadeOut(drop.closest('[data-sap-pp-item]'));
				setTimeout(function () { var li = drop.closest('[data-sap-pp-item]'); if (li) { li.remove(); } done(); }, 380);
			}).catch(function (e) { toast(e.message, true); drop.disabled = false; });
			return;
		}
		if (rej) {
			if (!window.confirm('همه اقدامات این صفحه رد شود؟')) { return; }
			var ids = Array.prototype.map.call(card.querySelectorAll('[data-sap-pp-item]'), function (li) { return li.getAttribute('data-sap-pp-item'); });
			rej.disabled = true;
			Promise.all(ids.map(function (id) { return post('finding/' + id, { do: 'dismiss' }); })).then(function () {
				toast('اقدامات این صفحه رد شد');
				card.querySelectorAll('[data-sap-pp-item]').forEach(function (li) { li.remove(); });
				done();
			}).catch(function (e) { toast(e.message, true); rej.disabled = false; });
			return;
		}
		var picked = Array.prototype.map.call(card.querySelectorAll('[data-sap-pp-item] input:checked'), function (i) { return i.value; });
		if (!picked.length) { toast('هیچ اقدامی تیک نخورده.', true); return; }
		var per = document.querySelector('[data-sap-pp-period]');
		ok.disabled = true;
		ok.classList.add('is-loading');
		post('page-plan/' + ok.getAttribute('data-sap-pp-approve'), { url: card.getAttribute('data-sap-pagecard'), ids: picked, period: per ? per.value : '' }).then(function (res) {
			toast(res.msg);
			picked.forEach(function (id) { var li = card.querySelector('[data-sap-pp-item="' + id + '"]'); if (li) { li.remove(); } });
			ok.disabled = false;
			ok.classList.remove('is-loading');
			done();
		}).catch(function (e) { toast(e.message, true); ok.disabled = false; ok.classList.remove('is-loading'); });
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

	/* ---- Team: one-time password link of a member ---- */
	document.addEventListener('click', function (ev) {
		var b = ev.target.closest('[data-sap-team-link]');
		if (!b) { return; }
		if (!window.confirm('یک لینک تازه تعیین رمز ساخته شود؟ (لینک‌های قبلی این عضو باطل می‌شوند)')) { return; }
		b.disabled = true;
		post('team/link', { user_id: b.getAttribute('data-sap-team-link') }).then(function (res) {
			var text = 'پنل کارها: ' + res.panel + '\nنام کاربری: ' + res.login + '\nتعیین رمز: ' + res.link;
			var fallback = function () { window.prompt('این متن را کپی کنید و برای عضو بفرستید:', text); };
			if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(function () { toast(res.msg); }, fallback); } else { fallback(); }
		}).catch(function (e) { toast(e.message, true); }).then(function () { b.disabled = false; });
	});

	/* ---- Copy to clipboard ---- */
	document.querySelectorAll('[data-sap-copy]').forEach(function (el) {
		el.title = 'کلیک برای کپی';
		el.addEventListener('click', function () {
			var text = el.getAttribute('data-sap-copy') || el.textContent.trim();
			var ok = function () {
				el.classList.add('is-copied');
				toast('کپی شد');
				setTimeout(function () { el.classList.remove('is-copied'); }, 900);
			};
			var fallback = function () {
				var ta = document.createElement('textarea');
				ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
				document.body.appendChild(ta); ta.select();
				try { document.execCommand('copy'); ok(); } catch (e) { toast('کپی نشد؛ متن را دستی انتخاب کنید', true); }
				ta.remove();
			};
			if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(ok, fallback); } else { fallback(); }
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
			toast(res.msg, !!res.warn);
			if (!box) { location.reload(); return; }
			form.classList.add('is-confirmed');
			form.setAttribute('data-id', res.id || form.getAttribute('data-id'));
			var chip = form.querySelector('.sap-km-meta .sap-chip');
			if (chip) { chip.textContent = 'تأیید شده'; chip.className = 'sap-chip is-src-manual'; }
		}).catch(function (e) { toast(e.message, true); }).then(function () { btn.disabled = false; });
	});
	document.addEventListener('change', function (ev) {
		var sel = ev.target.closest('[data-sap-km-status]');
		if (sel) {
			var row = sel.closest('.sap-km-row');
			var red = row.querySelector('.is-redirect');
			if (red) { red.hidden = sel.value !== 'prune_301'; }
			row.className = row.className.replace(/\bis-st-[a-z0-9_]+\b/, '') + ' is-st-' + sel.value;
		}
		if (ev.target.matches('[data-sap-km-all]')) {
			document.querySelectorAll('[data-sap-km-pick]').forEach(function (c) { c.checked = ev.target.checked; });
		}
	});
	document.addEventListener('click', function (ev) {
		var q = ev.target.closest('[data-sap-km-q]');
		if (q) {
			var row = q.closest('.sap-km-row');
			row.querySelector('[name="primary_kw"]').value = q.getAttribute('data-sap-km-q');
			var st = row.querySelector('[data-sap-km-status]');
			if (st && ['none', 'noindex', 'prune_410', 'prune_301'].indexOf(st.value) !== -1) { st.value = 'target'; st.dispatchEvent(new Event('change', { bubbles: true })); }
			row.querySelector('[name="primary_kw"]').focus();
		}
		var bar = ev.target.closest('[data-sap-km-bulk]');
		if (!bar) { return; }
		var pid = bar.getAttribute('data-sap-km-bulk');
		var go = ev.target.closest('[data-sap-km-bulk-go]');
		var prune = ev.target.closest('[data-sap-km-prune-task]');
		if (go) {
			var urls = Array.prototype.map.call(document.querySelectorAll('[data-sap-km-pick]:checked'), function (c) { return c.value; });
			if (!urls.length) { toast('اول صفحه‌ها را تیک بزنید.', true); return; }
			var status = bar.querySelector('[data-sap-km-bulk-status]').value;
			if (/^prune/.test(status) && !window.confirm(urls.length + ' صفحه برای هرس علامت بخورد؟ (چیزی روی سایت حذف نمی‌شود؛ فقط در نقشه و اکشن پلن ثبت می‌شود)')) { return; }
			busy(go, '…');
			post('keymap/' + pid, { do: 'bulk', urls: urls, status: status }).then(function (res) { toast(res.msg); setTimeout(function () { location.reload(); }, 600); }).catch(function (e) { toast(e.message, true); idle(go); });
		}
		if (prune) {
			busy(prune, '…');
			post('keymap/' + pid, { do: 'prune_task' }).then(function (res) { toast(res.msg); idle(prune); }).catch(function (e) { toast(e.message, true); idle(prune); });
		}
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

	/* ---- Comments (your own notes) ---- */
	function esc(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); } // Safe in text and attributes.
	document.addEventListener('click', function (ev) {
		var add = ev.target.closest('[data-sap-comment-add]');
		var del = ev.target.closest('[data-sap-comment-del]');
		if (!add && !del) { return; }
		var box = ev.target.closest('[data-sap-comments]');
		if (add) {
			var ta = box.querySelector('textarea');
			var body = ta.value.trim();
			if (!body) { ta.focus(); return; }
			var p = box.getAttribute('data-sap-comments').split(':');
			add.disabled = true;
			post('comment', { type: p[0], id: p[1], project: p[2], body: body }).then(function (res) {
				var li = document.createElement('li');
				li.setAttribute('data-id', res.id);
				li.innerHTML = '<p>' + esc(body).replace(/\n/g, '<br>') + '</p><small class="sap-muted">' + esc(res.meta) + '</small><button type="button" class="sap-icon-btn" data-sap-comment-del title="حذف">✕</button>';
				box.querySelector('.sap-comment-list').appendChild(li);
				ta.value = '';
				toast('یادداشت ثبت شد');
			}).catch(function (e) { toast(e.message, true); }).then(function () { add.disabled = false; });
		} else {
			var li2 = del.closest('li');
			if (!window.confirm('این یادداشت حذف شود؟')) { return; }
			api('comment/' + li2.getAttribute('data-id'), { method: 'DELETE' }).then(function () { fadeOut(li2); }).catch(function (e) { toast(e.message, true); });
		}
	});

	/* ---- Delete a task card / bulk actions on the plan board ---- */
	document.addEventListener('click', function (ev) {
		var btn = ev.target.closest('[data-sap-task-del]');
		if (!btn) { return; }
		ev.preventDefault();
		ev.stopPropagation();
		if (!window.confirm('این کار حذف شود؟ (یافته مرتبط به صندوق ورودی برمی‌گردد)')) { return; }
		var card = btn.closest('.sap-task') || document.querySelector('.sap-task.is-active');
		api('task/' + btn.getAttribute('data-sap-task-del'), { method: 'DELETE' }).then(function (res) {
			toast(res.msg);
			document.dispatchEvent(new Event('sap:task-moved'));
			fadeOut(card);
			setTimeout(refreshCounts, 300);
		}).catch(function (e) { toast(e.message, true); });
	});
	var bulk = document.querySelector('[data-sap-bulkbar]');
	if (bulk) {
		var tools = bulk.querySelector('.sap-bulk-tools');
		var picks = function () { return Array.prototype.slice.call(document.querySelectorAll('.sap-board .sap-task-pick, .sap-stack .sap-task-pick')); };
		var count = function () { bulk.querySelector('[data-sap-bulk-n]').textContent = picks().filter(function (c) { return c.checked; }).length.toLocaleString('fa-IR'); };
		bulk.querySelector('[data-sap-bulk-toggle]').addEventListener('change', function (ev) {
			document.body.classList.toggle('is-picking', ev.target.checked);
			tools.hidden = !ev.target.checked;
			if (!ev.target.checked) { picks().forEach(function (c) { c.checked = false; }); count(); }
		});
		bulk.querySelector('[data-sap-bulk-all]').addEventListener('change', function (ev) {
			document.querySelectorAll('.sap-board .sap-task-pick').forEach(function (c) { c.checked = ev.target.checked; });
			count();
		});
		document.addEventListener('change', function (ev) { if (ev.target.classList.contains('sap-task-pick')) { count(); } });
		document.addEventListener('click', function (ev) { if (ev.target.classList.contains('sap-task-pick')) { ev.stopPropagation(); } }, true);
		bulk.querySelector('[data-sap-bulk-go]').addEventListener('click', function () {
			var ids = picks().filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
			var v = bulk.querySelector('[data-sap-bulk-do]').value;
			if (!ids.length || !v) { toast('کارها و اقدام را انتخاب کنید', true); return; }
			var parts = v.split(':');
			if (parts[0] === 'delete' && !window.confirm(ids.length.toLocaleString('fa-IR') + ' کار حذف شود؟')) { return; }
			post('tasks/bulk', { ids: ids, do: parts[0], value: parts[1] || '' }).then(function (res) {
				toast(res.msg);
				setTimeout(function () { location.reload(); }, 600);
			}).catch(function (e) { toast(e.message, true); });
		});
	}

	/* ---- Content ideas & calendar ---- */
	function busy(btn, text) { btn.disabled = true; btn.classList.add('is-loading'); if (text) { btn.dataset.label = btn.innerHTML; btn.textContent = text; } }
	function idle(btn) { btn.disabled = false; btn.classList.remove('is-loading'); if (btn.dataset.label) { btn.innerHTML = btn.dataset.label; } }
	document.addEventListener('click', function (ev) {
		var gen = ev.target.closest('[data-sap-ideas-gen]');
		var fill = ev.target.closest('[data-sap-cal-fill]');
		var sheet = ev.target.closest('[data-sap-cal-sheet]');
		var create = ev.target.closest('[data-sap-sheet-create]');
		var act = ev.target.closest('[data-sap-idea-do]');
		if (gen) {
			busy(gen, 'هوش مصنوعی در حال بررسی داده‌ها… (۳۰ تا ۹۰ ثانیه)');
			var n = document.querySelector('[data-sap-ideas-count]');
			post('ideas/' + gen.getAttribute('data-sap-ideas-gen') + '/generate', { count: n ? n.value : 10, source: gen.getAttribute('data-source') || 'all' }).then(function (res) {
				toast(res.msg); setTimeout(function () { location.reload(); }, 900);
			}).catch(function (e) { toast(e.message, true); idle(gen); });
		} else if (fill) {
			var t = document.querySelector('[data-sap-cal-target]');
			busy(fill, 'در حال ساخت ایده‌ها و تکمیل ماه…');
			post('ideas/' + fill.getAttribute('data-sap-cal-fill') + '/generate', { period: fill.getAttribute('data-period'), target: t ? t.value : 8 }).then(function (res) {
				toast(res.msg); setTimeout(function () { location.reload(); }, 900);
			}).catch(function (e) { toast(e.message, true); idle(fill); });
		} else if (sheet) {
			busy(sheet, 'در حال نوشتن در شیت…');
			post('calendar/' + sheet.getAttribute('data-sap-cal-sheet') + '/sheet').then(function (res) { toast(res.msg); idle(sheet); })
				.catch(function (e) { toast(e.message, true); idle(sheet); });
		} else if (create) {
			var existing = create.hasAttribute('data-existing') ? (document.querySelector('[data-sap-sheet-existing]') || {}).value : '';
			if (create.hasAttribute('data-existing') && !existing) { toast('لینک گوگل شیت را وارد کنید', true); return; }
			if (!window.confirm(existing ? 'تب‌های Blog، B keyword، کلاسترینگ، نقشه کلمات و لاگ تغییرات به این شیت اضافه و پر شوند؟' : 'گوگل شیت پروژه در حساب گوگل متصل ساخته و به پروژه وصل شود؟')) { return; }
			busy(create, 'در حال ساخت و پر کردن شیت…');
			post('calendar/' + create.getAttribute('data-sap-sheet-create') + '/sheet', { create: 1, existing: existing }).then(function (res) {
				toast(res.msg);
				if (res.url) { window.open(res.url, '_blank'); }
				setTimeout(function () { location.reload(); }, 1200);
			}).catch(function (e) { toast(e.message, true); idle(create); });
		} else if (act) {
			var box = act.closest('[data-sap-idea]');
			var id = box.getAttribute('data-sap-idea');
			var doing = act.getAttribute('data-sap-idea-do');
			var data = { do: doing };
			if (doing === 'schedule') { var m = box.querySelector('[data-sap-idea-month]'); data.period = m ? m.value : ''; }
			if (doing === 'move') { data.dir = act.getAttribute('data-dir'); }
			if (doing === 'published') { var u = window.prompt('لینک مقاله منتشرشده:'); if (u === null) { return; } data.url = u; }
			if (doing === 'delete' && !window.confirm('این ایده کامل حذف شود؟')) { return; }
			act.disabled = true;
			post('idea/' + id, data).then(function (res) {
				toast(res.msg);
				if (['schedule', 'approve', 'reject', 'delete'].indexOf(doing) !== -1 && box.classList.contains('sap-idea')) { fadeOut(box); return; }
				setTimeout(function () { location.reload(); }, 500);
			}).catch(function (e) { toast(e.message, true); act.disabled = false; });
		}
	});
	document.addEventListener('submit', function (ev) {
		var f = ev.target.closest('[data-sap-idea-edit]');
		if (!f) { return; }
		ev.preventDefault();
		var data = { do: 'edit' };
		new FormData(f).forEach(function (v, k) { data[k] = v; });
		post('idea/' + f.closest('[data-sap-idea]').getAttribute('data-sap-idea'), data).then(function (res) { toast(res.msg); setTimeout(function () { location.reload(); }, 500); })
			.catch(function (e) { toast(e.message, true); });
	});

	/* ---- Keyword cloud filter ---- */
	document.querySelectorAll('[data-sap-cloud-kind] a').forEach(function (a) {
		a.addEventListener('click', function (ev) {
			ev.preventDefault();
			var kind = a.getAttribute('data-kind');
			a.parentNode.querySelectorAll('a').forEach(function (x) { x.classList.toggle('is-active', x === a); });
			a.closest('[data-sap-cloud]').querySelectorAll('.sap-cloud-w').forEach(function (w) { w.classList.toggle('is-hidden', kind !== 'all' && w.getAttribute('data-kind') !== kind); });
		});
	});

	/* ---- Client site: test connection, revision draft / publish / rollback ---- */
	document.addEventListener('click', function (ev) {
		var test = ev.target.closest('[data-sap-site-test]');
		var act = ev.target.closest('[data-sap-site-do]');
		if (test) {
			var st = document.querySelector('[data-sap-site-status]');
			busy(test, 'در حال تست…');
			post('site/' + test.getAttribute('data-sap-site-test') + '/test').then(function (res) { st.textContent = res.msg; st.className = 'sap-ok'; })
				.catch(function (e) { st.textContent = e.message; st.className = 'sap-bad'; }).then(function () { idle(test); });
		} else if (act) {
			var box = act.closest('[data-sap-rev-site]');
			var doing = act.getAttribute('data-sap-site-do');
			var msgs = { publish: 'تغییرات روی سایت کارفرما منتشر شود؟', rollback: 'محتوا به نسخه قبل از انتشار برگردد؟', discard: 'پیش‌نویس روی سایت حذف شود؟' };
			if (msgs[doing] && !window.confirm(msgs[doing])) { return; }
			var seo = box.querySelector('[data-sap-site-seo]');
			busy(act, 'در حال ارتباط با سایت…');
			post('revision/' + box.getAttribute('data-sap-rev-site') + '/site', { do: doing, extra: { seo: seo && seo.checked ? 1 : 0 } }).then(function (res) {
				toast(res.msg);
				if (res.preview_url) { window.open(res.preview_url, '_blank'); }
				setTimeout(function () { location.reload(); }, 900);
			}).catch(function (e) { toast(e.message, true); idle(act); });
		}
	});

	/* ---- Network test (settings) ---- */
	document.addEventListener('click', function (ev) {
		var b = ev.target.closest('[data-sap-net-test]');
		if (!b) { return; }
		var out = document.querySelector('[data-sap-net-result]');
		busy(b);
		post('net/test', { url: b.getAttribute('data-sap-net-test') }).then(function (res) {
			out.textContent = b.textContent.replace('تست اتصال ', '') + ': ' + res.msg; out.className = res.ok ? 'sap-ok' : 'sap-bad';
		}).catch(function (e) { out.textContent = e.message; out.className = 'sap-bad'; }).then(function () { idle(b); });
	});

	/* ---- Monthly repeating tasks ---- */
	document.addEventListener('click', function (ev) {
		var rp = ev.target.closest('[data-sap-task-repeat]');
		if (rp) {
			ev.preventDefault();
			ev.stopPropagation();
			post('task/' + rp.getAttribute('data-sap-task-repeat') + '/repeat').then(function (res) { toast(res.msg); rp.classList.toggle('is-on', !!res.on); }).catch(function (e) { toast(e.message, true); });
		}
		var pr = ev.target.closest('[data-sap-plan-recurring]');
		if (pr) {
			busy(pr, '…');
			post('plan/' + pr.getAttribute('data-sap-plan-recurring') + '/recurring', { period: pr.getAttribute('data-period') }).then(function (res) { toast(res.msg); setTimeout(function () { location.reload(); }, 800); }).catch(function (e) { toast(e.message, true); idle(pr); });
		}
	}, true);

	/* ---- Custom tasks from a brief; calendar article from your keyword ---- */
	document.addEventListener('submit', function (ev) {
		var f = ev.target;
		if (f.matches('[data-sap-custom-form]')) {
			ev.preventDefault();
			var box = f.closest('[data-sap-custom]');
			var out = box.querySelector('[data-sap-custom-out]');
			var b = f.querySelector('[type=submit]');
			busy(b, 'در حال تحلیل و ساخت کارها… (۲۰ تا ۶۰ ثانیه)');
			post('plan/' + box.getAttribute('data-sap-custom') + '/custom', { brief: f.brief.value, focus_id: f.focus_id.value }).then(function (res) {
				idle(b);
				out.innerHTML = res.html;
			}).catch(function (e) { toast(e.message, true); idle(b); });
		}
		if (f.matches('[data-sap-kwidea]')) {
			ev.preventDefault();
			var bt = f.querySelector('[type=submit]');
			busy(bt, 'در حال ساخت بریف… (۱۵ تا ۴۰ ثانیه)');
			post('ideas/' + f.getAttribute('data-sap-kwidea') + '/keyword', { keyword: f.keyword.value, secondary: f.secondary.value, notes: f.notes.value, period: f.getAttribute('data-period') }).then(function (res) {
				toast(res.msg, /هشدار/.test(res.msg || ''));
				setTimeout(function () { location.reload(); }, 2200);
			}).catch(function (e) { toast(e.message, true); idle(bt); });
		}
	});
	document.addEventListener('click', function (ev) {
		var c = ev.target.closest('[data-sap-custom-commit]');
		if (!c) { return; }
		var box = c.closest('[data-sap-custom]');
		var picked = Array.prototype.map.call(box.querySelectorAll('[data-sap-custom-pick]:checked'), function (x) { return x.value; });
		if (!picked.length) { toast('حداقل یک کار را انتخاب کنید.', true); return; }
		busy(c, '…');
		post('plan/' + box.getAttribute('data-sap-custom') + '/custom', { do: 'commit', picked: picked, period: box.getAttribute('data-period') }).then(function (res) {
			toast(res.msg);
			setTimeout(function () { location.reload(); }, 900);
		}).catch(function (e) { toast(e.message, true); idle(c); });
	});

	/* ---- Screaming Frog wizard: steps, scope, connection test (polls the agent's ping) ---- */
	(function () {
		var box = document.querySelector('[data-sap-sfw]');
		if (!box) { return; }
		var pid = box.getAttribute('data-sap-sfw');
		var result = box.querySelector('[data-sap-sf-result]');
		var reload = function (msg) { if (msg) { toast(msg); } setTimeout(function () { location.reload(); }, 700); };
		box.addEventListener('click', function (ev) {
			var head = ev.target.closest('.sap-sfw-steps > li.is-done .sap-sfw-body > b');
			if (head) { head.closest('li').classList.toggle('is-edit'); }
			var cfg = ev.target.closest('[data-sap-sf-config]');
			if (cfg) {
				var d = {}; d[cfg.getAttribute('data-sap-sf-config')] = 1;
				busy(cfg, '…');
				post('crawl/' + pid + '/config', d).then(function (r) { reload(r.msg); }).catch(function (e) { toast(e.message, true); idle(cfg); });
			}
			var chk = ev.target.closest('[data-sap-sf-check]');
			if (chk) {
				busy(chk, 'در انتظار پیام ایجنت…');
				var started = Date.now(), before = null;
				var tick = function () {
					api('crawl/' + pid + '/status').then(function (r) {
						var at = r.ping ? r.ping.at : '';
						if (before === null) { before = at; }
						if (r.ping && (at !== before || (Date.now() - started < 1500 && r.ping.sf_found))) {
							idle(chk);
							result.innerHTML = '';
							var sp = document.createElement('span');
							sp.className = r.ping.sf_found ? 'sap-ok' : 'sap-bad';
							sp.textContent = r.ping.sf_found ? '✓ اتصال برقرار است و Screaming Frog پیدا شد (' + r.ping.os + ' · ' + r.ping.host + ').' : 'ایجنت به سکان وصل شد ولی Screaming Frog را پیدا نکرد؛ برنامه را نصب کنید یا مسیرش را با -SpiderPath بدهید.';
							result.appendChild(sp);
							if (r.ping.sf_found) { reload('اتصال تأیید شد؛ مرحله بعد.'); }
							return;
						}
						if (Date.now() - started > 180000) {
							idle(chk);
							result.innerHTML = '<span class="sap-bad">پیامی نرسید. دستور تست را اجرا کرده‌اید؟ اگر خطای اتصال داد، فایروال/پروکسی کامپیوتر یا آدرس سایت سکان را بررسی کنید.</span>';
							return;
						}
						result.innerHTML = '<span class="sap-muted">در انتظار اجرای دستور تست روی کامپیوتر شما… (' + Math.round((Date.now() - started) / 1000) + ' ثانیه)</span>';
						setTimeout(tick, 4000);
					}).catch(function (e) { idle(chk); toast(e.message, true); });
				};
				tick();
			}
		});
		box.addEventListener('submit', function (ev) {
			var f = ev.target;
			if (f.matches('[data-sap-sf-scope]')) {
				ev.preventDefault();
				var b = f.querySelector('[type=submit]');
				busy(b, '…');
				post('crawl/' + pid + '/config', { mode: f.mode.value, limit: f.limit.value }).then(function (r) { reload(r.msg); }).catch(function (e) { toast(e.message, true); idle(b); });
			}
			if (f.matches('[data-sap-sf-download]')) {
				toast('فایل ایجنت دانلود می‌شود؛ مرحله بعد: تست اتصال.');
				setTimeout(function () { location.reload(); }, 2500);
			}
		});
	}());

	/* ---- Pillars: content audit, content updates, clustering sheet, competitors, off-page data ---- */
	(function () {
		var done = function (res) { toast(res.msg || 'انجام شد'); setTimeout(function () { location.reload(); }, 900); };
		var drawer = document.querySelector('[data-sap-audit-drawer]');
		var auditTouched = false;
		var openAudit = function (html) {
			if (!drawer) { return; }
			drawer.querySelector('[data-sap-audit-body]').innerHTML = html;
			drawer.hidden = false;
			document.body.classList.add('sap-sheet-open');
		};
		var closeAudit = function () {
			if (!drawer) { return; }
			drawer.hidden = true;
			document.body.classList.remove('sap-sheet-open');
			if (auditTouched) { location.reload(); }
		};
		document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && drawer && !drawer.hidden) { closeAudit(); } });
		var picked = function () { return Array.prototype.map.call(document.querySelectorAll('[data-sap-audit-pick]:checked'), function (c) { return c.value; }); };
		document.addEventListener('change', function (ev) {
			if (ev.target.matches('[data-sap-audit-all]')) {
				document.querySelectorAll('[data-sap-audit-pick]').forEach(function (c) { c.checked = ev.target.checked; });
			}
		});
		document.addEventListener('click', function (ev) {
			var one = ev.target.closest('[data-sap-audit-one]');
			var add = ev.target.closest('[data-sap-update-add]');
			var bulk = ev.target.closest('[data-sap-update-bulk]');
			var udo = ev.target.closest('[data-sap-update-do]');
			var cl = ev.target.closest('[data-sap-cluster-sheet]');
			var rm = ev.target.closest('[data-sap-comp-remove]');
			var oc = ev.target.closest('[data-sap-offpage-collect]');
			if (one) {
				var oneUrl = one.getAttribute('data-sap-audit-one');
				busy(one, '…');
				openAudit('<div class="sap-ar-loading"><span class="sap-spin"></span><b>در حال تحلیل هوشمند صفحه…</b><p class="sap-muted">محتوا و داده وردپرس صفحه خوانده می‌شود، با کلمات و کوئری‌های سرچ کنسول سنجیده و از دید Focus و فروش تحلیل می‌شود (۲۰ تا ۶۰ ثانیه).</p><div class="sap-progress"><i class="is-indeterminate"></i></div></div>');
				post('audit/' + one.getAttribute('data-project') + '/' + one.getAttribute('data-group'), { url: oneUrl }).then(function (res) {
					idle(one);
					if (res.html) { openAudit(res.html); auditTouched = true; } else { closeAudit(); }
					toast(res.msg || 'تحلیل انجام شد');
				}).catch(function (e) { toast(e.message, true); idle(one); closeAudit(); });
			}
			var view = ev.target.closest('[data-sap-audit-view]');
			if (view) {
				ev.preventDefault();
				openAudit('<div class="sap-ar-loading"><span class="sap-spin"></span><b>در حال باز کردن…</b></div>');
				api('audit/' + view.getAttribute('data-project') + '/view?url=' + encodeURIComponent(view.getAttribute('data-sap-audit-view'))).then(function (res) { openAudit(res.html); }).catch(function (e) { toast(e.message, true); closeAudit(); });
			}
			if (ev.target.closest('[data-sap-audit-close]')) { closeAudit(); }
			var pk = ev.target.closest('[data-sap-audit-picked]');
			if (pk) {
				var urlsP = picked();
				if (!urlsP.length) { toast('اول صفحه‌ها را تیک بزنید.', true); return; }
				if (urlsP.length > 30) { toast('حداکثر ۳۰ صفحه در هر بار؛ ۳۰ صفحه اول تحلیل می‌شود.'); }
				busy(pk, '…');
				post('audit/' + pk.getAttribute('data-sap-audit-picked') + '/' + pk.getAttribute('data-group'), { urls: urlsP }).then(function () {
					idle(pk);
					var runner = document.createElement('button');
					runner.type = 'button';
					runner.className = 'sap-btn is-small is-primary';
					runner.setAttribute('data-sap-step', 'audit_' + pk.getAttribute('data-group'));
					runner.setAttribute('data-project', pk.getAttribute('data-sap-audit-picked'));
					runner.setAttribute('data-force', '');
					runner.textContent = 'در حال تحلیل ' + urlsP.length + ' صفحه…';
					pk.parentNode.insertBefore(runner, pk.nextSibling);
					pk.hidden = true;
					runner.click();
				}).catch(function (e) { toast(e.message, true); idle(pk); });
			}
			var cp = ev.target.closest('[data-sap-audit-body] [data-sap-copy]');
			if (cp && navigator.clipboard) {
				navigator.clipboard.writeText(cp.textContent.trim()).then(function () { cp.classList.add('is-copied'); toast('کپی شد'); setTimeout(function () { cp.classList.remove('is-copied'); }, 900); });
			}
			if (add) {
				busy(add, '…');
				post('updates/' + add.getAttribute('data-project'), { url: add.getAttribute('data-sap-update-add'), source: 'audit' }).then(function (res) { toast(res.msg); add.textContent = '✓ در تقویم'; }).catch(function (e) { toast(e.message, true); idle(add); });
			}
			if (bulk) {
				var urls = picked();
				if (!urls.length) { toast('اول صفحه‌ها را تیک بزنید.', true); return; }
				var per = document.querySelector('[data-sap-update-period]');
				busy(bulk, '…');
				post('updates/' + bulk.getAttribute('data-sap-update-bulk'), { urls: urls, period: per ? per.value : '', source: 'audit' }).then(function (res) { toast(res.msg); idle(bulk); }).catch(function (e) { toast(e.message, true); idle(bulk); });
			}
			if (udo) {
				var what = udo.getAttribute('data-sap-update-do');
				if (what === 'delete' && !window.confirm('این صفحه از آپدیت‌های این ماه حذف شود؟')) { return; }
				busy(udo, what === 'brief' ? 'در حال نوشتن بریف… (۲۰ تا ۶۰ ثانیه)' : '…');
				post('update/' + udo.getAttribute('data-id'), { do: what, status: udo.getAttribute('data-status') || '' }).then(done).catch(function (e) { toast(e.message, true); idle(udo); });
			}
			if (cl) {
				var go = function (over) {
					busy(cl, 'در حال نوشتن در شیت…');
					post('cluster/' + cl.getAttribute('data-sap-cluster-sheet') + '/sheet', { overwrite: over ? 1 : 0 }).then(function (res) { toast(res.msg); idle(cl); }).catch(function (e) {
						idle(cl);
						if (e.status === 409 && window.confirm(e.message + '\n\nجایگزین شود؟')) { go(true); } else { toast(e.message, true); }
					});
				};
				go(false);
			}
			if (rm) {
				if (!window.confirm('رقیب ' + rm.getAttribute('data-sap-comp-remove') + ' حذف شود؟')) { return; }
				post('competitors/' + rm.getAttribute('data-project'), { do: 'remove', domain: rm.getAttribute('data-sap-comp-remove') }).then(done).catch(function (e) { toast(e.message, true); });
			}
			if (oc) {
				if (!window.confirm('این درخواست از سهمیه روزانه سئو سیگنال مصرف می‌کند. ادامه؟')) { return; }
				busy(oc, 'در حال دریافت از سئو سیگنال…');
				post('offpage/' + oc.getAttribute('data-project') + '/collect', { what: oc.getAttribute('data-sap-offpage-collect') }).then(done).catch(function (e) { toast(e.message, true); idle(oc); });
			}
		});
		document.addEventListener('submit', function (ev) {
			var f = ev.target;
			if (f.matches('[data-sap-update-form]')) {
				ev.preventDefault();
				var b = f.querySelector('[type=submit]');
				busy(b, '…');
				post('updates/' + f.getAttribute('data-sap-update-form'), { url: f.url.value, reason: f.reason.value, period: f.getAttribute('data-period'), source: 'manual' }).then(done).catch(function (e) { toast(e.message, true); idle(b); });
			}
			if (f.matches('[data-sap-comp-add]')) {
				ev.preventDefault();
				var bb = f.querySelector('[type=submit]');
				busy(bb, '…');
				post('competitors/' + f.getAttribute('data-sap-comp-add'), { do: 'add', domain: f.domain.value }).then(done).catch(function (e) { toast(e.message, true); idle(bb); });
			}
		});
	}());

	/* ---- AI cost profiles (settings) ---- */
	document.querySelectorAll('[data-sap-ai-profile]').forEach(function (b) {
		b.addEventListener('click', function () {
			var m = JSON.parse(b.getAttribute('data-sap-ai-profile'));
			Object.keys(m).forEach(function (k) { var f = document.querySelector('input[name=' + k + ']'); if (f) { f.value = m[k]; } });
			document.querySelectorAll('[data-sap-ai-profile]').forEach(function (x) { x.classList.toggle('is-on', x === b); });
			toast('مدل‌ها پر شدند؛ «ذخیره تنظیمات» را بزنید.');
		});
	});

	/* ---- AI gateway test (settings) ---- */
	var aiTest = document.querySelector('[data-sap-ai-test]');
	if (aiTest) {
		aiTest.addEventListener('click', function () {
			var out = document.querySelector('[data-sap-ai-test-out]');
			busy(aiTest, 'در حال تست…');
			post('ai/test').then(function (j) {
				out.hidden = false;
				out.innerHTML = '<ol class="sap-ss-steps">' + j.steps.map(function (s) {
					return '<li class="' + (s.ok ? 'is-done' : 'is-todo is-bad') + '"><i>' + (s.ok ? '✓' : '!') + '</i><div><b dir="auto">' + esc(s.name) + '</b><p class="sap-small">' + esc(s.msg) + '</p></div></li>';
				}).join('') + '</ol>' + (j.models && j.models.length ? '<details class="sap-small"><summary>مدل‌های حساب شما (' + j.models.length.toLocaleString('fa-IR') + ') — روی هر کدام بزنید تا در «مدل تحلیل پروژه» قرار بگیرد</summary><div class="sap-kw-chips" dir="ltr">' + j.models.map(function (m) { return '<button type="button" class="sap-chip" data-sap-ai-pick="' + esc(m) + '">' + esc(m) + '</button>'; }).join('') + '</div></details>' : '');
				toast(j.ok ? 'کلید و مدل‌ها سالم‌اند.' : 'مشکل پیدا شد؛ جزئیات زیر دکمه.', !j.ok);
			}).catch(function (e) { toast(e.message, true); }).then(function () { idle(aiTest); });
		});
		document.addEventListener('click', function (ev) {
			var pick = ev.target.closest('[data-sap-ai-pick]');
			if (!pick) { return; }
			var f = document.querySelector('input[name=ai_model]');
			f.value = pick.getAttribute('data-sap-ai-pick'); f.focus();
			toast('مدل در فیلد قرار گرفت؛ تنظیمات را ذخیره کنید.');
		});
	}

	/* ---- SEO Signal tab ---- */
	var ssProject = document.querySelector('[data-sap-ss-project]');
	if (ssProject) {
		var pid = ssProject.value, box = document.querySelector('[data-sap-ss-results]'), last = null, rows = [], lastMeta = {};
		var titles = { research: 'تحقیق کلمه', bulk: 'حجم دسته‌ای', analyzer: 'آنالیز کلمات سایت', compare: 'مقایسه سایت', rp_suggest: 'پیشنهاد رسانه رپورتاژ', rp_check: 'جزئیات رسانه', rp_seller: 'آنالیز فروشنده رپورتاژ', rp_buyer: 'آنالیز خریدار رپورتاژ', rt_status: 'وضعیت تغییر رتبه‌ها', rt_history: 'تاریخچه رتبه کلمات', rt_projects: 'پروژه‌های ردیاب رتبه' };
		var labels = { Domain: 'دامنه', Score: 'امتیاز', Count: 'تعداد', RankAvg: 'میانگین رتبه', Price: 'قیمت (تومان)', Ranks: 'رتبه‌ها', FromUrl: 'صفحه مبدأ', AnchorText: 'انکر', ToUrl: 'مقصد', ToHost: 'دامنه مقصد', Follow: 'فالو', NoFollow: 'نوفالو', All: 'همه', Url: 'آدرس', InLinkCount: 'لینک ورودی', OutDomainCount: 'دامنه خروجی', OutLinkCount: 'لینک خروجی', PublishDate: 'تاریخ انتشار', Title: 'عنوان', FromHost: 'رسانه', Keyword: 'کلمه', Rank: 'رتبه', Word: 'کلمه', SearchVolume: 'حجم', Page: 'صفحه', Anchor: 'انکر', Host: 'دامنه' };
		var fa = function (v) { return (v === null || v === undefined || v === '') ? '—' : (typeof v === 'number' ? v.toLocaleString('fa-IR') : (typeof v === 'boolean' ? (v ? '✓' : '—') : esc(String(v)))); };
		var cell = function (v) {
			if (Array.isArray(v)) { return v.map(function (x) { return typeof x === 'object' ? esc((x.Keyword || x.keyword || '') + (x.Rank ? ' (' + x.Rank + ')' : '')) : esc(String(x)); }).join('، '); }
			if (v && typeof v === 'object') { return esc(JSON.stringify(v)); }
			if (typeof v === 'string' && /^https?:\/\//.test(v)) { return '<a href="' + esc(v) + '" target="_blank" rel="noopener" dir="ltr">' + esc(v.replace(/^https?:\/\//, '').slice(0, 60)) + '</a>'; }
			return fa(v);
		};
		var spark = function (data, lbls) {
			if (!data || data.length < 2) { return ''; }
			var max = Math.max.apply(null, data) || 1, w = 220, h = 46, step = w / (data.length - 1);
			var pts = data.map(function (v, i) { return (w - i * step).toFixed(1) + ',' + (h - 4 - (v / max) * (h - 10)).toFixed(1); }).join(' ');
			return '<svg class="sap-spark" viewBox="0 0 ' + w + ' ' + h + '" width="' + w + '" height="' + h + '"><polyline points="' + pts + '" fill="none" stroke="#2563EB" stroke-width="2"/></svg><small class="sap-muted">' + esc((lbls || [])[0] || '') + ' ← ' + esc((lbls || [])[data.length - 1] || '') + '</small>';
		};
		var extra = function (tool, m) {
			var h = '';
			if (tool === 'research' && m) {
				h += '<div class="sap-ss-kpis"><div><span>حجم «' + esc(m.keyword || '') + '»</span><b>' + fa(m.volume) + '</b></div><div><span>حجم کل خوشه</span><b>' + fa(m.all) + '</b></div><div><span>تغییر</span><b class="' + (m.change > 0 ? 'sap-ok' : (m.change < 0 ? 'sap-bad' : '')) + '">' + (m.change === null || m.change === undefined ? '—' : (m.change > 0 ? '+' : '') + Number(m.change).toLocaleString('fa-IR') + '٪') + '</b></div><div class="is-chart"><span>روند ماهانه</span>' + spark(m.chart && m.chart.data, m.chart && m.chart.labels) + '</div></div>';
			}
			if (m && m.ctr && m.ctr.length) {
				h += '<p class="sap-label">سهم کلیک دامنه‌ها</p><div class="sap-kw-chips">' + m.ctr.map(function (c) { return '<span>' + esc(c[0]) + '</span>'; }).join('') + '</div>';
			}
			if (tool === 'analyzer' && m) {
				if (m.pages && m.pages.length) {
					h += '<p class="sap-label">صفحات برتر ' + esc(m.domain || '') + '</p><div class="sap-table-wrap"><table class="sap-table is-compact"><thead><tr><th>صفحه</th><th>میانگین رتبه</th><th>تعداد کلمه</th></tr></thead><tbody>' + m.pages.slice(0, 15).map(function (p) { return '<tr><td dir="ltr">' + esc(p.page) + '</td><td>' + fa(p.avg) + '</td><td>' + fa(p.count) + '</td></tr>'; }).join('') + '</tbody></table></div>';
				}
				if (m.related && m.related.length) {
					h += '<p class="sap-label">سایت‌های مرتبط (برای مقایسه کلیک کنید)</p><div class="sap-kw-chips">' + m.related.map(function (d) { return '<button type="button" class="sap-chip" data-sap-ss-compare="' + esc(d) + '">' + esc(d) + '</button>'; }).join('') + '</div>';
				}
			}
			if (tool === 'rt_status' && m) {
				var dist = function (o) { return Object.keys(o || {}).map(function (k) { return '<span>' + esc(k) + ': <b>' + fa(o[k]) + '</b></span>'; }).join(''); };
				h += '<div class="sap-kw-chips">' + dist(m.changes) + '</div><div class="sap-kw-chips">' + dist(m.distribution) + '</div>';
			}
			return h;
		};
		var render = function (res) {
			rows = res.rows || []; lastMeta = res.meta || {};
			var tool = last.tool, head = '', body = '';
			box.hidden = false;
			box.querySelector('[data-sap-ss-title]').textContent = titles[tool] + ' — ' + rows.length.toLocaleString('fa-IR') + ' ردیف';
			box.querySelector('[data-sap-ss-meta]').textContent = (res.cached ? 'از کش (بدون مصرف سهمیه) · ' : 'دریافت تازه · ') + (res.left !== undefined ? 'باقی‌مانده امروز: ' + res.left.toLocaleString('fa-IR') : '');
			box.querySelector('[data-sap-ss-extra]').innerHTML = extra(tool, lastMeta);
			box.querySelector('.sap-ss-use').hidden = ['research', 'bulk', 'analyzer', 'compare'].indexOf(tool) === -1;
			if (tool === 'research' || tool === 'bulk' || tool === 'analyzer') {
				head = '<tr><th></th><th>کلمه</th><th>حجم جستجو</th><th>رقابت</th>' + (tool === 'analyzer' ? '<th>صفحه</th>' : '') + '</tr>';
				rows.forEach(function (r, i) { body += '<tr><td><input type="checkbox" data-i="' + i + '"></td><td>' + esc(r.word) + '</td><td>' + fa(r.volume) + '</td><td>' + esc(r.competition || '') + '</td>' + (tool === 'analyzer' ? '<td dir="ltr" class="sap-small">' + esc(r.page || '') + '</td>' : '') + '</tr>'; });
			} else if (tool === 'compare') {
				head = '<tr><th></th><th>کلمه</th><th>رتبه ما</th><th>رتبه رقیب</th><th>فاصله</th><th>حجم</th></tr>';
				rows.forEach(function (r, i) { body += '<tr><td><input type="checkbox" data-i="' + i + '"></td><td>' + esc(r.word) + '</td><td>' + fa(r.rank1 || '') + '</td><td>' + fa(r.rank2 || '') + '</td><td>' + fa(r.diff || '') + '</td><td>' + fa(r.volume) + '</td></tr>'; });
			} else if (tool === 'rt_status') {
				head = '<tr><th>کلمه</th><th>رتبه</th><th>تغییر</th><th>بهترین</th><th>میانگین</th><th>حجم</th><th>صفحه</th></tr>';
				rows.sort(function (a, b) { return b.rank_change - a.rank_change; });
				rows.forEach(function (r) {
					var d = r.rank_change; // positive = rank number grew = dropped.
					body += '<tr><td>' + esc(r.keyword) + '</td><td>' + fa(r.rank || '') + '</td><td class="' + (d > 0 ? 'sap-bad' : (d < 0 ? 'sap-ok' : '')) + '">' + (d > 0 ? '▼ ' : (d < 0 ? '▲ ' : '')) + fa(Math.abs(d) || '') + '</td><td>' + fa(r.best_rank || '') + '</td><td>' + fa(r.avg_rank || '') + '</td><td>' + fa(r.volume) + '</td><td dir="ltr" class="sap-small">' + esc((r.target_url || '').replace(/^https?:\/\/[^/]+/, '')) + '</td></tr>';
				});
			} else if (tool === 'rt_history') {
				rows.sort(function (a, b) { return (b.start_rank - b.end_rank) - (a.start_rank - a.end_rank); });
				head = '<tr><th>کلمه</th><th>رتبه اول دوره</th><th>رتبه آخر</th><th>تغییر</th><th>بهترین</th><th>حجم</th><th>صفحه</th></tr>';
				rows.forEach(function (r) {
					var d = r.start_rank && r.end_rank ? r.start_rank - r.end_rank : 0;
					body += '<tr><td>' + esc(r.keyword) + '</td><td>' + fa(r.start_rank || '') + '</td><td>' + fa(r.end_rank || '') + '</td><td class="' + (d > 0 ? 'sap-ok' : (d < 0 ? 'sap-bad' : '')) + '">' + (d > 0 ? '▲ ' : (d < 0 ? '▼ ' : '')) + fa(Math.abs(d) || '') + '</td><td>' + fa(r.best_rank || '') + '</td><td>' + fa(r.volume) + '</td><td dir="ltr" class="sap-small">' + esc((r.target_url || '').replace(/^https?:\/\/[^/]+/, '')) + '</td></tr>';
				});
			} else if (tool === 'rt_projects') {
				head = '<tr><th>شناسه</th><th>نام</th><th>دامنه</th><th>کلمات</th><th>فعال</th></tr>';
				rows.forEach(function (r) { body += '<tr><td>' + fa(r.id) + '</td><td>' + esc(r.name) + '</td><td dir="ltr">' + esc(r.domain) + '</td><td>' + fa(r.keywords) + '</td><td>' + (r.active ? '✓' : '—') + '</td></tr>'; });
			} else {
				// Reportage tools: columns from the data itself.
				var keys = [];
				rows.slice(0, 20).forEach(function (r) { Object.keys(r).forEach(function (k) { if (keys.indexOf(k) === -1) { keys.push(k); } }); });
				head = '<tr>' + keys.map(function (k) { return '<th>' + esc(labels[k] || k) + '</th>'; }).join('') + (tool === 'rp_suggest' ? '<th></th>' : '') + '</tr>';
				rows.forEach(function (r) {
					body += '<tr>' + keys.map(function (k) { return '<td class="sap-small">' + cell(r[k]) + '</td>'; }).join('') + (tool === 'rp_suggest' ? '<td><button type="button" class="sap-btn is-small" data-sap-ss-check="' + esc(r.Domain || '') + '">جزئیات</button></td>' : '') + '</tr>';
				});
			}
			box.querySelector('[data-sap-ss-head]').innerHTML = head;
			box.querySelector('[data-sap-ss-body]').innerHTML = body || '<tr><td colspan="8" class="sap-muted">داده‌ای نبود.</td></tr>';
			box.scrollIntoView({ behavior: 'smooth', block: 'start' });
		};
		var run = function (tool, params, force, btn) {
			last = { tool: tool, params: params };
			if (btn) { busy(btn); }
			post('ss/' + pid + '/tool', { tool: tool, params: params, force: force ? 1 : 0 }).then(render).catch(function (e) {
				// Keep the error on screen (a toast disappears before it can be read).
				box.hidden = false;
				box.querySelector('[data-sap-ss-title]').textContent = titles[tool] + ' — ناموفق';
				box.querySelector('[data-sap-ss-meta]').textContent = '';
				box.querySelector('[data-sap-ss-extra]').innerHTML = '<div class="sap-alert is-bad"><div><b>سئو سیگنال جواب نداد</b><br>' + esc(e.message) + '<br><small>برای دیدن علت دقیق، «تست اتصال سئو سیگنال» را در بالای همین صفحه بزنید.</small></div></div>';
				box.querySelector('.sap-ss-use').hidden = true;
				box.querySelector('[data-sap-ss-head]').innerHTML = '';
				box.querySelector('[data-sap-ss-body]').innerHTML = '';
				box.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}).then(function () { if (btn) { idle(btn); } });
		};
		var tst = document.querySelector('[data-sap-ss-test]');
		if (tst) {
			tst.addEventListener('click', function () {
				var out = document.querySelector('[data-sap-ss-test-out]');
				busy(tst, 'در حال تست…');
				post('ss/' + tst.getAttribute('data-sap-ss-test') + '/test').then(function (j) {
					out.hidden = false;
					out.innerHTML = '<ol class="sap-ss-steps">' + j.steps.map(function (s) {
						var cls = s.ok === true ? 'is-done' : (s.ok === false ? 'is-todo is-bad' : 'is-info');
						return '<li class="' + cls + '"><i>' + (s.ok === true ? '✓' : (s.ok === false ? '!' : 'i')) + '</i><div><b>' + esc(s.name) + '</b><p class="sap-small">' + esc(s.msg) + '</p></div></li>';
					}).join('') + '</ol>';
					toast(j.ok ? 'اتصال سئو سیگنال سالم است.' : 'اتصال مشکل دارد؛ جزئیات زیر دکمه.', !j.ok);
				}).catch(function (e) { toast(e.message, true); }).then(function () { idle(tst); });
			});
		}
		document.querySelectorAll('[data-sap-ss-form]').forEach(function (f) {
			f.addEventListener('submit', function (ev) {
				ev.preventDefault();
				var params = {}; new FormData(f).forEach(function (v, k) { params[k] = v; });
				var sub = ev.submitter;
				var tool = sub && sub.getAttribute('data-sap-ss-alt') ? sub.getAttribute('data-sap-ss-alt') : f.getAttribute('data-sap-ss-form');
				run(tool, params, false, sub || f.querySelector('[type=submit]'));
			});
		});
		document.addEventListener('click', function (ev) {
			var r = ev.target.closest('[data-sap-ss-run]');
			var adv = ev.target.closest('[data-sap-ss-advise]');
			var use = ev.target.closest('[data-sap-ss-use]');
			var chk = ev.target.closest('[data-sap-ss-check]');
			var cmp = ev.target.closest('[data-sap-ss-compare]');
			if (r) { var d = JSON.parse(r.getAttribute('data-sap-ss-run')); run(d.tool, d.params, false, r); }
			if (chk) { run('rp_check', { domain: chk.getAttribute('data-sap-ss-check'), keywords: (lastMeta.keywords || []).join('\n') }, false, chk); }
			if (cmp) { var cf = document.querySelector('form[data-sap-ss-form="compare"]'); cf.querySelector('[name=site2]').value = cmp.getAttribute('data-sap-ss-compare'); cf.scrollIntoView({ behavior: 'smooth', block: 'center' }); toast('دامنه در فرم «مقایسه دو سایت» قرار گرفت (۲ واحد)'); }
			if (ev.target.closest('[data-sap-ss-refresh]') && last) { if (window.confirm('درخواست تازه از سهمیه روزانه مصرف می‌کند. ادامه؟')) { run(last.tool, last.params, true, ev.target); } }
			var lnk = ev.target.closest('[data-sap-ss-link]');
			if (lnk) {
				busy(lnk, 'در حال جستجو در ردیاب…');
				post('ss/' + lnk.getAttribute('data-sap-ss-link') + '/link').then(function (j) { toast(j.msg); setTimeout(function () { location.reload(); }, 1600); }).catch(function (e) { toast(e.message, true); idle(lnk); });
			}
			var cpf = ev.target.closest('[data-sap-copy-from]');
			if (cpf) {
				var src = document.querySelector(cpf.getAttribute('data-sap-copy-from'));
				(navigator.clipboard ? navigator.clipboard.writeText(src.value) : Promise.reject()).then(function () { toast('کپی شد'); }).catch(function () { src.select(); document.execCommand('copy'); toast('کپی شد'); });
			}
			if (adv) {
				busy(adv, 'در حال بررسی داده‌ها…');
				post('ss/' + adv.getAttribute('data-sap-ss-advise') + '/advise').then(function () { location.reload(); }).catch(function (e) { toast(e.message, true); idle(adv); });
			}
			if (use) {
				var picked = Array.prototype.map.call(box.querySelectorAll('[data-i]:checked'), function (c) { return rows[+c.getAttribute('data-i')]; });
				var action = use.getAttribute('data-sap-ss-use');
				if (action === 'volumes' && !picked.length) { picked = rows; }
				var url = (box.querySelector('[data-sap-ss-page]') || {}).value || '';
				if (!picked.length) { toast('ردیف‌ها را انتخاب کنید', true); return; }
				if (action === 'secondary' && !url) { toast('صفحه را انتخاب کنید', true); return; }
				post('ss/' + pid + '/use', { action: action, items: picked, url: url }).then(function (res) { toast(res.msg); }).catch(function (e) { toast(e.message, true); });
			}
		});
		var all = box.querySelector('[data-sap-ss-all]');
		if (all) { all.addEventListener('change', function () { box.querySelectorAll('[data-i]').forEach(function (c) { c.checked = all.checked; }); }); }
	}

	/* ---- Site automation: preview → approve → apply (links, schema, alts, attributes) ---- */
	(function () {
		var modal = document.querySelector('[data-sap-auto-modal]');
		if (!modal) { return; }
		var body = modal.querySelector('[data-sap-auto-body]');
		var current = null; // {pid, kind, res}
		var open = function (html) { body.innerHTML = html; modal.hidden = false; document.body.classList.add('sap-modal-open'); };
		var close = function () { modal.hidden = true; document.body.classList.remove('sap-modal-open'); current = null; };
		var mark = function (s) { return esc(s).replace(/\[\[/g, '<mark>').replace(/\]\]/g, '</mark>'); };
		var where = { featured: 'تصویر شاخص', gallery: 'گالری', content: 'داخل متن' };
		var statusTxt = { 'new': ['جدید', 'is-good'], update: ['به‌روزرسانی', 'is-info'], exists: ['از قبل هست (افزونه دیگر)', 'is-soft'], invalid: ['ناقص', 'is-bad'] };
		var foot = function (label) {
			return '<div class="sap-auto-foot"><button type="button" class="sap-btn is-primary" data-sap-auto-apply>' + label + '</button><button type="button" class="sap-btn is-ghost" data-sap-auto-close>انصراف</button></div>';
		};
		var render = {
			link: function (r) {
				return '<h3 class="sap-modal-t">پیش‌نمایش درج لینک</h3>' +
					'<p class="sap-auto-route"><span>از <b>' + esc(r.source.title) + '</b></span><i>←</i><span>به <b>' + esc(r.target.title) + '</b></span></p>' +
					'<p class="sap-auto-note">' + esc(r.note) + '</p>' +
					'<div class="sap-auto-diff"><div class="is-before"><small>قبل</small><p>' + esc(r.before) + '</p></div><div class="is-after"><small>بعد (لینک: «' + esc(r.anchor) + '»)</small><p>' + mark(r.after) + '</p></div></div>' +
					(r.why ? '<p class="sap-small sap-muted">چرا این جمله: ' + esc(r.why) + '</p>' : '') + foot('تایید و درج روی سایت');
			},
			schema: function (r) {
				var h = '<h3 class="sap-modal-t">اسکیمای «' + esc(r.title) + '» <span class="sap-chip">' + esc(r.nature) + '</span></h3>';
				if (r.other && r.other.length) { h += '<p class="sap-auto-note">روی صفحه از قبل (افزونه‌های دیگر): ' + esc(r.other.join('، ')) + ' — این نوع‌ها تکراری اضافه نمی‌شوند.</p>'; }
				h += '<div class="sap-schema-list">';
				r.blocks.forEach(function (b) {
					var st = statusTxt[b.status] || [b.status, ''];
					var can = b.status === 'new' || b.status === 'update';
					h += '<div class="sap-schema-b ' + (can ? '' : 'is-off') + '"><label class="sap-schema-top">' + (can ? '<input type="checkbox" checked data-type="' + esc(b.type) + '">' : '') + '<b dir="ltr">' + esc(b.type) + '</b><span class="sap-chip ' + st[1] + '">' + st[0] + '</span></label>';
					if (b.why) { h += '<p class="sap-small">' + esc(b.why) + '</p>'; }
					b.errors.forEach(function (e) { h += '<p class="sap-schema-msg is-bad">✗ ' + esc(e) + '</p>'; });
					b.warnings.forEach(function (e) { h += '<p class="sap-schema-msg is-warn">! ' + esc(e) + '</p>'; });
					h += '<details><summary>JSON-LD</summary><pre dir="ltr">' + esc(JSON.stringify(b.json, null, 2)) + '</pre></details></div>';
				});
				h += '</div>';
				if (r.editor === 'elementor') { h += '<p class="sap-small sap-muted">صفحه المنتوری است؛ اسکیما در head چاپ می‌شود و به محتوای المنتور دست نمی‌زند.</p>'; }
				h += '<p class="sap-small"><a href="' + esc(r.test) + '" target="_blank" rel="noopener">بررسی صفحه در ابزار Rich Results گوگل ↗</a></p>';
				return h + (r.token ? foot('تایید و انتشار اسکیما') : '<p class="sap-muted">اسکیمای جدیدی برای افزودن نیست.</p>');
			},
			alt: function (r) {
				var h = '<h3 class="sap-modal-t">متن جایگزین تصاویر «' + esc(r.title) + '»</h3>';
				h += '<p class="sap-auto-note">' + (r.source === 'ai' ? 'نوشته‌شده با هوش مصنوعی از روی محتوای صفحه' : 'بر اساس قواعد (هوش مصنوعی در دسترس نبود)') + (r.keyword ? ' · کلمه کلیدی: «' + esc(r.keyword) + '»' : '') + ' — قبل از تایید می‌توانید هر متن را ویرایش کنید.</p><div class="sap-alt-list">';
				r.images.forEach(function (im) {
					h += '<div class="sap-alt-item ' + (im.change ? '' : 'is-keep') + '"><img src="' + esc(im.src) + '" alt="" loading="lazy"><div><p class="sap-small"><span class="sap-chip is-soft">' + (where[im.where] || im.where) + '</span> <bdi dir="ltr">' + esc(im.file) + '</bdi></p>' +
						'<p class="sap-small sap-muted">فعلی: ' + (im.old ? esc(im.old) : '<em>خالی</em>') + '</p>' +
						(im.change ? '<input type="text" maxlength="160" data-src="' + esc(im.src) + '" value="' + esc(im.new || '') + '"><small class="sap-alt-len"></small>' : '<p class="sap-small">alt فعلی مناسب است و تغییر نمی‌کند.</p>') + '</div></div>';
				});
				return h + '</div>' + (r.elementor ? '<p class="sap-small sap-muted">صفحه المنتوری است: alt در کتابخانه رسانه ثبت می‌شود (تصاویر المنتور از همان استفاده می‌کنند).</p>' : '') + foot('تایید و ثبت روی سایت');
			},
			attr: function (r) {
				var h = '<h3 class="sap-modal-t">ویژگی‌های «' + esc(r.title) + '»</h3><p class="sap-auto-note">' + (r.source === 'ai' ? 'استخراج‌شده از توضیحات با هوش مصنوعی' : 'مقادیری که عیناً در متن بود') + ' — مقادیر را با کلیک انتخاب یا حذف کنید. مقدار تازه فقط با تیک شما به ویژگی سراسری اضافه می‌شود.</p><div class="sap-attr-pick">';
				r.rows.forEach(function (row) {
					h += '<div class="sap-attr-p" data-name="' + esc(row.name) + '"><div class="sap-attr-p-head"><b>' + esc(row.name) + '</b>' + (row.current.length ? '<span class="sap-muted sap-small">فعلی: ' + esc(row.current.join('، ')) + '</span>' : '') + '</div><p>';
					row.allowed.forEach(function (v) { h += '<button type="button" class="sap-pick ' + (row.pick.indexOf(v) >= 0 ? 'is-on' : '') + '" data-v="' + esc(v) + '">' + esc(v) + '</button>'; });
					h += '</p>';
					row['new'].forEach(function (v) { h += '<label class="sap-check sap-attr-new"><input type="checkbox" data-new="' + esc(v) + '"> افزودن مقدار تازه «' + esc(v) + '»</label>'; });
					if (row.evidence) { h += '<small class="sap-muted">' + esc(row.evidence) + '</small>'; }
					h += '</div>';
				});
				return h + '</div>' + foot('تایید و ثبت روی محصول');
			}
		};
		var preview = function (pid, kind, data, btn) {
			if (btn) { btn.disabled = true; btn.classList.add('is-loading'); }
			return post('auto/' + pid + '/' + kind + '-preview', data).then(function (res) {
				current = { pid: pid, kind: kind, res: res };
				open(render[kind](res));
				body.querySelectorAll('.sap-alt-item input').forEach(function (i) { var f = function () { i.nextElementSibling.textContent = i.value.length + ' / ۱۲۵'; i.nextElementSibling.classList.toggle('is-bad', i.value.length > 125); }; i.addEventListener('input', f); f(); });
			}).catch(function (e) { toast(e.message, true); }).then(function () { if (btn) { btn.disabled = false; btn.classList.remove('is-loading'); } });
		};
		var applyData = function () {
			var d = { token: current.res.token };
			if (current.kind === 'alt') {
				d.alts = [];
				body.querySelectorAll('.sap-alt-item input[data-src]').forEach(function (i) { d.alts.push({ src: i.getAttribute('data-src'), alt: i.value.trim() }); });
			} else if (current.kind === 'schema') {
				d.types = [];
				body.querySelectorAll('.sap-schema-b input[data-type]:checked').forEach(function (i) { d.types.push(i.getAttribute('data-type')); });
			} else if (current.kind === 'attr') {
				d.picks = {};
				body.querySelectorAll('.sap-attr-p').forEach(function (p) {
					var vals = [];
					p.querySelectorAll('.sap-pick.is-on').forEach(function (b) { vals.push(b.getAttribute('data-v')); });
					p.querySelectorAll('input[data-new]:checked').forEach(function (c) { vals.push(c.getAttribute('data-new')); });
					d.picks[p.getAttribute('data-name')] = vals;
				});
			}
			return d;
		};
		document.addEventListener('submit', function (ev) {
			var f = ev.target.closest('[data-sap-auto-form]');
			if (!f) { return; }
			ev.preventDefault();
			var data = {};
			new FormData(f).forEach(function (v, k) { data[k] = v; });
			preview(f.getAttribute('data-project'), f.getAttribute('data-sap-auto-form'), data, f.querySelector('[type=submit]'));
		});
		document.addEventListener('click', function (ev) {
			var pv = ev.target.closest('[data-sap-auto-preview]');
			var ap = ev.target.closest('[data-sap-auto-apply]');
			var rb = ev.target.closest('[data-sap-auto-rollback]');
			var pk = ev.target.closest('.sap-pick');
			var op = ev.target.closest('[data-sap-attr-open]');
			if (ev.target.closest('[data-sap-auto-close]') || ev.target === modal) { close(); return; }
			if (pk && !pk.closest('[data-sap-attr-bulk-out]')) { pk.classList.toggle('is-on'); return; }
			if (op) { var form = op.parentNode.querySelector('.sap-attr-desc'); form.hidden = !form.hidden; if (!form.hidden) { form.querySelector('textarea').focus(); } return; }
			if (pv) {
				var d = {};
				['source', 'target', 'anchor', 'finding_id', 'url'].forEach(function (k) { if (pv.hasAttribute('data-' + k)) { d[k] = pv.getAttribute('data-' + k); } });
				preview(pv.getAttribute('data-project'), pv.getAttribute('data-sap-auto-preview'), d, pv);
				return;
			}
			if (ap && current) {
				ap.disabled = true; ap.classList.add('is-loading');
				post('auto/' + current.pid + '/apply', applyData()).then(function (res) {
					toast(res.msg); close(); setTimeout(function () { location.reload(); }, 900);
				}).catch(function (e) { toast(e.message, true); ap.disabled = false; ap.classList.remove('is-loading'); });
				return;
			}
			if (rb) {
				if (!window.confirm('این تغییر روی سایت به حالت قبل برگردد؟')) { return; }
				rb.disabled = true;
				var doRb = function (force) {
					return post('auto/' + rb.getAttribute('data-project') + '/rollback', { ref: rb.getAttribute('data-sap-auto-rollback'), force: force ? 1 : 0 }).then(function (res) { toast(res.msg); setTimeout(function () { location.reload(); }, 800); });
				};
				doRb(false).catch(function (e) {
					// Edited on the site since: only with an explicit second confirmation.
					if (e.code === 'sap_rb_changed' && window.confirm(e.message + '\n\nبرگرداندن به‌هرحال؟')) { return doRb(true); }
					throw e;
				}).catch(function (e) { toast(e.message, true); rb.disabled = false; });
			}
			var vf = ev.target.closest('[data-sap-auto-verify]');
			if (vf) {
				vf.disabled = true; vf.classList.add('is-loading');
				post('auto/' + vf.getAttribute('data-project') + '/verify', { ref: vf.getAttribute('data-sap-auto-verify') }).then(function (res) { toast(res.msg, res.state !== 'live'); setTimeout(function () { location.reload(); }, 1600); }).catch(function (e) { toast(e.message, true); vf.disabled = false; vf.classList.remove('is-loading'); });
			}
		});
		document.addEventListener('keydown', function (ev) { if ('Escape' === ev.key && !modal.hidden) { close(); } });
		/* Autopilot queue: review a prepared change in the same preview modal */
		document.addEventListener('click', function (ev) {
			var rv = ev.target.closest('[data-sap-ap-review]');
			if (!rv) { return; }
			var view = JSON.parse(rv.getAttribute('data-view') || '{}');
			current = { pid: rv.getAttribute('data-project'), kind: rv.getAttribute('data-sap-ap-review'), res: view };
			open(render[current.kind](view));
			body.querySelectorAll('.sap-alt-item input').forEach(function (i) { var f = function () { i.nextElementSibling.textContent = i.value.length + ' / ۱۲۵'; }; i.addEventListener('input', f); f(); });
		});

		/* Attribute set editor */
		var setBox = document.querySelector('[data-sap-attr-set]');
		if (setBox) {
			var rows = setBox.querySelector('[data-sap-attr-rows]');
			var rowHtml = function (a) {
				return '<div class="sap-attr-row"><input type="text" data-f="name" value="' + esc(a.name || '') + '" placeholder="نام ویژگی (مثلاً رنگ)"><input type="text" data-f="slug" value="' + esc(a.slug || '') + '" dir="ltr" placeholder="slug (color)"><textarea data-f="values" rows="2" placeholder="مقادیر مجاز، با ویرگول یا خط جدا">' + esc((a.values || []).join('، ')) + '</textarea><label class="sap-check"><input type="checkbox" data-f="filter"' + (a.filter === false ? '' : ' checked') + '> فیلتر</label><button type="button" class="sap-icon-btn" data-sap-attr-del title="حذف">×</button></div>';
			};
			var collect = function () {
				var out = [];
				rows.querySelectorAll('.sap-attr-row').forEach(function (r) {
					var name = r.querySelector('[data-f=name]').value.trim();
					if (!name) { return; }
					out.push({ name: name, slug: r.querySelector('[data-f=slug]').value.trim(), values: r.querySelector('[data-f=values]').value, filter: r.querySelector('[data-f=filter]').checked });
				});
				return out;
			};
			var pid = setBox.getAttribute('data-project');
			setBox.addEventListener('click', function (ev) {
				var del = ev.target.closest('[data-sap-attr-del]');
				var sv = ev.target.closest('[data-sap-attr-save]');
				var sg = ev.target.closest('[data-sap-attr-suggest]');
				if (del) { del.closest('.sap-attr-row').remove(); return; }
				if (ev.target.closest('[data-sap-attr-add]')) { rows.insertAdjacentHTML('beforeend', rowHtml({})); rows.lastElementChild.querySelector('input').focus(); return; }
				if (sg) {
					sg.disabled = true; sg.classList.add('is-loading');
					post('auto/' + pid + '/attr-suggest', { cat: setBox.getAttribute('data-cat') }).then(function (res) {
						rows.innerHTML = res.attrs.map(rowHtml).join('');
						toast('پیشنهاد آماده شد؛ بررسی و ویرایش کنید، بعد ذخیره کنید.');
					}).catch(function (e) { toast(e.message, true); }).then(function () { sg.disabled = false; sg.classList.remove('is-loading'); });
					return;
				}
				if (sv) {
					var attrs = collect();
					var sync = sv.getAttribute('data-sap-attr-save') === '1';
					if (sync && !window.confirm('ویژگی‌ها به‌صورت ویژگی سراسری در ووکامرس سایت ساخته شوند؟')) { return; }
					sv.disabled = true; sv.classList.add('is-loading');
					post('auto/' + pid + '/attr-set', { cat: setBox.getAttribute('data-cat'), cat_name: setBox.getAttribute('data-cat_name'), attrs: attrs, sync: sync }).then(function (res) {
						toast(res.msg); setTimeout(function () { location.reload(); }, 900);
					}).catch(function (e) { toast(e.message, true); sv.disabled = false; sv.classList.remove('is-loading'); });
				}
			});
		}

		/* Bulk: extract the attributes of several products, review, approve all */
		var bulk = document.querySelector('[data-sap-attr-bulk]');
		if (bulk) {
			bulk.addEventListener('click', function () {
				var urls = JSON.parse(bulk.getAttribute('data-urls') || '[]');
				var pid = bulk.getAttribute('data-project');
				var out = document.querySelector('[data-sap-attr-bulk-out]');
				var items = [];
				bulk.disabled = true;
				out.innerHTML = '<div class="sap-serp-progress"><i style="width:3%"></i><span>استخراج ویژگی‌ها…</span></div><div class="sap-bulk-list"></div>';
				var list = out.querySelector('.sap-bulk-list');
				var bar = out.querySelector('.sap-serp-progress');
				var next = function (i) {
					if (i >= urls.length) {
						bar.querySelector('span').textContent = items.length + ' محصول آماده تایید است. مقادیر را بررسی کنید.';
						if (items.length) { list.insertAdjacentHTML('afterend', '<div class="sap-actions"><button type="button" class="sap-btn is-primary" data-sap-bulk-apply>تایید و ثبت همه موارد تیک‌خورده</button></div>'); }
						return;
					}
					bar.querySelector('span').textContent = 'استخراج ویژگی‌ها (' + (i + 1) + ' از ' + urls.length + ')…';
					bar.querySelector('i').style.width = Math.round(100 * i / urls.length) + '%';
					post('auto/' + pid + '/attr-preview', { url: urls[i], cat: bulk.getAttribute('data-cat') }).then(function (res) {
						var picked = res.rows.filter(function (r) { return r.pick.length; });
						items.push(res);
						list.insertAdjacentHTML('beforeend', '<label class="sap-bulk-item' + (picked.length ? '' : ' is-empty') + '"><input type="checkbox" data-token="' + esc(res.token) + '"' + (picked.length ? ' checked' : '') + '><div><b>' + esc(res.title) + '</b><p>' + (picked.length ? picked.map(function (r) { return '<span class="sap-chip is-soft">' + esc(r.name) + ': ' + esc(r.pick.join('، ')) + '</span>'; }).join(' ') : '<span class="sap-muted sap-small">از متن محصول مقداری پیدا نشد؛ توضیحات را دستی بدهید.</span>') + '</p></div></label>');
						list.lastElementChild._picks = res.rows.reduce(function (o, r) { o[r.name] = r.pick; return o; }, {});
					}).catch(function (e) {
						list.insertAdjacentHTML('beforeend', '<p class="sap-schema-msg is-bad">' + esc(urls[i]) + ': ' + esc(e.message) + '</p>');
					}).then(function () { next(i + 1); });
				};
				next(0);
			});
			document.addEventListener('click', function (ev) {
				var ba = ev.target.closest('[data-sap-bulk-apply]');
				if (!ba) { return; }
				var boxes = Array.prototype.slice.call(document.querySelectorAll('.sap-bulk-item input[data-token]:checked'));
				var pid = bulk.getAttribute('data-project');
				var ok = 0;
				ba.disabled = true;
				var run = function (i) {
					if (i >= boxes.length) { toast(ok + ' محصول روی سایت به‌روز شد.'); setTimeout(function () { location.reload(); }, 1200); return; }
					ba.textContent = 'ثبت ' + (i + 1) + ' از ' + boxes.length + '…';
					var item = boxes[i].closest('.sap-bulk-item');
					post('auto/' + pid + '/apply', { token: boxes[i].getAttribute('data-token'), picks: item._picks }).then(function () { ok++; item.classList.add('is-done'); }).catch(function (e) { item.classList.add('is-err'); toast(e.message, true); }).then(function () { run(i + 1); });
				};
				run(0);
			});
		}
	})();

	/* ---- Project memory ---- */
	(function () {
		var form = document.querySelector('[data-sap-mem-form]');
		if (form) {
			form.addEventListener('submit', function (ev) {
				ev.preventDefault();
				var d = {}; new FormData(form).forEach(function (v, k) { d[k] = v; });
				var b = form.querySelector('[type=submit]'); b.disabled = true;
				post(form.getAttribute('data-project') + '/memory', d).then(function (res) { toast(res.msg); setTimeout(function () { location.reload(); }, 700); }).catch(function (e) { toast(e.message, true); b.disabled = false; });
			});
		}
		document.addEventListener('click', function (ev) {
			var m = ev.target.closest('[data-sap-mem]');
			if (!m) { return; }
			var act = m.getAttribute('data-sap-mem');
			if ('delete' === act && !window.confirm('این مورد از حافظه پروژه حذف شود؟')) { return; }
			post(m.getAttribute('data-project') + '/memory/' + m.getAttribute('data-id') + '/' + act, {}).then(function (res) { toast(res.msg); setTimeout(function () { location.reload(); }, 500); }).catch(function (e) { toast(e.message, true); });
		});
	})();

	/* ---- Chat with data ---- */
	(function () {
		var box = document.querySelector('[data-sap-chat]');
		if (!box) { return; }
		var pid = box.getAttribute('data-project');
		var labels = JSON.parse(box.getAttribute('data-labels') || '{}');
		var log = box.querySelector('[data-sap-chat-log]');
		var form = box.querySelector('[data-sap-chat-form]');
		var ta = form.querySelector('textarea');
		var welcome = box.querySelector('[data-sap-chat-welcome]');
		var busy = false;
		var acts = []; // Suggested tasks of the answers, by index.
		var fmt = function (t) {
			var lines = esc(t || '').split(/\n/);
			var out = '', list = false;
			lines.forEach(function (l) {
				l = l.replace(/\*\*(.+?)\*\*/g, '<b>$1</b>');
				if (/^\s*[-•]\s+/.test(l)) { if (!list) { out += '<ul>'; list = true; } out += '<li>' + l.replace(/^\s*[-•]\s+/, '') + '</li>'; return; }
				if (list) { out += '</ul>'; list = false; }
				if (l.trim()) { out += '<p>' + l + '</p>'; }
			});
			return out + (list ? '</ul>' : '');
		};
		var chart = function (c) {
			var W = 560, H = 190, P = 28, n = c.labels.length, all = [];
			c.series.forEach(function (s) { s.values.forEach(function (v) { if (v !== null && !isNaN(v)) { all.push(+v); } }); });
			if (!all.length) { return ''; }
			var mn = Math.min.apply(null, all), mx = Math.max.apply(null, all);
			if (c.type === 'bar') { mn = Math.min(0, mn); }
			if (mx === mn) { mx = mn + 1; }
			var colors = ['#2563EB', '#F59E0B', '#16A34A'];
			var y = function (v) { var r = (v - mn) / (mx - mn); return c.reverse ? P + r * (H - 2 * P) : H - P - r * (H - 2 * P); };
			var x = function (i) { return P + (n === 1 ? (W - 2 * P) / 2 : i * (W - 2 * P) / (n - 1)); };
			var svg = '<svg class="sap-chart" viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none">';
			[0, 0.5, 1].forEach(function (r) { var yy = P + r * (H - 2 * P); svg += '<line class="sap-chart-grid" x1="' + P + '" x2="' + (W - P) + '" y1="' + yy + '" y2="' + yy + '"/>'; });
			if (c.type === 'bar') {
				var groups = c.series.length, bw = Math.max(4, (W - 2 * P) / n / (groups + 1));
				c.series.forEach(function (s, si) {
					s.values.forEach(function (v, i) {
						var cx = P + (i + 0.5) * (W - 2 * P) / n - groups * bw / 2 + si * bw, top = y(Math.max(+v, 0)), base = y(Math.max(mn, 0));
						svg += '<rect x="' + cx + '" y="' + Math.min(top, base) + '" width="' + (bw - 2) + '" height="' + Math.max(1, Math.abs(base - top)) + '" rx="3" fill="' + colors[si % 3] + '"><title>' + esc(c.labels[i] + ': ' + v) + '</title></rect>';
					});
				});
			} else {
				c.series.forEach(function (s, si) {
					var d = '';
					s.values.forEach(function (v, i) { if (v === null || isNaN(v)) { return; } d += (d ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(+v).toFixed(1); });
					svg += '<path d="' + d + '" fill="none" stroke="' + colors[si % 3] + '" stroke-width="2.2" vector-effect="non-scaling-stroke" stroke-linejoin="round"/>';
				});
			}
			[0, Math.floor((n - 1) / 2), n - 1].filter(function (v, i, a) { return a.indexOf(v) === i; }).forEach(function (i) { svg += '<text class="sap-chart-axis" x="' + x(i) + '" y="' + (H - 6) + '" text-anchor="middle">' + esc(String(c.labels[i] || '')) + '</text>'; });
			svg += '<text class="sap-chart-axis" x="2" y="' + (c.reverse ? H - P : P) + '">' + esc(String(Math.round(mx * 10) / 10)) + '</text><text class="sap-chart-axis" x="2" y="' + (c.reverse ? P : H - P) + '">' + esc(String(Math.round(mn * 10) / 10)) + '</text></svg>';
			var legend = '<p class="sap-chart-legend">' + c.series.map(function (s, si) { return '<span><i style="background:' + colors[si % 3] + '"></i>' + esc(s.name) + '</span>'; }).join('') + '</p>';
			return '<div class="sap-chat-chart">' + (c.title ? '<b>' + esc(c.title) + '</b>' : '') + svg + legend + '</div>';
		};
		var bubble = function (m) {
			if (m.role === 'user') { return '<div class="sap-msg is-user"><p>' + esc(m.text) + '</p></div>'; }
			var h = '<div class="sap-msg is-bot"><span class="sap-chat-bot"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="8" width="16" height="12" rx="3"/><path d="M12 8V4M9 13h.01M15 13h.01"/></svg></span><div class="sap-msg-body">' + fmt(m.text);
			if (m.table && m.table.columns && m.table.columns.length) {
				h += '<div class="sap-table-wrap"><table class="sap-table sap-chat-table">' + (m.table.title ? '<caption>' + esc(m.table.title) + '</caption>' : '') + '<thead><tr>' + m.table.columns.map(function (c) { return '<th>' + esc(c) + '</th>'; }).join('') + '</tr></thead><tbody>' + (m.table.rows || []).map(function (r) { return '<tr>' + r.map(function (c) { return '<td dir="auto">' + esc(String(c)) + '</td>'; }).join('') + '</tr>'; }).join('') + '</tbody></table></div>';
			}
			if (m.chart && m.chart.labels && m.chart.labels.length) { h += chart(m.chart); }
			if (m.actions && m.actions.length) {
				h += '<div class="sap-chat-actions"><b>کارهای پیشنهادی</b>' + m.actions.map(function (a) {
					return '<div class="sap-chat-act"><div><p><b>' + esc(a.title) + '</b></p>' + (a.why ? '<p class="sap-small sap-muted">' + esc(a.why) + '</p>' : '') + '</div><button type="button" class="sap-btn is-soft is-small" data-sap-chat-task="' + (acts.push(a) - 1) + '">+ اکشن پلن</button></div>';
				}).join('') + '</div>';
			}
			h += '<p class="sap-chat-foot">' + (m.tools && m.tools.length ? '<span>داده: ' + m.tools.map(function (t) { return esc(labels[t] || t); }).join('، ') + '</span>' : '') + '<button type="button" class="sap-btn is-ghost is-small" data-sap-chat-remember>ذخیره در حافظه پروژه</button></p>';
			if (m.followups && m.followups.length) { h += '<div class="sap-chat-starts">' + m.followups.map(function (f) { return '<button type="button" class="sap-chip" data-sap-chat-ask="' + esc(f) + '">' + esc(f) + '</button>'; }).join('') + '</div>'; }
			return h + '</div></div>';
		};
		var scroll = function () { log.scrollTop = log.scrollHeight; };
		JSON.parse(box.getAttribute('data-history') || '[]').forEach(function (m) { log.insertAdjacentHTML('beforeend', bubble(m)); });
		log.style.scrollBehavior = 'auto'; scroll(); setTimeout(function () { scroll(); log.style.scrollBehavior = ''; }, 60);
		var ask = function (q) {
			q = (q || '').trim();
			if (!q || busy) { return; }
			busy = true;
			if (welcome) { welcome.hidden = true; }
			box.querySelector('[data-sap-chat-clear]').hidden = false;
			log.insertAdjacentHTML('beforeend', bubble({ role: 'user', text: q }) + '<div class="sap-msg is-bot is-typing" data-sap-typing><span class="sap-chat-bot">…</span><div class="sap-msg-body"><p class="sap-typing"><i></i><i></i><i></i> در حال خواندن داده‌ها و تحلیل…</p></div></div>');
			scroll(); ta.value = '';
			post(pid + '/chat', { message: q }).then(function (res) {
				log.querySelector('[data-sap-typing]').remove();
				log.insertAdjacentHTML('beforeend', bubble(res.reply)); scroll();
			}).catch(function (e) {
				var t = log.querySelector('[data-sap-typing]'); if (t) { t.remove(); }
				log.insertAdjacentHTML('beforeend', '<div class="sap-msg is-bot is-err"><div class="sap-msg-body"><p>' + esc(e.message) + '</p></div></div>');
			}).then(function () { busy = false; ta.focus(); });
		};
		form.addEventListener('submit', function (ev) { ev.preventDefault(); ask(ta.value); });
		ta.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); ask(ta.value); } });
		ta.addEventListener('input', function () { ta.style.height = 'auto'; ta.style.height = Math.min(160, ta.scrollHeight) + 'px'; });
		box.addEventListener('click', function (ev) {
			var a = ev.target.closest('[data-sap-chat-ask]');
			var t = ev.target.closest('[data-sap-chat-task]');
			var r = ev.target.closest('[data-sap-chat-remember]');
			if (a) { ask(a.getAttribute('data-sap-chat-ask')); return; }
			if (t) {
				t.disabled = true;
				post(pid + '/chat/task', acts[+t.getAttribute('data-sap-chat-task')]).then(function (res) { toast(res.msg); t.textContent = '✓ اضافه شد'; }).catch(function (e) { toast(e.message, true); t.disabled = false; });
				return;
			}
			if (r) {
				var msg = r.closest('.sap-msg-body'); var prev = msg.closest('.sap-msg').previousElementSibling;
				var q = prev && prev.classList.contains('is-user') ? prev.textContent : 'از گفتگو با داده‌ها';
				r.disabled = true;
				post(pid + '/memory', { kind: 'note', title: q.slice(0, 200), body: msg.querySelector('p') ? Array.prototype.map.call(msg.querySelectorAll(':scope > p, :scope > ul li'), function (p) { return p.textContent; }).join('\n').slice(0, 2000) : '', source: 'chat' }).then(function (res) { toast(res.msg); r.textContent = '✓ در حافظه'; }).catch(function (e) { toast(e.message, true); r.disabled = false; });
				return;
			}
			if (ev.target.closest('[data-sap-chat-clear]')) {
				post(pid + '/chat/clear', {}).then(function () { location.reload(); });
			}
		});
	})();

	/* ---- GEO: questions, run, audit, llms.txt ---- */
	(function () {
		var form = document.querySelector('[data-sap-geo-prompts]');
		var parse = function (txt) { return txt.split(/\n+/).map(function (l) { var p = l.split('|'); return { q: (p[0] || '').trim(), kw: (p[1] || '').trim() }; }).filter(function (x) { return x.q; }); };
		if (form) {
			var pid = form.getAttribute('data-project');
			var ta = form.querySelector('textarea');
			var save = function () { return post(pid + '/geo/prompts', { prompts: parse(ta.value) }); };
			form.addEventListener('submit', function (ev) { ev.preventDefault(); save().then(function (res) { toast(res.msg); setTimeout(function () { location.reload(); }, 600); }).catch(function (e) { toast(e.message, true); }); });
			form.querySelector('[data-sap-geo-suggest]').addEventListener('click', function (ev) {
				var b = ev.currentTarget; b.disabled = true; b.classList.add('is-loading');
				post(pid + '/geo/suggest', {}).then(function (res) {
					var cur = parse(ta.value).map(function (x) { return x.q; });
					var add = res.prompts.filter(function (p) { return cur.indexOf(p.q) < 0; }).map(function (p) { return p.q + (p.kw ? ' | ' + p.kw : ''); });
					ta.value = (ta.value.trim() ? ta.value.trim() + '\n' : '') + add.join('\n');
					toast(add.length + ' سوال پیشنهاد شد؛ ویرایش و ذخیره کنید.');
				}).catch(function (e) { toast(e.message, true); }).then(function () { b.disabled = false; b.classList.remove('is-loading'); });
			});
			var prog = form.querySelector('[data-sap-geo-progress]');
			form.querySelector('[data-sap-geo-run]').addEventListener('click', function (ev) {
				var b = ev.currentTarget; b.disabled = true;
				prog.hidden = false; prog.querySelector('span').textContent = 'ذخیره سوال‌ها…';
				var step = function (first) {
					post(pid + '/geo/run', { restart: first ? 1 : 0 }).then(function (res) {
						prog.querySelector('span').textContent = res.msg; prog.querySelector('i').style.width = (res.pct || 5) + '%';
						if (res.more) { return step(false); }
						toast(res.msg); setTimeout(function () { location.reload(); }, 900);
					}).catch(function (e) { toast(e.message, true); prog.querySelector('span').textContent = e.message; b.disabled = false; });
				};
				save().then(function () { step(true); }).catch(function (e) { toast(e.message, true); b.disabled = false; });
			});
		}
		document.addEventListener('click', function (ev) {
			var au = ev.target.closest('[data-sap-geo-audit]');
			var ll = ev.target.closest('[data-sap-llms] [data-sap-llms]');
			if (au) {
				au.disabled = true; au.classList.add('is-loading');
				post(au.getAttribute('data-project') + '/geo/audit', {}).then(function (res) { toast(res.msg); setTimeout(function () { location.reload(); }, 600); }).catch(function (e) { toast(e.message, true); au.disabled = false; au.classList.remove('is-loading'); });
				return;
			}
			if (ll) {
				var wrap = ll.closest('section[data-sap-llms]');
				var pid2 = wrap.getAttribute('data-project');
				var text = wrap.querySelector('[data-sap-llms-text]');
				var act = ll.getAttribute('data-sap-llms');
				if (act === 'publish' && !text.value.trim()) { toast('اول پیش‌نویس را بسازید یا متن را بنویسید.', true); return; }
				if (act !== 'build' && !window.confirm(act === 'publish' ? 'llms.txt روی سایت منتشر شود؟' : 'llms.txt به نسخه قبل برگردد؟')) { return; }
				ll.disabled = true; ll.classList.add('is-loading');
				post(pid2 + '/geo/llms', { 'do': act, content: text.value }).then(function (res) {
					if (act === 'build') { text.value = res.content; toast('پیش‌نویس ساخته شد؛ بررسی و ویرایش کنید، بعد منتشر کنید.'); } else { toast(res.msg); }
				}).catch(function (e) { toast(e.message, true); }).then(function () { ll.disabled = false; ll.classList.remove('is-loading'); });
			}
		});
	})();

	/* ---- Experiments ---- */
	(function () {
		var form = document.querySelector('[data-sap-exp-form]');
		if (form) {
			var pid = form.getAttribute('data-project');
			form.addEventListener('submit', function (ev) {
				ev.preventDefault();
				var d = {}; new FormData(form).forEach(function (v, k) { d[k] = v; });
				var b = form.querySelector('[type=submit]'); b.disabled = true; b.classList.add('is-loading');
				post(pid + '/exp', d).then(function (res) { toast(res.msg); setTimeout(function () { location.reload(); }, 700); }).catch(function (e) { toast(e.message, true); b.disabled = false; b.classList.remove('is-loading'); });
			});
			form.querySelector('[data-sap-exp-control]').addEventListener('click', function (ev) {
				var b = ev.currentTarget; b.disabled = true;
				post(pid + '/exp/control', { test: form.test.value }).then(function (res) {
					if (!res.control.length) { toast('صفحه مشابهی پیدا نشد؛ دستی وارد کنید.', true); return; }
					form.control.value = res.control.join('\n'); toast(res.control.length + ' صفحه کنترل مشابه پیشنهاد شد.');
				}).catch(function (e) { toast(e.message, true); }).then(function () { b.disabled = false; });
			});
		}
		document.addEventListener('click', function (ev) {
			var x = ev.target.closest('[data-sap-exp]');
			if (!x) { return; }
			var act = x.getAttribute('data-sap-exp');
			if (act !== 'measure' && !window.confirm(act === 'delete' ? 'این آزمایش حذف شود؟' : 'آزمایش پایان یابد؟')) { return; }
			x.disabled = true; x.classList.add('is-loading');
			post(x.getAttribute('data-project') + '/exp/' + x.getAttribute('data-id') + '/' + act, {}).then(function (res) { toast(res.msg); setTimeout(function () { location.reload(); }, 600); }).catch(function (e) { toast(e.message, true); x.disabled = false; x.classList.remove('is-loading'); });
		});
	})();

	/* ---- Autopilot policy, run, dismiss ---- */
	(function () {
		var form = document.querySelector('[data-sap-ap-form]');
		if (form) {
			form.addEventListener('submit', function (ev) {
				ev.preventDefault();
				var d = { mode: (form.querySelector('[name=mode]:checked') || {}).value || 'off', limit: form.limit.value };
				['schema', 'alts', 'links', 'alerts'].forEach(function (k) { d[k] = form[k].checked ? 1 : 0; });
				post(form.getAttribute('data-project') + '/autopilot', d).then(function (res) { toast(res.msg); setTimeout(function () { location.reload(); }, 600); }).catch(function (e) { toast(e.message, true); });
			});
		}
		document.addEventListener('click', function (ev) {
			var run = ev.target.closest('[data-sap-ap-run]');
			var dis = ev.target.closest('[data-sap-ap-dismiss]');
			if (run) {
				run.disabled = true; run.classList.add('is-loading');
				post(run.getAttribute('data-project') + '/autopilot/run', {}).then(function (res) { toast(res.msg); setTimeout(function () { location.reload(); }, 1500); }).catch(function (e) { toast(e.message, true); run.disabled = false; run.classList.remove('is-loading'); });
			}
			if (dis) {
				dis.disabled = true;
				post(dis.getAttribute('data-project') + '/autopilot/dismiss', { token: dis.getAttribute('data-sap-ap-dismiss') }).then(function (res) { toast(res.msg); dis.closest('li').remove(); }).catch(function (e) { toast(e.message, true); dis.disabled = false; });
			}
		});
	})();

	/* ---- Project transfer: chunked upload → preview → import ---- */
	(function () {
		var box = document.querySelector('[data-sap-transfer]');
		if (!box) { return; }
		var input = box.querySelector('[data-sap-transfer-file]');
		var go = box.querySelector('[data-sap-transfer-go]');
		var prev = box.querySelector('[data-sap-transfer-preview]');
		var prog = box.querySelector('[data-sap-transfer-progress]');
		var out = box.querySelector('[data-sap-transfer-result]');
		var upload = '';
		var CHUNK = 1024 * 1024; // 1 MB parts: below every host's upload limit.
		function bar(pct, text) {
			prog.hidden = false;
			prog.querySelector('i').style.width = Math.max(4, pct) + '%';
			prog.querySelector('span').textContent = text;
		}
		function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
		function send(file, i, n) {
			var fd = new FormData();
			fd.append('upload', upload);
			fd.append('index', i);
			fd.append('chunk', file.slice(i * CHUNK, (i + 1) * CHUNK), 'part');
			return api('transfer/chunk', { method: 'POST', body: fd }).then(function () {
				bar(Math.round((i + 1) / n * 90), 'آپلود فایل: ' + (i + 1).toLocaleString('fa-IR') + ' از ' + n.toLocaleString('fa-IR') + ' بخش');
				return i + 1 < n ? send(file, i + 1, n) : null;
			});
		}
		input.addEventListener('change', function () {
			var file = input.files[0];
			go.disabled = true; prev.hidden = true; out.innerHTML = '';
			if (!file) { return; }
			upload = (Date.now().toString(36) + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2)).replace(/[^a-z0-9]/g, '').slice(0, 24);
			var n = Math.max(1, Math.ceil(file.size / CHUNK));
			bar(2, 'آپلود فایل…');
			send(file, 0, n).then(function () {
				bar(95, 'خواندن فایل…');
				return post('transfer/import', { upload: upload, step: 'preview' });
			}).then(function (res) {
				prog.hidden = true;
				var html = '<p class="sap-small">از <b dir="ltr">' + esc(res.source) + '</b> · نسخه ' + esc(res.version) + ' · ' + esc(res.at) + ' · ' + Number(res.users).toLocaleString('fa-IR') + ' عضو</p><ul>';
				res.projects.forEach(function (p) {
					var c = p.counts || {};
					html += '<li><b>' + esc(p.name) + '</b> <small dir="ltr">' + esc(p.site) + '</small><br><small>' +
						[['tasks', 'کار'], ['findings', 'یافته'], ['activity', 'اقدام در لاگ'], ['focuses', 'حوزه'], ['keymap', 'کلمه در نقشه'], ['datasets', 'داده'], ['ranks', 'رتبه']].map(function (k) {
							return (c[k[0]] || 0).toLocaleString('fa-IR') + ' ' + k[1];
						}).join(' · ') + '</small></li>';
				});
				prev.innerHTML = html + '</ul>';
				prev.hidden = false;
				box.querySelector('[data-sap-transfer-settings-row]').hidden = !res.has_settings;
				go.disabled = false;
			}).catch(function (e) { prog.hidden = true; toast(e.message, true); });
		});
		go.addEventListener('click', function () {
			go.disabled = true;
			bar(96, 'در حال وارد کردن پروژه‌ها… (ممکن است چند دقیقه طول بکشد)');
			post('transfer/import', {
				upload: upload,
				create_users: box.querySelector('[data-sap-transfer-users]').checked ? 1 : 0,
				settings: box.querySelector('[data-sap-transfer-settings]').checked ? 1 : 0
			}).then(function (res) {
				bar(100, res.msg);
				out.innerHTML = '<ul class="sap-transfer-done">' + res.projects.map(function (p) {
					return '<li><a href="' + esc(p.url) + '">' + esc(p.name) + ' ←</a> <small>' + Number(p.tasks).toLocaleString('fa-IR') + ' کار · ' + Number(p.findings).toLocaleString('fa-IR') + ' یافته · ' + Number(p.activity).toLocaleString('fa-IR') + ' اقدام</small></li>';
				}).join('') + '</ul>';
				toast(res.msg);
				input.value = ''; prev.hidden = true;
			}).catch(function (e) { toast(e.message, true); go.disabled = false; prog.hidden = true; });
		});
	})();

	/* ---- «show more» rows in long lists ---- */
	document.addEventListener('click', function (ev) {
		var b = ev.target.closest('[data-sap-more-rows]');
		if (!b) { return; }
		var box = b.parentElement;
		box.querySelectorAll('.is-more[hidden]').forEach(function (li) { li.hidden = false; });
		b.remove();
	});

	/* ---- Profile dialog ---- */
	(function () {
		var dlg = document.querySelector('[data-sap-profile]');
		if (!dlg) { return; }
		document.addEventListener('click', function (ev) {
			if (ev.target.closest('[data-sap-profile-open]')) { ev.preventDefault(); if (dlg.showModal) { dlg.showModal(); } else { dlg.setAttribute('open', ''); } }
			if (ev.target.closest('[data-sap-profile-close]')) { ev.preventDefault(); dlg.close ? dlg.close() : dlg.removeAttribute('open'); }
		});
		dlg.addEventListener('click', function (ev) { if (ev.target === dlg) { dlg.close(); } });
		var inp = dlg.querySelector('[data-sap-avatar-input]');
		var prev = dlg.querySelector('[data-sap-avatar-preview]');
		if (inp && prev) {
			inp.addEventListener('change', function () {
				var f = inp.files[0];
				if (!f) { return; }
				if (f.size > 5 * 1024 * 1024) { toast('حجم تصویر بیشتر از ۵ مگابایت است.', true); inp.value = ''; return; }
				var img = document.createElement('img');
				img.className = 'sap-av';
				img.src = URL.createObjectURL(f);
				prev.innerHTML = '';
				prev.appendChild(img);
			});
		}
	})();

})();
