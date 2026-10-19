/**
 * Pods Repeater Field — Feldgruppen-Verwaltung (Vanilla JS, kein Build-Schritt).
 *
 * Ergänzt das serverseitig gerenderte Formular der Feldgruppen-Seite:
 *   - "Feld hinzufügen" klont die letzte Zeile und leert die Eingaben.
 *   - "Entfernen" löscht eine Zeile; die letzte Zeile bleibt stehen,
 *     damit das Formular nie ohne Feldzeile dasteht.
 *   - Lösch-Links bekommen eine Rückfrage mit dem Gruppen-Label.
 *
 * Ausschließlich Event-Delegation auf document, keine Inline-Handler:
 * die Seite ist ohne dieses Skript vollständig absendbar (der Server
 * ignoriert Zeilen ohne Namen), das Skript ist reine Bedienhilfe.
 */
(function () {
	'use strict';

	var ROW_SELECTOR = '.pods-repeater-group-field-row';
	var TABLE_SELECTOR = '.pods-repeater-group-fields';
	var NAME_INPUT = '.pods-repeater-group-field-name';
	var LABEL_INPUT = '.pods-repeater-group-field-label';
	var TYPE_INPUT = '.pods-repeater-group-field-type';
	var REQUIRED_INPUT = '.pods-repeater-group-field-required';

	/**
	 * Setzt die tabellarischen Feldnamen einer Zeile neu.
	 *
	 * Namen sind `pods_repeater_group_fields[<index>][<key>]`; der Index
	 * wird nach dem Einfügen/Entfernen neu durchnummeriert, damit die
	 * Reihenfolge im DOM der Reihenfolge im Request entspricht.
	 */
	function reindex(rows) {
		for (var i = 0; i < rows.length; i++) {
			var row = rows[i];
			var inputs = row.querySelectorAll('[name]');

			for (var j = 0; j < inputs.length; j++) {
				var input = inputs[j];
				var name = input.getAttribute('name') || '';
				var next = name.replace(
					/^(pods_repeater_group_fields)\[\d+\]\[(.+)\]$/,
					'$1[' + i + '][$2]'
				);

				input.setAttribute('name', next);

				var id = input.getAttribute('id') || '';

				if (id) {
					input.setAttribute(
						'id',
						id.replace(/-field-\d+-/, '-field-' + i + '-')
					);
				}
			}
		}
	}

	/**
	 * Klont die letzte Zeile, leert die Eingaben und fügt sie an.
	 */
	function addRow(table) {
		var body = table.querySelector('tbody');

		if (!body) {
			return;
		}

		var rows = body.querySelectorAll(ROW_SELECTOR);
		var template = rows.length ? rows[rows.length - 1] : null;

		if (!template) {
			return;
		}

		var clone = template.cloneNode(true);
		var fields = clone.querySelectorAll('input, select, textarea');

		for (var i = 0; i < fields.length; i++) {
			var input = fields[i];

			if (input.tagName === 'SELECT') {
				input.selectedIndex = 0;
			} else if (input.type === 'checkbox' || input.type === 'radio') {
				input.checked = false;
			} else {
				input.value = '';
			}
		}

		body.appendChild(clone);
		reindex(body.querySelectorAll(ROW_SELECTOR));

		var first = clone.querySelector(NAME_INPUT) || clone.querySelector('input');
		if (first && typeof first.focus === 'function') {
			first.focus();
		}
	}

	/**
	 * Entfernt eine Zeile; die letzte Zeile wird geleert statt gelöscht.
	 */
	function removeRow(row) {
		var table = row.closest(TABLE_SELECTOR);

		if (!table) {
			row.parentNode.removeChild(row);
			return;
		}

		var body = table.querySelector('tbody');
		var rows = body ? body.querySelectorAll(ROW_SELECTOR) : [];

		if (rows.length <= 1) {
			var fields = row.querySelectorAll('input, select, textarea');

			for (var i = 0; i < fields.length; i++) {
				if (fields[i].tagName === 'SELECT') {
					fields[i].selectedIndex = 0;
				} else if (fields[i].type === 'checkbox' || fields[i].type === 'radio') {
					fields[i].checked = false;
				} else {
					fields[i].value = '';
				}
			}

			return;
		}

		row.parentNode.removeChild(row);
		reindex(body.querySelectorAll(ROW_SELECTOR));
	}

	document.addEventListener('click', function (event) {
		var add = event.target.closest('.pods-repeater-group-add-field');

		if (add) {
			event.preventDefault();
			var table = document.querySelector(TABLE_SELECTOR);

			if (table) {
				addRow(table);
			}

			return;
		}

		var remove = event.target.closest('.pods-repeater-group-remove-field');

		if (remove) {
			event.preventDefault();
			var row = remove.closest(ROW_SELECTOR);

			if (row) {
				removeRow(row);
			}

			return;
		}

		var del = event.target.closest('.pods-repeater-group-delete');

		if (del) {
			var label = del.getAttribute('data-label') || del.textContent || '';

			if (!window.confirm('Delete field group "' + label + '"?')) {
				event.preventDefault();
			}
		}
	});
})();
