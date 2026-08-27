/**
 * Pods Repeater Field — admin UI (vanilla JS, no build step).
 *
 * Adds/removes/moves rows in the server-side rendered repeater.
 * Deliberately dependency-free (no jQuery, no React): this is the
 * merge-friendly scope for a Pods core PR (PR Phase 1 = PHP only).
 */
(function () {
	'use strict';

	function addRow(container) {
		const rows = container.querySelector('.pods-repeater-rows');
		const limit = parseInt(container.dataset.limit || '0', 10);
		if (limit > 0 && rows.querySelectorAll('.pods-repeater-row').length >= limit) {
			return;
		}

		const name = rows.dataset.name || '';
		const index = rows.querySelectorAll('.pods-repeater-row').length;
		const template = container.querySelector('.pods-repeater-row');
		if (!template) {
			// No template row — render a fresh row from sub-field definitions.
			// (Full implementation in Phase B; spike renders rows server-side.)
			return;
		}

		const clone = template.cloneNode(true);
		clone.dataset.index = String(index);
		clone.querySelectorAll('[name]').forEach(function (input) {
			input.name = name + '[' + index + '][' + input.dataset.sub + ']';
			input.value = '';
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

	function renumber(rows) {
		rows.querySelectorAll('.pods-repeater-row').forEach(function (row, i) {
			row.dataset.index = String(i);
			row.querySelectorAll('[name]').forEach(function (input) {
				input.name = input.name.replace(/\[\d+\]\[/, '[' + i + '][');
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
		}
	});
})();
