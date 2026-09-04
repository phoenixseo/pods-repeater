#!/usr/bin/env node
/**
 * T5-JS-Spike (Judge-Review 24e355c — Empfehlung 4).
 *
 * Verhaltens-Test der echten ui/js/repeater.js-Logik gegen ein Mini-DOM:
 *  - addRow baut neue Zeile aus Container-Namen + Index + data-sub
 *  - removeRow/moveRow → renumber baut ALLE Namen/IDs komplett neu
 *    (data-sub-Rebuild statt Regex auf bestehende Namen/IDs)
 *  - Regression: Feldname, der selbst ein '_ziffer_'-Muster enthält
 *    ('sect_2_field'), wird NICHT korrumpiert (alte /_\d+_/-Regex hätte
 *    'sect_2_field' → 'sect_0_field' umgeschrieben, da '_2_' zuerst matcht).
 *
 * Ausführung: node tests/spike-t5-js.js   (kein wp-env nötig, kein DOM nötig)
 */

'use strict';

const fs = require('fs');
const path = require('path');

let pass = 0;
let fail = 0;
function check(label, ok, detail) {
  if (ok) {
    pass++;
    console.log('PASS  ' + label);
  } else {
    fail++;
    console.log('FAIL  ' + label + (detail !== undefined ? '  → ' + detail : ''));
  }
}

/* ------------------------------------------------------------------ */
/* Mini-DOM: gerade genug für repeater.js (query/all, closest, remove, */
/* insertBefore, cloneNode, dataset, parent-Navigation).               */
/* ------------------------------------------------------------------ */
class El {
  constructor(tagName, attrs = {}, children = []) {
    this.tagName = String(tagName).toUpperCase();
    this.dataset = {};
    this.children = [];
    this.parent = null;
    this.value = '';
    this.selectedIndex = 0;
    this.classList = [];
    this.name = '';
    this.id = '';
    for (const [k, v] of Object.entries(attrs)) {
      if (k === 'class') this.classList = String(v).split(/\s+/).filter(Boolean);
      else if (k === 'data-sub') this.dataset.sub = v;
      else if (k === 'data-name') this.dataset.name = v;
      else if (k === 'data-index') this.dataset.index = v;
      else if (k === 'data-limit') this.dataset.limit = v;
      else if (k === 'value') this.value = v;
      else this[k] = v;
    }
    for (const c of children) this.appendChild(c);
  }

  appendChild(c) {
    if (c.parent) c.parent.removeChild(c);
    c.parent = this;
    this.children.push(c);
    return c;
  }

  removeChild(c) {
    const i = this.children.indexOf(c);
    if (i >= 0) {
      this.children.splice(i, 1);
      c.parent = null;
    }
    return c;
  }

  remove() {
    // Echten DOM nicht 1:1 nachbildbar: closest() nach remove() muss noch
    // den letzten Parent kennen (repeater.js: removeRow ruft row.remove()
    // und dann row.closest('.pods-repeater-rows')). In echtem DOM bleibt
    // die Referenz über das entfernte Element erhalten; hier merken wir
    // uns den Parent explizit.
    if (this.parent) {
      this._removedFrom = this.parent;
      this.parent.removeChild(this);
    }
  }

  matches(sel) {
    if (sel === '[data-sub]') return this.dataset.sub !== undefined;
    if (sel.startsWith('.')) {
      const cls = sel.slice(1);
      return this.classList.indexOf(cls) !== -1;
    }
    return false;
  }

  querySelectorAll(sel) {
    const out = [];
    const walk = (node) => {
      for (const child of node.children) {
        if (child.matches(sel)) out.push(child);
        walk(child);
      }
    };
    walk(this);
    return out;
  }

  querySelector(sel) {
    return this.querySelectorAll(sel)[0] || null;
  }

  closest(sel) {
    let n = this;
    while (n) {
      if (n.matches(sel)) return n;
      // Nach remove(): Kette über den gemerkten letzten Parent fortsetzen.
      n = n.parent || n._removedFrom || null;
    }
    return null;
  }

  get previousElementSibling() {
    if (!this.parent) return null;
    const i = this.parent.children.indexOf(this);
    return i > 0 ? this.parent.children[i - 1] : null;
  }

  get nextElementSibling() {
    if (!this.parent) return null;
    const i = this.parent.children.indexOf(this);
    return i >= 0 && i < this.parent.children.length - 1 ? this.parent.children[i + 1] : null;
  }

  insertBefore(node, ref) {
    // Echte DOM-Semantik: this ist der PARENT, node wird Kind von this,
    // direkt vor ref einsortiert. (Die frühere Variante behandelte this
    // als Referenz-Kind und schob node in den Großeltern-Container —
    // dadurch „verschwanden" Zeilen bei moveRow aus rows.)
    if (node.parent) node.parent.removeChild(node);
    node.parent = this;
    const i = this.children.indexOf(ref);
    if (i < 0) this.children.push(node);
    else this.children.splice(i, 0, node);
    return node;
  }

  insertChildBefore(node, ref) {
    // Wird von insertBefore nicht mehr benötigt; direkt aufs Kind-Array.
    return this.insertBefore(node, ref);
  }

  cloneNode(deep) {
    const c = new El(this.tagName);
    c.dataset = Object.assign({}, this.dataset);
    c.classList = this.classList.slice();
    c.name = this.name;
    c.id = this.id;
    c.value = this.value;
    c.selectedIndex = this.selectedIndex;
    if (deep) {
      for (const child of this.children) {
        c.appendChild(child.cloneNode(true));
      }
    }
    return c;
  }
}

/* ------------------------------------------------------------------ */
/* repeater.js laden — nur die schließende IIFE-Zeile wird für den     */
/* Test durch einen Export der internen Funktionen ersetzt.            */
/* ------------------------------------------------------------------ */
const srcPath = path.join(__dirname, '..', 'ui', 'js', 'repeater.js');
let src = fs.readFileSync(srcPath, 'utf8');
// Kein abschließendes Semikolon nach `})()` erzeugen: Der Wrapper unten
// (`return (…);`) hängt sein eigenes an — `})(););` wäre sonst ein
// Syntaxfehler.
const exported = src.replace(/\}\)\(\)\;\s*$/, "  return { renumber, addRow, removeRow, moveRow };\n})()");
if (exported === src) {
  console.log('FAIL  Harness: IIFE-Ende nicht gefunden — Export-Ersetzung fehlgeschlagen');
  process.exit(1);
}
const fakeDocument = { addEventListener: () => {} };
// Sicherheit: `exported` ist ausschließlich der statische, dev-kontrollierte
// Inhalt von ui/js/repeater.js (aus dem eigenen Repo gelesen) — keine
// untrusted Eingabe im Funktionskörper. Reiner Test-Harness, kein Produktionscode.
// `return (` mit Klammer direkt auf derselben Zeile: Ein nacktes
// 'return ' + Quelle würde durch die JSDoc-Zeilenumbrüche am Dateikopf
// ASI auslösen (implizites Semikolon nach return → undefined). Die
// öffnende Klammer direkt hinter return verhindert das.
const api = new Function('document', 'return (' + exported + ');')(fakeDocument);

/* ------------------------------------------------------------------ */
/* Aufbau: Repeater mit Feldname 'sect_2_field' (enthält '_2_'-Muster) */
/* ------------------------------------------------------------------ */
const subField = (name, sub) =>
  new El('input', {
    name: name + '[0][' + sub + ']',
    id: name + '_0_' + sub,
    'data-sub': sub,
    value: name === 'sect_2_field' && sub === 'sub' ? 'A' : 'B',
  });

function buildRepeater() {
  const fieldName = 'sect_2_field'; // '_ziffer_'-Muster im Feldnamen (Regression)
  const row0 = new El('div', { class: 'pods-repeater-row', 'data-index': 0 }, [
    subField(fieldName, 'sub'),
    subField(fieldName, 'title'),
    new El('button', { class: 'pods-repeater-remove', type: 'button' }),
    new El('button', { class: 'pods-repeater-move-up', type: 'button' }),
    new El('button', { class: 'pods-repeater-move-down', type: 'button' }),
  ]);
  const rows = new El('div', { class: 'pods-repeater-rows', 'data-name': fieldName }, [row0]);
  const container = new El('div', { class: 'pods-repeater', 'data-limit': '0' }, [rows]);
  return { container, rows, fieldName };
}

function inputsOf(rows) {
  return rows.querySelectorAll('[data-sub]');
}

console.log('=== JS renumber/addRow/removeRow/moveRow (Empfehlung 4) ===');
console.log('Feldname mit _ziffer_-Muster: "sect_2_field"\n');

/* 1) addRow: neue Zeile bekommt sauberen Namen/ID + leeren Wert */
{
  const { container, rows } = buildRepeater();
  api.addRow(container);
  const all = inputsOf(rows);
  check('addRow: 2 Zeilen vorhanden', rows.children.length === 2, 'rows=' + rows.children.length);
  const newSub = all.find((i) => i.name === 'sect_2_field[1][sub]');
  check('addRow: neue Zeile name "sect_2_field[1][sub]"', !!newSub);
  check('addRow: neue Zeile id "sect_2_field_1_sub"', !!newSub && newSub.id === 'sect_2_field_1_sub');
  check('addRow: neue Zeile value geleert', !!newSub && newSub.value === '');
  check('addRow: Zeile-0 unverändert', all.find((i) => i.name === 'sect_2_field[0][sub]') !== undefined);
}

/* 2) removeRow + renumber: Restzeilen werden neu nummeriert, Feldname
 *    'sect_2_field' darf NICHT korrumpiert werden (alte Regex: '_2_' zuerst). */
{
  const { container, rows } = buildRepeater();
  // Drei Zeilen aufbauen (addRow zweimal), dann Zeile 0 entfernen.
  api.addRow(container);
  api.addRow(container);
  check('removeRow-Setup: 3 Zeilen', rows.children.length === 3);

  const firstRow = rows.children[0];
  const removeBtn = firstRow.querySelector('.pods-repeater-remove');
  api.removeRow(removeBtn);

  check('removeRow: 2 Zeilen verbleiben', rows.children.length === 2);
  const all = inputsOf(rows);
  // Zeile 0 (Wert A/B) wurde entfernt; die beiden verbliebenen Klone sind
  // leer. Entscheidend ist die korrekte Neu-Nummerierung, nicht ein Wert.
  const row0HasSub = all.some((i) => i.name === 'sect_2_field[0][sub]');
  const row0HasTitle = all.some((i) => i.name === 'sect_2_field[0][title]');
  check('removeRow+renumber: ehemalige Zeile 1 ist jetzt [0]', row0HasSub && row0HasTitle,
    all.map((i) => i.name + '=' + i.value).join(', '));
  const noGap = all.some((i) => i.name === 'sect_2_field[1][title]');
  check('removeRow+renumber: zweite Restzeile ist [1]', noGap);
  check('renumber: Feldname "_2_" NICHT korrumpiert (kein sect_0_field)',
    all.every((i) => i.name.indexOf('sect_2_field[') === 0 && i.id.indexOf('sect_2_field_') === 0),
    all.map((i) => i.id).join(', '));
  check('renumber: data-index 0/1 fortlaufend',
    rows.children[0].dataset.index === '0' && rows.children[1].dataset.index === '1');
  check('renumber: keine alte Index-Lücke (kein [2])',
    all.every((i) => !/\[2\]/.test(i.name)));
}

/* 3) moveRow down/up + renumber */
{
  const { container, rows } = buildRepeater();
  api.addRow(container);
  api.addRow(container); // 3 Zeilen: A(0), leer(1), leer(2)
  const upBtn = rows.children[2].querySelector('.pods-repeater-move-up');
  api.moveRow(upBtn, -1); // Zeile 2 → Position 1

  check('moveRow-up: Reihenfolge [0]=A,[1]=leer,[2]=leer', rows.children[0].children[0].value === 'A');
  const all = inputsOf(rows);
  const ordered = all.filter((i) => i.dataset.sub === 'sub').map((i) => i.name);
  check('moveRow-up+renumber: Namen [0]/[1]/[2] fortlaufend',
    JSON.stringify(ordered) === JSON.stringify([
      'sect_2_field[0][sub]',
      'sect_2_field[1][sub]',
      'sect_2_field[2][sub]',
    ]), ordered.join(', '));

  // Remove der obersten (Wert A) → danach muss alles auf 0 starten.
  api.removeRow(rows.children[0].querySelector('.pods-repeater-remove'));
  const namesAfter = inputsOf(rows).filter((i) => i.dataset.sub === 'sub').map((i) => i.name);
  check('removeRow nach move: [0]/[1] ohne Lücke',
    JSON.stringify(namesAfter) === JSON.stringify(['sect_2_field[0][sub]', 'sect_2_field[1][sub]']),
    namesAfter.join(', '));
}

/* 4) Element ohne data-sub bleibt unangetastet (kein sub → kein Rebuild) */
{
  const { rows } = buildRepeater();
  const ghost = new El('input', { name: 'sect_2_field[0][extra]', id: 'sect_2_field_0_extra' }); // kein data-sub
  rows.children[0].appendChild(ghost);
  api.renumber(rows);
  check('renumber: Input ohne data-sub unverändert',
    ghost.name === 'sect_2_field[0][extra]' && ghost.id === 'sect_2_field_0_extra',
    ghost.name + ' / ' + ghost.id);
}

/* 5) Leeres data-name → keine kaputten Namen (kein "undefined") */
{
  const row = new El('div', { class: 'pods-repeater-row' }, [subField('', 'sub')]);
  const rows = new El('div', { class: 'pods-repeater-rows' }, [row]); // kein data-name
  api.renumber(rows);
  const sub = inputsOf(rows)[0];
  check('renumber: ohne data-name kein "undefined"-Name',
    sub.name === '[0][sub]' && sub.name.indexOf('undefined') === -1, sub.name + ' / ' + sub.id);
}

console.log('');
console.log('ERGEBNIS: ' + pass + ' PASS, ' + fail + ' FAIL');
console.log(fail === 0 ? 'T5-JS ERFOLGREICH' : 'T5-JS FEHLGESCHLAGEN');
process.exit(fail === 0 ? 0 : 1);
