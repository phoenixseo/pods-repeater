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
		// DFV-Sub-Felder (kein data-sub, aber name mit [index]) ebenfalls
		// auf die neue Zeile umbiegen, damit Pods' React-Komponenten beim
		// Row-Klon den richtigen Namen bekommen.
		clone.querySelectorAll('.pods-repeater-subfield').forEach(function (wrapper) {
			wrapper.querySelectorAll('[name]').forEach(function (input) {
				if (input.dataset.sub) {
					return; // bereits oben behandelt
				}
				const nameNow = input.getAttribute('name') || '';
				if (nameNow.indexOf('[') === -1) {
					return;
				}
				input.setAttribute('name', nameNow.replace(/\[\d+\]/, '[' + index + ']'));
				input.setAttribute('id', (input.getAttribute('id') || '').replace(/_\d+_/, '_' + index + '_'));
			});
		});
		rows.appendChild(clone);
		runRowScripts(clone);
	}

	/**
	 * Re-run inline <script>s of a freshly cloned row.
	 *
	 * Browsers do NOT execute <script> content of cloned nodes, and Pods'
	 * field types (number/date/color/…) emit small inline jQuery helpers
	 * (blur filters, pickers) inside their markup. Without re-running them
	 * a cloned row would carry dead <script> elements. Scripts are created
	 * fresh (same text) so the helpers bind to the new row's inputs; the
	 * admin page always has jQuery loaded globally.
	 *
	 * @param {HTMLElement} root Cloned row.
	 */
	function runRowScripts(root) {
		root.querySelectorAll('script').forEach(function (oldScript) {
			const fresh = document.createElement('script');
			fresh.textContent = oldScript.textContent;
			oldScript.parentNode.replaceChild(fresh, oldScript);
		});
	}

	function removeRow(button) {
		const row = button.closest('.pods-repeater-row');
		if (row) {
			// rows VOR row.remove() aufloesen: .pods-repeater-rows ist der
			// direkte Parent der Row — closest() auf einem detached Node
			// liefert null, renumber(null) crashte mit TypeError (E2E-Fund
			// 04.09.2026) und liess data-index/input-names unrenumbered zurueck.
			const rows = row.closest('.pods-repeater-rows');
			row.remove();
			if (rows) {
				renumber(rows);
			}
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
