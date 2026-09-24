/**
 * scripts/tests/zone_picker_test.mjs
 * OK Veggies. The delivery-area search rule in assets/js/zone-picker.js, run
 * in plain Node with no browser: case, accents, spacing, separators, notes,
 * several words, no match, and characters that would mean something to a
 * regular expression. The zones are made up on purpose, so the rule cannot
 * lean on any real area existing.
 *
 *   node scripts/tests/zone_picker_test.mjs
 */
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const require = createRequire(import.meta.url);
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const picker = require(path.join(root, 'assets/js/zone-picker.js'));

let tests = 0;
let passed = 0;
function ok(condition, label) {
  tests++;
  if (condition) { passed++; } else { console.error(`  FAIL: ${label}`); }
}
function eq(expected, actual, label) {
  const same = JSON.stringify(expected) === JSON.stringify(actual);
  ok(same, same ? label : `${label} (expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)})`);
}
const ids = (list) => list.map((zone) => zone.id);

const zones = [
  { id: '41', name: 'Harbour Side', note: 'Old Quay, Ferry Road' },
  { id: '7', name: 'Hilltop', note: '' },
  { id: '93', name: 'Riverbend East', note: 'Mill Lane, Saint-Anne Close' },
  { id: '12', name: 'Café Row', note: 'Market (North) gate' },
  { id: '5', name: 'Phase 1', note: 'Plot 1.5 and Block [C]' },
];

// Normalising
eq('harbour side', picker.normalise('  HARBOUR   Side '), 'lower case, trimmed, spaces collapsed');
eq('cafe row', picker.normalise('Café Row'), 'accents are dropped');
eq('saint anne close', picker.normalise('Saint-Anne Close'), 'a hyphen reads as a space');
eq('a b c d', picker.normalise('a/b,c_d'), 'slashes, commas and underscores read as spaces');
eq('', picker.normalise(null), 'null normalises to nothing');
eq([], picker.words('   '), 'blank space is no query');
eq(['mill', 'lane'], picker.words(' Mill   LANE '), 'a query splits into words');

// Matching by name
eq(ids(zones), ids(picker.filter(zones, '')), 'an empty query shows every zone, in the order given');
eq(ids(zones), ids(picker.filter(zones, '    ')), 'a query of only spaces shows every zone');
eq(['41'], ids(picker.filter(zones, 'harbour')), 'a name matches by substring');
eq(['41'], ids(picker.filter(zones, 'HARBOUR')), 'matching ignores case');
eq(['41'], ids(picker.filter(zones, '  harb  ')), 'surrounding space is trimmed');
eq(['7'], ids(picker.filter(zones, 'hill')), 'a partial name matches');
eq(['12'], ids(picker.filter(zones, 'cafe')), 'an unaccented query finds an accented name');
eq(['12'], ids(picker.filter(zones, 'CAFÉ')), 'an accented query finds it too');

// Matching by the database note
eq(['41'], ids(picker.filter(zones, 'ferry')), 'the note matches as well as the name');
eq(['93'], ids(picker.filter(zones, 'mill lane')), 'several words from the note match');
eq(['93'], ids(picker.filter(zones, 'saint anne')), 'a hyphenated note matches with a space');
eq(['93'], ids(picker.filter(zones, 'saint-anne')), 'and with the hyphen typed');
eq(['93'], ids(picker.filter(zones, 'east mill')), 'words can come from the name and the note together');
eq(['93'], ids(picker.filter(zones, 'lane riverbend')), 'in any order');
eq([], ids(picker.filter(zones, 'hilltop ferry')), 'every word has to match, not any one of them');

// No match
eq([], ids(picker.filter(zones, 'zzzz')), 'a query that matches nothing returns nothing');
eq([], ids(picker.filter([], 'harbour')), 'no zones, no matches');

// Characters that mean something to a regular expression are plain text here
eq([], ids(picker.filter(zones, '.*')), '".*" is not a wildcard');
eq(['12'], ids(picker.filter(zones, '(')), 'a lone open bracket does not throw and matches the note that holds one');
eq(['12'], ids(picker.filter(zones, '(north)')), 'brackets are matched literally');
eq(['5'], ids(picker.filter(zones, '[c]')), 'square brackets are matched literally');
eq([], ids(picker.filter(zones, '^harbour$')), 'anchors are not anchors');
eq([], ids(picker.filter(zones, 'a|z')), 'a pipe is not alternation');
eq([], ids(picker.filter(zones, '\\')), 'a backslash does not throw');
eq([], ids(picker.filter(zones, '<script>')), 'markup is just text to look for');
eq(['5'], ids(picker.filter(zones, 'plot 1.5')), 'a dot between digits reads as a separator, so "plot 1.5" still finds the note');
ok(picker.matches({ name: '', note: '' }, '') === true, 'an empty query matches even an empty zone');
ok(picker.matches({ name: 'X' }, 'x') === true, 'a zone with no note still matches by name');

console.log(`\n${passed} / ${tests} zone picker assertions passed.`);
process.exit(passed === tests ? 0 : 1);
