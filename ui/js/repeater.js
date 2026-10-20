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
	// intentionally absent: nested repeaters are rejected server-side in
	// classes/fields/repeater.php (validate()) and stripped in pre_save()
	// for write paths that skip validation.
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
	// Verifizierte Wirk-Matrix (2026-09-07/08 gegen die echte Pipeline
	// render_sub_field_input()/render_sub_field_pick() gemessen):
	// placeholder greift bei allen Text-ähnlichen; maxlength zusätzlich bei text/
	// paragraph/code/slug/password/email; pick liest data/pick_format_type/
	// pick_format_type_single; default ist ein NO-OP (Server füllt nur Row-Werte)
	// und wird deshalb NICHT angeboten. 'required' wirkt in validate().
	// Seit dem Server-Fix (generische Durchreichung der Sub-Options) wirken auch
	// die präfixierten Feldtyp-Optionen.
	// Alle Keys stammen aus Pods options() (classes/fields/*.php, verifiziert
	// 2026-09-08); UI-Labels sind Kurzformen. Nur wirksame Optionen werden angeboten.
	//
	// Format der Einträge:
	//   'key'            => gespeicherter Options-Key (mit Typ-Präfix)
	//   { label, type: 'select', options: {value: Label}, placeholder? }
	//   { label, type: 'text'|'number', placeholder? }
	//   { label, type: 'checkbox' }  (checked = 1)
	const SUBFIELD_CONFIG_OPTIONS = {
		text: {
			placeholder: { label: 'Placeholder', type: 'text' },
			maxlength: { label: 'Max length', type: 'number' }
		},
		paragraph: {
			placeholder: { label: 'Placeholder', type: 'text' },
			maxlength: { label: 'Max length', type: 'number' }
		},
		code: {
			placeholder: { label: 'Placeholder', type: 'text' },
			maxlength: { label: 'Max length', type: 'number' }
		},
		slug: {
			placeholder: { label: 'Placeholder', type: 'text' },
			maxlength: { label: 'Max length', type: 'number' }
		},
		password: {
			placeholder: { label: 'Placeholder', type: 'text' },
			maxlength: { label: 'Max length', type: 'number' }
		},
		website: {
			placeholder: { label: 'Placeholder', type: 'text' },
			website_format: { label: 'Format', type: 'select', options: {
				normal: 'https://example.com/',
				'no-www': 'Remove www',
				'force-www': 'Force www',
				'no-http': 'example.com',
				'no-http-no-www': 'example.com (no www)',
				'no-http-force-www': 'www.example.com',
				none: 'No format' } },
			website_allow_port: { label: 'Allow port in URL', type: 'checkbox' },
			website_clickable: { label: 'Output as link', type: 'checkbox' },
			website_new_window: { label: 'Open in new window', type: 'checkbox' },
			website_nofollow: { label: 'Add nofollow', type: 'checkbox' },
			website_max_length: { label: 'Max length (-1 = no limit)', type: 'number' }
		},
		email: {
			placeholder: { label: 'Placeholder', type: 'text' },
			maxlength: { label: 'Max length', type: 'number' }
		},
		phone: {
			placeholder: { label: 'Placeholder', type: 'text' },
			phone_format: { label: 'Format', type: 'select', options: {
				'999-999-9999 x999': '123-456-7890 x123',
				'(999) 999-9999 x999': '(123) 456-7890 x123',
				'999.999.9999 x999': '123.456.7890 x123',
				international: 'International (any)' } },
			phone_max_length: { label: 'Max length (-1 = no limit)', type: 'number' }
		},
		currency: {
			placeholder: { label: 'Placeholder', type: 'text' },
			currency_format_type: { label: 'Input type', type: 'select', options: {
				number: 'Freeform number', slider: 'Slider' } },
			currency_format_sign: { label: 'Currency', type: 'select', options: {
				aud: 'AUD ($)', brl: 'BRL (R$)', cad: 'CAD ($)', chf: 'CHF', cny: 'CNY (¥)',
				czk: 'CZK (Kč)', dkk: 'DKK (kr)', eur: 'EUR (€)', gbp: 'GBP (£)',
				hkd: 'HKD ($)', huf: 'HUF (Ft)', idr: 'IDR (Rp)', ils: 'ILS (₪)',
				inr: 'INR (₹)', jpy: 'JPY (¥)', krw: 'KRW (₩)', mxn: 'MXN ($)',
				myr: 'MYR (RM)', ngn: 'NGN (₦)', nok: 'NOK (kr)', nzd: 'NZD ($)',
				php: 'PHP (₱)', pln: 'PLN (zł)', rub: 'RUB (₽)', sek: 'SEK (kr)',
				sgd: 'SGD ($)', thb: 'THB (฿)', twd: 'TWD (NT$)', usd: 'USD ($)',
				usdcent: 'US Cent (¢)', vnd: 'VND (₫)', zar: 'ZAR (R)' } },
			currency_format_placement: { label: 'Placement', type: 'select', options: {
				before: 'Before ($100)', after: 'After (100$)',
				before_space: 'Before with space ($ 100)', after_space: 'After with space (100 $)',
				none: 'None (100)', beforeaftercode: 'Before, code after ($100 USD)',
				beforeaftercode_space: 'Before w/ space, code after ($ 100 USD)' } },
			currency_format: { label: 'Number format', type: 'select', options: {
				i18n: 'Localized default', '9,999.99': '1,234.00', '9999.99': '1234.00',
				'9.999,99': '1.234,00', '9999,99': '1234,00', '9 999,99': '1 234,00',
				"9'999.99": "1'234.00" } },
			currency_decimals: { label: 'Decimals', type: 'number' },
			currency_decimal_handling: { label: 'Decimal handling', type: 'select', options: {
				none: 'Default (1.00, 0.00)', remove: 'Remove (1, 0)',
				remove_only_zero: 'Remove only when zero (1.00, 0)',
				dash: 'To dash (1.-, 0.-)', dash_only_zero: 'Dash only when zero (1.00, 0.-)',
				dash_whole_zero: 'Whole value to dash (1.00, -)' } }
		},
		number: {
			placeholder: { label: 'Placeholder', type: 'text' },
			number_format_type: { label: 'Input type', type: 'select', options: {
				number: 'Freeform number', slider: 'Slider' } },
			number_format: { label: 'Number format', type: 'select', options: {
				i18n: 'Localized default', '9,999.99': '1,234.00', '9999.99': '1234.00',
				'9.999,99': '1.234,00', '9999,99': '1234,00', '9 999,99': '1 234,00',
				"9'999.99": "1'234.00" } },
			number_decimals: { label: 'Decimals', type: 'number' },
			number_decimal_handling: { label: 'Decimal handling', type: 'select', options: {
				none: 'Default (1.00, 0.00)', remove: 'Remove (1, 0)',
				remove_only_zero: 'Remove only when zero (1.00, 0)',
				dash: 'To dash (1.-, 0.-)', dash_only_zero: 'Dash only when zero (1.00, 0.-)',
				dash_whole_zero: 'Whole value to dash (1.00, -)' } },
			number_step: { label: 'Slider step', type: 'text' },
			number_min: { label: 'Minimum', type: 'text' },
			number_max: { label: 'Maximum', type: 'text' },
			number_max_length: { label: 'Max digits', type: 'number' }
		},
		oembed: {
			placeholder: { label: 'Placeholder', type: 'text' },
			oembed_width: { label: 'Embed width (0 = none)', type: 'number' },
			oembed_height: { label: 'Embed height (0 = none)', type: 'number' },
			oembed_show_preview: { label: 'Show preview', type: 'checkbox' },
			oembed_restrict_providers: { label: 'Restrict to providers', type: 'checkbox' }
		},
		date: {
			date_type: { label: 'Date format type', type: 'select', options: {
				wp: 'WordPress default', format: 'Predefined format', custom: 'Custom format' } },
			date_format_custom: { label: 'Date format for display (PHP)', type: 'text' },
			date_format_custom_js: { label: 'Date format for input (jQuery UI)', type: 'text' },
			date_format: { label: 'Date format', type: 'select', options: {
				mdy: 'm/d/Y', mdy_dash: 'm-d-Y', mdy_dot: 'm.d.Y',
				ymd_slash: 'Y/m/d', ymd_dash: 'Y-m-d', ymd_dot: 'Y.m.d',
				fjy: 'F j, Y', fjsy: 'F jS, Y', y: 'Y' } },
			date_year_range_custom: { label: 'Year range (e.g. -10:+10)', type: 'text' },
			date_allow_empty: { label: 'Allow empty value', type: 'checkbox' }
		},
		time: {
			time_type: { label: 'Time format type', type: 'select', options: {
				wp: 'WordPress default', '12': '12 hour', '24': '24 hour', custom: 'Custom format' } },
			time_format_custom: { label: 'Time format for display (PHP)', type: 'text' },
			time_format_custom_js: { label: 'Time format for input (jQuery UI)', type: 'text' },
			time_format: { label: 'Time format (12h)', type: 'select', options: {
				h_mm_A: 'g:i A', h_mm_ss_A: 'g:i:s A', hh_mm_A: 'h:i A', hh_mm_ss_A: 'h:i:s A',
				h_mma: 'g:ia', hh_mma: 'h:ia', h_mm: 'g:i', h_mm_ss: 'g:i:s', hh_mm: 'h:i', hh_mm_ss: 'h:i:s' } },
			time_format_24: { label: 'Time format (24h)', type: 'select', options: {
				hh_mm: 'H:i', hh_mm_ss: 'H:i:s' } },
			time_allow_empty: { label: 'Allow empty value', type: 'checkbox' }
		},
		datetime: {
			datetime_type: { label: 'Date format type', type: 'select', options: {
				wp: 'WordPress default', format: 'Predefined format', custom: 'Custom format' } },
			datetime_format_custom: { label: 'Date format for display (PHP)', type: 'text' },
			datetime_format_custom_js: { label: 'Date format for input (jQuery UI)', type: 'text' },
			datetime_format: { label: 'Date format', type: 'select', options: {
				mdy: 'm/d/Y', mdy_dash: 'm-d-Y', mdy_dot: 'm.d.Y',
				ymd_slash: 'Y/m/d', ymd_dash: 'Y-m-d', ymd_dot: 'Y.m.d',
				fjy: 'F j, Y', fjsy: 'F jS, Y', c: 'ISO 8601' } },
			datetime_time_type: { label: 'Time format type', type: 'select', options: {
				wp: 'WordPress default', '12': '12 hour', '24': '24 hour', custom: 'Custom format' } },
			datetime_time_format_custom: { label: 'Time format for display (PHP)', type: 'text' },
			datetime_time_format_custom_js: { label: 'Time format for input (jQuery UI)', type: 'text' },
			datetime_time_format: { label: 'Time format (12h)', type: 'select', options: {
				h_mm_A: 'g:i A', h_mm_ss_A: 'g:i:s A', hh_mm_A: 'h:i A', hh_mm_ss_A: 'h:i:s A',
				h_mma: 'g:ia', hh_mma: 'h:ia', h_mm: 'g:i', h_mm_ss: 'g:i:s', hh_mm: 'h:i', hh_mm_ss: 'h:i:s' } },
			datetime_time_format_24: { label: 'Time format (24h)', type: 'select', options: {
				hh_mm: 'H:i', hh_mm_ss: 'H:i:s' } },
			datetime_year_range_custom: { label: 'Year range (e.g. -10:+10)', type: 'text' },
			datetime_allow_empty: { label: 'Allow empty value', type: 'checkbox' }
		},
		color: {
			color_select_label: { label: 'Select color label', type: 'text', placeholder: 'Select Color' },
			color_clear_label: { label: 'Clear label', type: 'text', placeholder: 'Clear' }
		},
		boolean: {
			boolean_format_type: { label: 'Input type', type: 'select', options: {
				checkbox: 'Checkbox', radio: 'Radio buttons', dropdown: 'Drop down' } },
			boolean_yes_label: { label: 'Yes label', type: 'text', placeholder: 'Yes' },
			boolean_no_label: { label: 'No label', type: 'text', placeholder: 'No' }
		},
		wysiwyg: {
			wysiwyg_editor: { label: 'Editor', type: 'select', options: {
				tinymce: 'TinyMCE (WP default)', quill: 'Quill', cleditor: 'CLEditor (fallback: Quill)' } },
			wysiwyg_editor_height: { label: 'Editor height (px)', type: 'number' },
			wysiwyg_media_buttons: { label: 'Enable media buttons', type: 'checkbox' },
			wysiwyg_delay_init: { label: 'Delay TinyMCE init', type: 'checkbox' },
			wysiwyg_trim: { label: 'Trim extra whitespace', type: 'checkbox' },
			wysiwyg_trim_lines: { label: 'Trim whitespace at line ends', type: 'checkbox' },
			wysiwyg_trim_p_brs: { label: 'Remove blank lines / empty p / br', type: 'checkbox' },
			wysiwyg_trim_extra_lines: { label: 'Remove extra blank lines', type: 'checkbox' },
			wysiwyg_sanitize_html: { label: 'Sanitize HTML', type: 'checkbox' },
			wysiwyg_oembed: { label: 'Enable oEmbed', type: 'checkbox' },
			wysiwyg_wptexturize: { label: 'Enable wptexturize', type: 'checkbox' },
			wysiwyg_convert_chars: { label: 'Enable convert_chars', type: 'checkbox' },
			wysiwyg_wpautop: { label: 'Enable wpautop', type: 'checkbox' }
		}
	};
	// Generische (nicht-pick) Typschlüssel, die Konfiguration anbieten.
	const SUBFIELD_CONFIG_TYPES = Object.keys(SUBFIELD_CONFIG_OPTIONS);
	// Welche Typen wählen ein mehrzeiliges Eingabefeld (paragraph/code → textarea,
	// wysiwyg wird über eine eigene Konfigoption gesteuert, kein maxlength-Attribut).
	const SUBFIELD_TEXTAREA_TYPES = ['paragraph', 'code'];
	// Pick-spezifische Konfiguration (eigener Server-Renderzweig):
	// data (Optionsliste), Format single/multi, bei single zusätzlich dropdown/radio.
	const SUBFIELD_PICK_OPTION_FIELDS = [
		{ key: 'pick_format_type', label: 'Format', type: 'select', options: {
			single: 'Single select', multi: 'Multiple (checkboxes)' } },
		{ key: 'pick_format_type_single', label: 'Single format', type: 'select', options: {
			dropdown: 'Dropdown', radio: 'Radio buttons' } }
	];
	const SUBFIELD_PICK_OPTION_SINGLE_ONLY = ['pick_format_type_single'];

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
		container.textContent = ''; // safe: only removes children

		// All content below is built via createElement/textContent only;
		// the container is cleared with textContent (''), never with
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

	/**
	 * Parse a pick option list. Sub-field pick options are stored as a flat
	 * object { value: label } in the sub-field definition (Pods server side
	 * expects exactly that). The form edits them as an ordered list of
	 * "value|label" lines; convert in both directions on open/save.
	 *
	 * @param {Object|string|Array} data Stored pick data.
	 * @return {Object} {value: label}
	 */
	function normalizePickData(data) {
		if (!data) {
			return {};
		}
		if (typeof data === 'string') {
			// Kompatibilität: JSON-String (aus Alt-Daten oder direktem Textarea-Edit).
			try {
				const parsed = JSON.parse(data);
				return normalizePickData(parsed);
			} catch (e) {
				return {};
			}
		}
		if (Array.isArray(data)) {
			// Numerisches Array [v1, v2] → value === label.
			var out = {};
			data.forEach(function (item) {
				if (item && typeof item === 'object' && item.value !== undefined) {
					out[String(item.value)] = item.label !== undefined ? String(item.label) : String(item.value);
				} else if (item !== null && item !== undefined) {
					out[String(item)] = String(item);
				}
			});
			return out;
		}
		if (typeof data === 'object') {
			var res = {};
			Object.keys(data).forEach(function (k) {
				var v = data[k];
				res[String(k)] = v === null || v === undefined ? String(k) : String(v);
			});
			return res;
		}
		return {};
	}

	/**
	 * Parse a "value|label" textarea into an ordered {value: label} object.
	 * Lines without '|' become value === label.
	 *
	 * @param {string} text Raw lines.
	 * @return {Object}
	 */
	function parseOptionLines(text) {
		var out = {};
		(text || '').split('\n').forEach(function (line) {
			line = line.trim();
			if (!line) {
				return;
			}
			var pipe = line.indexOf('|');
			if (pipe === -1) {
				out[line] = line;
			} else {
				var value = line.slice(0, pipe).trim();
				var label = line.slice(pipe + 1).trim();
				if (value !== '') {
					out[value] = label !== '' ? label : value;
				}
			}
		});
		return out;
	}

	/**
	 * Serialize an {value: label} object back to lines for the editor textarea.
	 *
	 * @param {Object} data
	 * @return {string} One "value|label" per line.
	 */
	function optionLinesText(data) {
		var obj = normalizePickData(data);
		return Object.keys(obj).map(function (k) {
			var v = obj[k];
			return (v === String(k) ? k : k + '|' + v);
		}).join('\n');
	}

	/**
	 * Build the dynamic, type-specific option fields for the sub-field form.
	 *
	 * Rebuild model: renderExtras() calls this fresh on every type change,
	 * so the returned container holds exactly the option controls of the
	 * currently selected type (pre-set from the mutable draft). No CSS
	 * show/hide layer needed.
	 *
	 * @param {string} type  Sub-field type.
	 * @param {Object} draft Mutable draft of the edited sub-field.
	 * @return {Object} { container, out } with out: key → { wrapper, input }.
	 */
function buildTypeFields(type, draft) {
	var container = document.createElement('div');
	container.className = 'pods-repeater-subfield-extra';

	var out = {};

	var addText = function (key, labelText, placeholder, inputType) {
		var wrapper = document.createElement('label');
		wrapper.className = 'pods-repeater-subfield-config';
		wrapper.dataset.opt = key;

		var labelEl = document.createElement('span');
		labelEl.textContent = labelText + ':';
		wrapper.appendChild(labelEl);

		var input = document.createElement('input');
		input.type = inputType || 'text';
		input.className = 'pods-repeater-subfield-input';
		input.name = 'subfield_' + key;
		if (placeholder) {
			input.placeholder = placeholder;
		}
		wrapper.appendChild(input);

		container.appendChild(wrapper);
		out[key] = { wrapper: wrapper, input: input };
		return input;
	};

	var addCheckbox = function (key, labelText) {
		var wrapper = document.createElement('label');
		wrapper.className = 'pods-repeater-subfield-config';
		wrapper.dataset.opt = key;

		var input = document.createElement('input');
		input.type = 'checkbox';
		input.className = 'pods-repeater-subfield-input';
		input.name = 'subfield_' + key;

		var labelEl = document.createElement('span');
		labelEl.textContent = labelText;
		wrapper.appendChild(input);
		wrapper.appendChild(labelEl);

		container.appendChild(wrapper);
		out[key] = { wrapper: wrapper, input: input };
		return input;
	};

	var addSelect = function (key, labelText, options) {
		var wrapper = document.createElement('label');
		wrapper.className = 'pods-repeater-subfield-config';
		wrapper.dataset.opt = key;

		var labelEl = document.createElement('span');
		labelEl.textContent = labelText + ':';
		wrapper.appendChild(labelEl);

		var input = document.createElement('select');
		input.className = 'pods-repeater-subfield-input';
		input.name = 'subfield_' + key;
		Object.keys(options).forEach(function (val) {
			var opt = document.createElement('option');
				opt.value = val;
				opt.textContent = options[val];
				input.appendChild(opt);
			});
			wrapper.appendChild(input);

			container.appendChild(wrapper);
			out[key] = { wrapper: wrapper, input: input };
			return input;
		};

		// -- Required (alle Nicht-Display-Typen, wirkt in validate()). --
		if (SUBFIELD_DISPLAY_TYPES.indexOf(type) === -1) {
			var reqWrap = document.createElement('label');
			reqWrap.className = 'pods-repeater-subfield-config';
			reqWrap.dataset.opt = 'required';

			var reqInput = document.createElement('input');
			reqInput.type = 'checkbox';
			reqInput.className = 'pods-repeater-subfield-input';
			reqInput.name = 'subfield_required';
			reqInput.checked = !!(draft && draft.required);
			reqWrap.appendChild(reqInput);

			var reqLabelEl = document.createElement('span');
			reqLabelEl.textContent = 'Required (in every row)';
			reqWrap.appendChild(reqLabelEl);

			container.appendChild(reqWrap);
			out.required = { wrapper: reqWrap, input: reqInput };
		}

		// -- pick: Format-Wahl (single/multi) + bei single dropdown/radio. --
		if (type === 'pick') {
			SUBFIELD_PICK_OPTION_FIELDS.forEach(function (def) {
				var input = addSelect(def.key, def.label, def.options);
				if (draft && draft[def.key] !== undefined) {
					input.value = draft[def.key];
				}
			});
		}

		// -- Typspezifische Konfiguration aus SUBFIELD_CONFIG_OPTIONS
		// (deklarativ): text/number → input, select → Dropdown,
		// checkbox → Checkbox (harvest liest checked). --
		var cfg = SUBFIELD_CONFIG_OPTIONS[type];
		if (cfg) {
			Object.keys(cfg).forEach(function (key) {
				var def = cfg[key];
				var input;
				if (def.type === 'select') {
					input = addSelect(key, def.label, def.options);
				} else if (def.type === 'checkbox') {
					input = addCheckbox(key, def.label);
					if (draft && draft[key]) {
						input.checked = true;
					}
				} else if (def.type === 'number') {
					input = addText(key, def.label, def.placeholder, 'number');
					if (draft && draft[key] !== undefined) {
						input.value = draft[key];
					}
				} else {
					input = addText(key, def.label, def.placeholder, 'text');
					if (draft && draft[key] !== undefined) {
						input.value = draft[key];
					}
				}
				// select-Wert aus draft nachfüllen
				if (def.type === 'select' && draft && draft[key] !== undefined) {
					input.value = draft[key];
				}
			});
		}

		// -- pick: Optionsliste (data) als "value|label"-Zeilen. --
		if (type === 'pick') {
			var wrap = document.createElement('label');
			wrap.className = 'pods-repeater-subfield-config pods-repeater-subfield-data';
			wrap.dataset.opt = 'data';
			var labelEl = document.createElement('span');
			labelEl.textContent = 'Options:';
			wrap.appendChild(labelEl);
			var ta = document.createElement('textarea');
			ta.className = 'pods-repeater-subfield-input';
			ta.name = 'subfield_data';
			ta.rows = 4;
			ta.placeholder = 'value|Label\n1|Option one\n2|Option two';
			if (draft) {
				ta.value = optionLinesText(draft.data);
			}
			wrap.appendChild(ta);
			var help = document.createElement('span');
			help.className = 'description';
			help.textContent = 'One per line: value|Label (value only = same label).';
			wrap.appendChild(help);
			container.appendChild(wrap);
			out.data = { wrapper: wrap, input: ta };
		}

		// Display-Typen (heading/html): nur die Required-Checkbox fehlt oben
		// bereits per Bedingung — hier ist nichts weiter zu tun.

		return { container: container, out: out };
	}

	function openSubfieldForm(container, textarea, sub, all, editIndex) {
		// Hide the list, show the (single) editor form in the same container.
		container.textContent = ''; // safe: only removes children

		// Mutable draft of the definition being edited. buildTypeFields() fills
		// a fresh options container from it; doSave() merges the controls back
		// into it. Kept independent of the textarea until Save so Cancel really
		// discards and switching type never corrupts the stored JSON.
		var draft = sub ? JSON.parse(JSON.stringify(sub)) : {};

		var form = document.createElement('div');
		form.className = 'pods-repeater-subfield-form';

		var title = document.createElement('h4');
		title.textContent = sub ? 'Edit sub-field' : 'Add sub-field';
		form.appendChild(title);

		// Type-specific options (placeholder/maxlength/required, pick data +
		// formats) live in their own container, rendered by buildTypeFields()
		// and re-rendered on every type change. Holds only the controls of the
		// current type — no show/hide CSS needed.
		var extras = document.createElement('div');
		extras.className = 'pods-repeater-subfield-extras';

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
				if (sub && sub.type) {
					input.value = sub.type;
				} else {
					input.value = 'text'; // sensible default for "Add"
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

		form.appendChild(extras);

		// Render (and re-render on type change) the type-specific options.
		// Before rebuilding, harvest the visible controls' values into the
		// draft so a type switch does not lose what was already typed.
		var renderExtras = function () {
			var built = buildTypeFields(fields.type ? fields.type.value : 'text', draft);
			extras.textContent = ''; // safe: only removes children
			extras.appendChild(built.container);
		};

		var harvestExtras = function () {
			// Only the currently visible controls exist in the DOM; the
			// draft keeps values of previously shown types. Read every
			// control and write it into the draft.
			extras.querySelectorAll('input[name^="subfield_"], select[name^="subfield_"], textarea[name^="subfield_"]').forEach(function (el) {
				var key = el.name.slice('subfield_'.length);
				var val;
				if (el.type === 'checkbox') {
					val = el.checked;
				} else if (el.type === 'select-multiple') {
					val = Array.prototype.filter.call(el.options, function (o) { return o.selected; })
						.map(function (o) { return o.value; });
				} else if (el.tagName === 'TEXTAREA' && key === 'data') {
					// Pick option list: raw "value|Label" lines → {value:label}
					// object right away, so draft.data stays a plain object
					// (the server-side expected shape) across type switches.
					val = parseOptionLines(el.value);
				} else {
					val = el.value;
				}
				draft[key] = val;
			});
		};

		var typeSelect = fields.type;
		if (typeSelect) {
			typeSelect.addEventListener('change', function () {
				harvestExtras();
				renderExtras();
			});
		}
		renderExtras();

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
			harvestExtras();
			var entry = { name: name, type: type };
			if (label) {
				entry.label = label;
			}
			// Merge the harvested type-specific options into the entry, but
			// only those that apply to the CURRENT type (SUBFIELD_CONFIG_OPTIONS
			// + required, or the pick options for pick). Values left over in
			// the draft from a previously selected type are dropped, so a
			// type switch never leaks stale options into the stored JSON.
			var OPTION_SKIP_EMPTY = { placeholder: 1, maxlength: 1 };
			var allowed = {};
			if (type === 'pick') {
				SUBFIELD_PICK_OPTION_FIELDS.forEach(function (def) { allowed[def.key] = 1; });
				allowed.data = 1;
			} else {
				var cfg = SUBFIELD_CONFIG_OPTIONS[type] || {};
				Object.keys(cfg).forEach(function (key) { allowed[key] = 1; });
			}
			allowed.required = 1;
			Object.keys(draft).forEach(function (key) {
				var val = draft[key];
				if (!allowed[key]) {
					return; // not applicable to the current type
				}
				if (key === 'name' || key === 'type' || key === 'label') {
					return; // core keys handled above
				}
				if (typeof val === 'string' && OPTION_SKIP_EMPTY[key] && val.trim() === '') {
					return; // don't store empty placeholder/maxlength
				}
				if (key === 'required' && !val) {
					return; // unchecked → omit (server treats absent as false)
				}
				if (key === 'data') {
					// draft.data is already a {value:label} object (harvest
					// parses the textarea); store it only when non-empty.
					if (val && typeof val === 'object' && Object.keys(val).length > 0) {
						entry.data = val;
					}
					return;
				}
				if (val === '') {
					return; // skip empty generic string options
				}
				entry[key] = val;
			});
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
