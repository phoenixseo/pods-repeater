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

	/* ------------------------------------------------------------------ *
	 * Sub-field editor (Repeater field options dialog).
	 *
	 * The Pods field dialog (.pods-settings-modal) is rendered client-side
	 * by DFV from a server-built schema. The plugin's PHP filter appends a
	 * static 'html' option (repeater_sub_fields_editor) to the repeater
	 * options group; DFV renders it as a plain div
	 * (#pods-repeater-subfield-editor). This module observes that container
	 * and, whenever the dialog opens, turns the repeater_sub_fields textarea
	 * (the real, saveable source of truth) into a list of sub-field rows with
	 * add / edit / remove controls, writing changes back as JSON and firing
	 * 'change' so the dialog state stays consistent.
	 *
	 * Deliberately dependency-free like the rest of this file (no jQuery, no
	 * React): the field dialog lives inside the DFV React tree, but the
	 * options themselves are plain DOM that we may extend safely. The
	 * observer pattern also covers the case where the schema/container
	 * appears after this script ran (late-loaded dialog).
	 * ------------------------------------------------------------------ */

	// Whitelist mirrors the Pods field types the server-side pipeline can
	// render (PodsForm::field_types() in the test instance). 'repeater' is
	// intentionally absent: nested repeaters are rejected server-side.
	const SUBFIELD_TYPES = [
		'avatar', 'boolean', 'code', 'color', 'currency', 'date', 'datetime',
		'email', 'file', 'heading', 'html', 'number', 'oembed', 'paragraph',
		'password', 'phone', 'pick', 'slug', 'text', 'time', 'website', 'wysiwyg'
	];
	// Types with no editable value of their own (visual separators only).
	const SUBFIELD_DISPLAY_TYPES = ['heading', 'html'];
	// Server-side renderer supports every registered Pods type; types that
	// need option data (pick/select/radio/file/avatar/oembed) fall back to a
	// plain text input until their options are set up here. Kept minimal.
	const SUBFIELD_TYPE_LABELS = {
		avatar: 'Avatar', boolean: 'Boolean', code: 'Code', color: 'Color',
		currency: 'Currency', date: 'Date', datetime: 'Datetime', email: 'Email',
		file: 'File', heading: 'Heading', html: 'HTML', number: 'Number',
		oembed: 'oEmbed', paragraph: 'Paragraph', password: 'Password',
		phone: 'Phone', pick: 'Pick', slug: 'Slug', text: 'Text', time: 'Time',
		website: 'Website', wysiwyg: 'WYSIWYG'
	};
	// Minimal editable shape of a sub-field definition.
	const SUBFIELD_DEFAULT_FIELDS = [
		{ key: 'name', label: 'Name', placeholder: 'my_field' },
		{ key: 'type', label: 'Type', type: 'select' },
		{ key: 'label', label: 'Label', placeholder: 'My Field' }
	];

	function getSubfieldEditorContainer() {
		return document.getElementById('pods-repeater-subfield-editor');
	}

	function getSubfieldTextarea() {
		// The textarea lives in the same options group (the schema's
		// repeater_sub_fields paragraph) inside the settings modal.
		const modal = document.querySelector('.pods-settings-modal');
		if (!modal) {
			return null;
		}
		return modal.querySelector('textarea[name="repeater_sub_fields"]');
	}

	function parseSubfields(raw) {
		if (!raw) {
			return [];
		}
		try {
			const parsed = JSON.parse(raw);
			if (!Array.isArray(parsed)) {
				return [];
			}
			// Keep only well-formed entries (name + type); tolerate
			// extra keys (options etc.) untouched.
			return parsed.filter(function (entry) {
				return entry && typeof entry === 'object' &&
					typeof entry.name === 'string' && entry.name !== '' &&
					typeof entry.type === 'string';
			});
		} catch (e) {
			return [];
		}
	}

	function subfieldTypeLabel(type) {
		return SUBFIELD_TYPE_LABELS[type] || type;
	}

	function renderSubfieldEditor(container, textarea) {
		const subfields = parseSubfields(textarea.value);
		container.innerHTML = '';

		// All content below is built via createElement/textContent only;
		// innerHTML is used solely to clear the container (''), never with
		// user-supplied HTML — no XSS surface.
		var list = document.createElement('div');
		list.className = 'pods-repeater-subfield-list';

		if (subfields.length === 0) {
			var empty = document.createElement('p');
			empty.className = 'description';
			empty.textContent = 'No sub-fields yet. Add one below.';
			list.appendChild(empty);
		} else {
			subfields.forEach(function (sub, index) {
				var row = document.createElement('div');
				row.className = 'pods-repeater-subfield-row';

				var summary = document.createElement('span');
				summary.className = 'pods-repeater-subfield-summary';
				summary.textContent = (index + 1) + '. ' + sub.name +
					' (' + subfieldTypeLabel(sub.type) + ')' +
					(sub.label ? ' — ' + sub.label : '');
				row.appendChild(summary);

				var editBtn = document.createElement('button');
				editBtn.type = 'button';
				editBtn.className = 'button pods-repeater-subfield-edit';
				editBtn.textContent = 'Edit';
				row.appendChild(editBtn);

				var removeBtn = document.createElement('button');
				removeBtn.type = 'button';
				removeBtn.className = 'button pods-repeater-subfield-remove';
				removeBtn.textContent = 'Remove';
				row.appendChild(removeBtn);

				list.appendChild(row);
			});
		}

		var addBtn = document.createElement('button');
		addBtn.type = 'button';
		addBtn.className = 'button pods-repeater-subfield-add';
		addBtn.textContent = 'Add sub-field';
		list.appendChild(addBtn);

		container.appendChild(list);
		container.dataset.editorReady = '1';

		// Row actions.
		list.addEventListener('click', function (event) {
			var target = event.target;
			if (!target || !target.closest) {
				return;
			}
			var rowEl = target.closest('.pods-repeater-subfield-row');
			var action;
			if (target.closest('.pods-repeater-subfield-edit')) {
				action = 'edit';
			} else if (target.closest('.pods-repeater-subfield-remove')) {
				action = 'remove';
			} else if (target.closest('.pods-repeater-subfield-add')) {
				action = 'add';
			}
			if (!action) {
				return;
			}

			var current = parseSubfields(textarea.value);
			if (action === 'remove' && rowEl) {
				current.splice(Array.prototype.indexOf.call(list.children, rowEl), 1);
				writeSubfields(textarea, current);
				renderSubfieldEditor(container, textarea); // re-render list
				return;
			}
			if (action === 'add') {
				openSubfieldForm(container, textarea, null, current);
				return;
			}
			if (action === 'edit' && rowEl) {
				var idx = Array.prototype.indexOf.call(list.children, rowEl);
				openSubfieldForm(container, textarea, current[idx] || null, current, idx);
			}
		});
	}

	function openSubfieldForm(container, textarea, sub, all, editIndex) {
		// Hide the list, show the (single) editor form in the same container.
		container.innerHTML = '';

		var form = document.createElement('div');
		form.className = 'pods-repeater-subfield-form';

		var title = document.createElement('h4');
		title.textContent = sub ? 'Edit sub-field' : 'Add sub-field';
		form.appendChild(title);

		var fields = {};
		SUBFIELD_DEFAULT_FIELDS.forEach(function (def) {
			var labelEl = document.createElement('label');
			labelEl.className = 'pods-repeater-subfield-label';
			labelEl.textContent = def.label + ':';
			var input;
			if (def.type === 'select') {
				input = document.createElement('select');
				SUBFIELD_TYPES.forEach(function (type) {
					var opt = document.createElement('option');
					opt.value = type;
					opt.textContent = subfieldTypeLabel(type);
					input.appendChild(opt);
				});
				if (sub) {
					input.value = sub.type || 'text';
				}
			} else {
				input = document.createElement('input');
				input.type = 'text';
				input.placeholder = def.placeholder || '';
				if (sub && sub[def.key] !== undefined) {
					input.value = sub[def.key];
				}
			}
			input.className = 'pods-repeater-subfield-input';
			input.name = 'subfield_' + def.key;
			labelEl.appendChild(input);
			fields[def.key] = input;
			form.appendChild(labelEl);
		});

		var hint = document.createElement('p');
		hint.className = 'description';
		hint.textContent = 'Name = meta key (a-z, 0-9, underscore). Display types (Heading/HTML) have no value.';
		form.appendChild(hint);

		var actions = document.createElement('div');
		actions.className = 'pods-repeater-subfield-actions';

		var cancelBtn = document.createElement('button');
		cancelBtn.type = 'button';
		cancelBtn.className = 'button pods-repeater-subfield-cancel';
		cancelBtn.textContent = 'Cancel';
		actions.appendChild(cancelBtn);

		var saveBtn = document.createElement('button');
		saveBtn.type = 'button';
		saveBtn.className = 'button button-primary pods-repeater-subfield-save';
		saveBtn.textContent = sub ? 'Save' : 'Add';
		actions.appendChild(saveBtn);

		form.appendChild(actions);
		container.appendChild(form);

		if (fields.name) {
			fields.name.focus();
		}

		function doSave() {
			var name = (fields.name.value || '').trim();
			var type = fields.type ? fields.type.value : 'text';
			var label = (fields.label.value || '').trim();
			if (!name) {
				fields.name.focus();
				return;
			}
			var entry = { name: name, type: type };
			if (label) {
				entry.label = label;
			}
			var next = all.slice();
			if (typeof editIndex === 'number' && editIndex >= 0 && editIndex < next.length) {
				next[editIndex] = entry;
			} else {
				next.push(entry);
			}
			writeSubfields(textarea, next);
			renderSubfieldEditor(container, textarea); // back to list
		}

		saveBtn.addEventListener('click', doSave);
		cancelBtn.addEventListener('click', function () {
			renderSubfieldEditor(container, textarea);
		});
		form.addEventListener('keydown', function (event) {
			if (event.key === 'Enter' && event.target && event.target.tagName !== 'TEXTAREA') {
				event.preventDefault();
				doSave();
			}
		});
	}

	function writeSubfields(textarea, subfields) {
		textarea.value = JSON.stringify(subfields);
		textarea.dispatchEvent(new Event('change', { bubbles: true }));
	}

	function setupSubfieldEditor() {
		var container = getSubfieldEditorContainer();
		var textarea = getSubfieldTextarea();
		if (!container || !textarea) {
			return;
		}
		// (Re-)render whenever the dialog opens: the schema (and thus the
		// container) is re-created on each open, so a fresh render keeps the
		// list in sync with whatever is currently in the textarea.
		if (container.dataset.editorReady === '1') {
			return;
		}
		container.dataset.editorReady = '1';
		renderSubfieldEditor(container, textarea);
	}

	// Observe the whole document: when the DFV-rendered dialog (schema)
	// appears, the static container is present and we attach the editor.
	const subfieldObserver = new MutationObserver(function () {
		setupSubfieldEditor();
	});
	subfieldObserver.observe(document.documentElement, { childList: true, subtree: true });

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
