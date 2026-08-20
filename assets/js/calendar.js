/**
 * 予約カレンダーと仮押さえ.
 *
 * 空き枠を月表示に並べ、枠を選ぶと Ajax で仮押さえを取ってから入力フォームを開く。
 * 仮押さえはサーバー側で SELECT ... FOR UPDATE を含むトランザクションで行うので、
 * 同時に押されても片方しか取れない。
 *
 * 決済への接続（Stripe Checkout）は実装順序 8 で入る。
 */
(function () {
	'use strict';

	var root = document.getElementById('ssb-calendar');

	if (!root || !window.ssbCalendarData) {
		return;
	}

	var data = window.ssbCalendarData;
	var WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'];

	var messageBox = document.getElementById('ssb-calendar-message');
	var booking = document.getElementById('ssb-booking');
	var fieldSlot = document.getElementById('ssb-field-slot-id');
	var fieldToken = document.getElementById('ssb-field-hold-token');
	var slotLabel = document.getElementById('ssb-booking-slot');
	var countdownEl = document.getElementById('ssb-booking-countdown');
	var cancelButton = document.getElementById('ssb-booking-cancel');

	var byDate = {};
	var availableDates = [];
	var view = null;      // { year: 2026, month: 7 }  month は 0 始まり
	var selected = null;  // 'YYYY-MM-DD'
	var hold = null;      // { slotId: 1, token: '...' }
	var deadline = 0;
	var ticker = null;
	var busy = false;
	var submitting = false;

	/* ---------------------------------------------------------------- 汎用 */

	function pad(n) {
		return n < 10 ? '0' + n : String(n);
	}

	function dateKey(year, month, day) {
		return year + '-' + pad(month + 1) + '-' + pad(day);
	}

	function monthKey(year, month) {
		return year + '-' + pad(month + 1);
	}

	function el(tag, className, text) {
		var node = document.createElement(tag);

		if (className) {
			node.className = className;
		}

		if (text !== undefined) {
			node.textContent = text;
		}

		return node;
	}

	function showMessage(text) {
		if (!messageBox) {
			return;
		}

		messageBox.textContent = text;
		messageBox.hidden = !text;
	}

	function post(action, payload) {
		var body = new FormData();

		body.append('action', action);
		body.append('nonce', data.nonce);

		Object.keys(payload || {}).forEach(function (key) {
			body.append(key, payload[key]);
		});

		return fetch(data.ajaxUrl, {
			method: 'POST',
			body: body,
			credentials: 'same-origin'
		}).then(function (response) {
			return response.json();
		});
	}

	/* ------------------------------------------------------------ 枠データ */

	function indexSlots(slots) {
		byDate = {};

		(slots || []).forEach(function (slot) {
			if (!byDate[slot.date]) {
				byDate[slot.date] = [];
			}

			byDate[slot.date].push(slot);
		});

		// 確保中の枠はサーバー側の空き一覧から外れるので、選択中と分かるように戻す。
		if (hold && hold.date) {
			var already = (byDate[hold.date] || []).some(function (slot) {
				return String(slot.id) === String(hold.slotId);
			});

			if (!already) {
				if (!byDate[hold.date]) {
					byDate[hold.date] = [];
				}

				byDate[hold.date].push({
					id: hold.slotId,
					date: hold.date,
					start: hold.start,
					end: hold.end
				});

				byDate[hold.date].sort(function (a, b) {
					return a.start < b.start ? -1 : 1;
				});
			}
		}

		availableDates = Object.keys(byDate).sort();
	}

	function refreshSlots() {
		return post('ssb_refresh_slots', { course_id: data.courseId }).then(function (res) {
			if (!res || !res.success) {
				return;
			}

			indexSlots(res.data.slots);

			// 選択中の日付が無くなっていたら、近い日に寄せる。
			if (!selected || !byDate[selected]) {
				selected = availableDates.filter(function (date) {
					return date.slice(0, 7) === monthKey(view.year, view.month);
				})[0] || availableDates[0] || null;

				if (selected) {
					var parts = selected.split('-');
					view = { year: parseInt(parts[0], 10), month: parseInt(parts[1], 10) - 1 };
				}
			}

			render();
		});
	}

	/* -------------------------------------------------------- 仮押さえと申込 */

	function formatRemaining(ms) {
		var total = Math.max(0, Math.floor(ms / 1000));

		return pad(Math.floor(total / 60)) + ':' + pad(total % 60);
	}

	function stopTicker() {
		if (ticker) {
			clearInterval(ticker);
			ticker = null;
		}
	}

	function startTicker() {
		stopTicker();

		ticker = setInterval(function () {
			var left = deadline - Date.now();

			if (countdownEl) {
				countdownEl.textContent = formatRemaining(left);
			}

			if (left <= 0) {
				stopTicker();
				hold = null;
				closeBooking();
				showMessage('確保の時間が過ぎました。もう一度お選びください。');
				refreshSlots();
			}
		}, 1000);
	}

	function openBooking(result) {
		hold = {
			slotId: result.slot_id,
			token: result.token,
			date: result.date,
			start: result.start,
			end: result.end
		};

		if (fieldSlot) {
			fieldSlot.value = result.slot_id;
		}

		if (fieldToken) {
			fieldToken.value = result.token;
		}

		if (slotLabel) {
			slotLabel.textContent = result.label;
		}

		deadline = Date.now() + (data.holdMinutes || 15) * 60 * 1000;

		if (countdownEl) {
			countdownEl.textContent = formatRemaining(deadline - Date.now());
		}

		startTicker();

		if (booking) {
			booking.hidden = false;
			booking.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}
	}

	function closeBooking() {
		stopTicker();

		if (booking) {
			booking.hidden = true;
		}

		if (fieldSlot) {
			fieldSlot.value = '';
		}

		if (fieldToken) {
			fieldToken.value = '';
		}

		render();
	}

	function releaseHold() {
		if (!hold) {
			return Promise.resolve();
		}

		var current = hold;
		hold = null;

		return post('ssb_release_slot', { slot_id: current.slotId, token: current.token });
	}

	function onSlotClick(event) {
		var slotId = event.currentTarget.dataset.slotId;

		if (busy || !slotId) {
			return;
		}

		// すでに自分が確保している枠なら何もしない。
		if (hold && String(hold.slotId) === String(slotId)) {
			return;
		}

		busy = true;
		showMessage('');

		// すでに別の枠を確保していたら先に返す。
		releaseHold().then(function () {
			return post('ssb_hold_slot', { slot_id: slotId });
		}).then(function (res) {
			busy = false;

			if (res && res.success) {
				openBooking(res.data);
				refreshSlots();
				return;
			}

			closeBooking();
			showMessage((res && res.data && res.data.message) || '枠を確保できませんでした。');
			refreshSlots();
		}).catch(function () {
			busy = false;
			showMessage('通信に失敗しました。時間をおいて再度お試しください。');
		});
	}

	/* ------------------------------------------------------------ 描画 */

	function shiftMonth(step) {
		var month = view.month + step;
		var year = view.year;

		if (month < 0) {
			month = 11;
			year -= 1;
		} else if (month > 11) {
			month = 0;
			year += 1;
		}

		view = { year: year, month: month };

		// 表示月に選択日が無ければ、その月の最初の空き日に移す。
		var prefix = monthKey(view.year, view.month);

		if (!selected || selected.slice(0, 7) !== prefix) {
			selected = availableDates.filter(function (date) {
				return date.slice(0, 7) === prefix;
			})[0] || null;
		}

		render();
	}

	function availableMonths() {
		var months = [];

		availableDates.forEach(function (date) {
			var key = date.slice(0, 7);

			if (months.indexOf(key) === -1) {
				months.push(key);
			}
		});

		return months;
	}

	function renderNav() {
		var months = availableMonths();
		var current = monthKey(view.year, view.month);
		var nav = el('div', 'ssb-calendar__nav');

		var prev = el('button', 'ssb-calendar__navbtn', '‹');
		prev.type = 'button';
		prev.setAttribute('aria-label', '前の月');
		prev.disabled = months.length === 0 || current <= months[0];
		prev.addEventListener('click', function () {
			shiftMonth(-1);
		});

		var next = el('button', 'ssb-calendar__navbtn', '›');
		next.type = 'button';
		next.setAttribute('aria-label', '次の月');
		next.disabled = months.length === 0 || current >= months[months.length - 1];
		next.addEventListener('click', function () {
			shiftMonth(1);
		});

		nav.appendChild(prev);
		nav.appendChild(el('span', 'ssb-calendar__month', view.year + '年' + (view.month + 1) + '月'));
		nav.appendChild(next);

		return nav;
	}

	function renderGrid() {
		var table = el('table', 'ssb-calendar__grid');
		var thead = el('thead');
		var headRow = el('tr');

		WEEKDAYS.forEach(function (label) {
			var th = el('th', null, label);
			th.scope = 'col';
			headRow.appendChild(th);
		});

		thead.appendChild(headRow);
		table.appendChild(thead);

		var tbody = el('tbody');
		var first = new Date(view.year, view.month, 1);
		var daysInMonth = new Date(view.year, view.month + 1, 0).getDate();
		var row = el('tr');
		var blank;

		for (blank = 0; blank < first.getDay(); blank++) {
			row.appendChild(el('td'));
		}

		for (var day = 1; day <= daysInMonth; day++) {
			if (row.children.length === 7) {
				tbody.appendChild(row);
				row = el('tr');
			}

			var key = dateKey(view.year, view.month, day);
			var cell = el('td');
			var slots = byDate[key];

			if (slots && slots.length) {
				var button = el('button', 'ssb-calendar__day is-available');
				button.type = 'button';
				button.dataset.date = key;
				button.appendChild(el('span', 'ssb-calendar__daynum', String(day)));
				button.appendChild(el('span', 'ssb-calendar__count', slots.length + '枠'));

				if (key === selected) {
					button.classList.add('is-selected');
					button.setAttribute('aria-current', 'date');
				}

				button.addEventListener('click', function () {
					selected = this.dataset.date;
					render();
				});

				cell.appendChild(button);
			} else {
				var plain = el('span', 'ssb-calendar__day is-empty');
				plain.appendChild(el('span', 'ssb-calendar__daynum', String(day)));

				if (key === data.today) {
					plain.classList.add('is-today');
				}

				cell.appendChild(plain);
			}

			row.appendChild(cell);
		}

		while (row.children.length < 7) {
			row.appendChild(el('td'));
		}

		tbody.appendChild(row);
		table.appendChild(tbody);

		return table;
	}

	function renderTimes() {
		var box = el('div', 'ssb-calendar__times');

		if (!selected || !byDate[selected]) {
			box.appendChild(el('p', 'ssb-muted', '日付を選ぶと空いている時間が表示されます。'));

			return box;
		}

		var parts = selected.split('-');
		var weekday = WEEKDAYS[new Date(parts[0], parts[1] - 1, parts[2]).getDay()];

		box.appendChild(el(
			'h3',
			'ssb-calendar__times-title',
			parts[0] + '年' + Number(parts[1]) + '月' + Number(parts[2]) + '日（' + weekday + '）'
		));

		var list = el('div', 'ssb-calendar__slots');

		byDate[selected].forEach(function (slot) {
			var button = el('button', 'ssb-calendar__slot', slot.start + '〜' + slot.end);
			button.type = 'button';
			button.dataset.slotId = String(slot.id);

			if (hold && hold.slotId === slot.id) {
				button.classList.add('is-held');
			}

			button.addEventListener('click', onSlotClick);
			list.appendChild(button);
		});

		box.appendChild(list);

		return box;
	}

	function render() {
		root.textContent = '';

		if (!availableDates.length) {
			root.appendChild(el('p', 'ssb-muted', '現在、予約できる枠がありません。'));

			return;
		}

		root.appendChild(renderNav());
		root.appendChild(renderGrid());
		root.appendChild(renderTimes());
	}

	/* ------------------------------------------------------------ 初期化 */

	if (cancelButton) {
		cancelButton.addEventListener('click', function () {
			showMessage('');
			releaseHold().then(function () {
				closeBooking();
				refreshSlots();
			});
		});
	}

	// 決済へ進むための送信では解放してはいけない。掴んだまま Stripe へ渡す。
	var bookingForm = booking ? booking.querySelector('form') : null;

	if (bookingForm) {
		bookingForm.addEventListener('submit', function () {
			submitting = true;
		});
	}

	// タブを閉じたときに掴んだままにしない。届かなくても期限切れで解放される。
	window.addEventListener('pagehide', function () {
		if (submitting || !hold || !navigator.sendBeacon) {
			return;
		}

		var body = new FormData();
		body.append('action', 'ssb_release_slot');
		body.append('nonce', data.nonce);
		body.append('slot_id', hold.slotId);
		body.append('token', hold.token);
		navigator.sendBeacon(data.ajaxUrl, body);
	});

	indexSlots(data.slots);

	if (availableDates.length) {
		var first = availableDates[0].split('-');
		view = { year: parseInt(first[0], 10), month: parseInt(first[1], 10) - 1 };
		selected = availableDates[0];
	} else {
		var today = (data.today || '').split('-');
		view = { year: parseInt(today[0], 10), month: parseInt(today[1], 10) - 1 };
	}

	render();
})();
