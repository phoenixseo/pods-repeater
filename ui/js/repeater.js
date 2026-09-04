/**
 * Pods Repeater Field — admin UI (vanilla JS, no build step).
 *
 * Adds/removes/moves rows in the server-side rendered repeater.
 * Deliberately dependency-free (no jQuery, no React): this is the
 * merge-friendly scope for a Pods core PR (PR Phase 1 = PHP only).
 */
(function () {
	'use strict';

	function getRows(container) {
		return container.querySelector('.pods-repeater-rows');
	}

	function addRow(container) {
		const rows = getRows(container);
		const limit = parseInt(container.dataset.limit || '0', 10);
		if (limit > 0 && rows.querySelectorAll('.pods-repeater-row').length >= limit) {
			return;
		}

		const name = rows.dataset.name || '';
		const index = rows.querySelectorAll('.pods-repeater-row').length;
		const template = container.querySelector('.pods-repeater-row');
		if (!template) {
			return;
		}

		const clone = template.cloneNode(true);
		clone.dataset.index = String(index);
		clone.querySelectorAll('[data-sub]').forEach(function (input) {
			input.name = name + '[' + index + '][' + input.dataset.sub + ']';
			input.id = name + '_' + index + '_' + input.dataset.sub;
			if (input.tagName === 'TEXTAREA') {
				input.value = '';
			} else if (input.tagName === 'SELECT') {
				input.selectedIndex = 0;
			} else {
				input.value = '';
			}
		});
		rows.appendChild(clone);
	}

	function removeRow(button) {
		const row = button.closest('.pods-repeater-row');
		if (row) {
			row.remove();
			renumber(row.closest('.pods-repeater-rows'));
		}
	}

	function moveRow(button, delta) {
		const row = button.closest('.pods-repeater-row');
		const rows = row ? row.closest('.pods-repeater-rows') : null;
		if (!row || !rows) {
			return;
		}
		const sibling = delta < 0 ? row.previousElementSibling : row.nextElementSibling;
		if (sibling) {
			rows.insertBefore(row, delta < 0 ? sibling : sibling.nextElementSibling);
			renumber(rows);
		}
	}

	function renumber(rows) {
		const name = rows.dataset.name || '';
		rows.querySelectorAll('.pods-repeater-row').forEach(function (row, i) {
			row.dataset.index = String(i);
			row.querySelectorAll('[data-sub]').forEach(function (input) {
				const sub = input.dataset.sub;
				if (!sub) {
					return;
				}
				// Kompletter Name/ID-Rebuild aus dem Container-Namen + Index +
				// data-sub (wie addRow) statt Regex auf bestehende Namen: die
				// alte /_\d+_/-Ersetzung korrumpierte Feldnamen, die selbst ein
				// '_ziffer_'-Muster enthalten (z. B. 'row1_2_x').
				input.name = name + '[' + i + '][' + sub + ']';
				input.id = name + '_' + i + '_' + sub;
			});
		});
	}

	document.addEventListener('click', function (event) {
		const add = event.target.closest('.pods-repeater-add');
		if (add) {
			addRow(add.closest('.pods-repeater'));
			return;
		}
		const remove = event.target.closest('.pods-repeater-remove');
		if (remove) {
			removeRow(remove);
			return;
		}
		const up = event.target.closest('.pods-repeater-move-up');
		if (up) {
			moveRow(up, -1);
			return;
		}
		const down = event.target.closest('.pods-repeater-move-down');
		if (down) {
			moveRow(down, 1);
		}
	});
})();
