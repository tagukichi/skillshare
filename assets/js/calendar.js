/**
 * 予約カレンダー.
 *
 * サーバーから渡された空き枠（window.ssbCalendarData）を月表示に並べる。
 * 実装順序 6 の時点では枠を選んでも予約はできない。仮押さえと入力フォームは
 * 実装順序 7 で onSlotClick に載せる。
 */
(function () {
	'use strict';

	var root = document.getElementById('ssb-calendar');

	if (!root || !window.ssbCalendarData) {
		return;
	}

	var data = window.ssbCalendarData;
	var WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'];

	// 日付ごとに枠をまとめる。
	var byDate = {};
	(data.slots || []).forEach(function (slot) {
		if (!byDate[slot.date]) {
			byDate[slot.date] = [];
		}
		byDate[slot.date].push(slot);
	});

	var availableDates = Object.keys(byDate).sort();

	var view = null;      // { year: 2026, month: 7 }  month は 0 始まり
	var selected = null;  // 'YYYY-MM-DD'

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

	/** 空き枠がある月の一覧（重複なし・昇順）。 */
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

	/** 枠を選んだとき。実装順序 7 でここに仮押さえを載せる。 */
	function onSlotClick() {
		return;
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

		// 1日の曜日まで空セルで埋める。
		for (var blank = 0; blank < first.getDay(); blank++) {
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
		var heading = parts[0] + '年' + Number(parts[1]) + '月' + Number(parts[2]) + '日'
			+ '（' + WEEKDAYS[new Date(parts[0], parts[1] - 1, parts[2]).getDay()] + '）';

		box.appendChild(el('h3', 'ssb-calendar__times-title', heading));

		var list = el('div', 'ssb-calendar__slots');

		byDate[selected].forEach(function (slot) {
			var button = el('button', 'ssb-calendar__slot', slot.start + '〜' + slot.end);
			button.type = 'button';
			button.dataset.slotId = String(slot.id);
			button.disabled = true;
			button.title = '予約機能は準備中です';
			button.addEventListener('click', onSlotClick);
			list.appendChild(button);
		});

		box.appendChild(list);
		box.appendChild(el('p', 'ssb-muted ssb-calendar__note', '予約の受付は準備中です。'));

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

	function init() {
		if (availableDates.length) {
			var first = availableDates[0].split('-');
			view = { year: parseInt(first[0], 10), month: parseInt(first[1], 10) - 1 };
			selected = availableDates[0];
		} else {
			var today = (data.today || '').split('-');
			view = { year: parseInt(today[0], 10), month: parseInt(today[1], 10) - 1 };
		}

		render();
	}

	init();
})();
