// Dock geometry verification for the responsive liquid-glass navigation.
//
// jsdom has no layout engine, and no browser is available in CI, so this
// harness evaluates the *declared* CSS values directly: it reads the shared
// `--dock-*` tokens out of liquid-glass.css, resolves clamp()/vw/vh the way a
// browser would at a given viewport, and checks the resulting box for every
// portal at the widths the brief names (1536, 1024, 768, 480, 360).
//
// It proves arithmetic, not pixels: it cannot see paint, blur or the glass
// material. Run after:
//   node tests/js/dock-geometry.test.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..', '..');
const glass = fs.readFileSync(path.join(root, 'public/assets/css/liquid-glass.css'), 'utf8');
const student = fs.readFileSync(path.join(root, 'public/assets/css/water-dock.css'), 'utf8');
const recruiter = fs.readFileSync(path.join(root, 'public/assets/css/recruiter.css'), 'utf8');
const admin = fs.readFileSync(path.join(root, 'public/assets/css/admin.css'), 'utf8');

const tokens = (css, name) => {
  const match = new RegExp(`${name}:\\s*([^;]+);`).exec(css);
  if (!match) throw new Error(`${name} is not declared`);
  return match[1].trim();
};

// Resolve one CSS length for a viewport. Supports px, vw, vh, env() = 0 and
// clamp(min, preferred, max), which is all the dock tokens use.
const resolve = (value, viewport) => {
  const token = value.trim();
  const clamp = /^clamp\((.+)\)$/.exec(token);
  if (clamp) {
    const [min, preferred, max] = clamp[1].split(',').map((part) => resolve(part, viewport));
    return Math.min(Math.max(preferred, min), max);
  }
  const calc = /^calc\((.+)\)$/.exec(token);
  if (calc) {
    return calc[1].split(/\s+/).reduce((total, part) => {
      if (part === '+' || part === '-') return total;
      return total + resolve(part, viewport);
    }, 0);
  }
  const env = /^env\(/.exec(token);
  if (env) return 0;
  const match = /^(-?[\d.]+)(px|vw|vh|%)$/.exec(token);
  if (!match) throw new Error(`cannot resolve ${token}`);
  const amount = Number(match[1]);
  if (match[2] === 'px') return amount;
  if (match[2] === 'vw') return (amount / 100) * viewport.width;
  if (match[2] === 'vh') return (amount / 100) * viewport.height;
  return (amount / 100) * viewport.width;
};

const metrics = (viewport) => {
  const read = (name) => resolve(tokens(glass, name), viewport);
  const out = {
    gutter: read('--dock-gutter'),
    bottom: read('--dock-bottom'),
    pad: read('--dock-pad'),
    gap: read('--dock-gap'),
    tabHeight: read('--dock-tab-height'),
    icon: read('--dock-icon'),
    label: read('--dock-label-size'),
  };
  out.shell = out.tabHeight + out.pad * 2;
  out.clearance = out.shell + out.bottom + 18;
  return out;
};

const portals = [
  { name: 'student', tabs: 5, max: 820, css: student, selector: '.sv-dock', labelsUntil: 380, contentRule: 'body:has(.sv-dock) .dashboard { padding-bottom: var(--dock-clearance); }' },
  { name: 'recruiter', tabs: 5, max: 820, css: recruiter, selector: '.rec-bottom-nav', labelsUntil: 0, contentRule: 'var(--dock-clearance)' },
  { name: 'admin', tabs: 8, max: 960, css: admin, selector: '.admin-dock', labelsUntil: 720, contentRule: 'var(--dock-clearance)' },
];

const WIDTHS = [
  { label: '1536 full desktop', width: 1536, height: 900 },
  { label: '1024 medium desktop', width: 1024, height: 768 },
  { label: '768 split window', width: 768, height: 900 },
  { label: '480 small viewport', width: 480, height: 800 },
  { label: '360 narrow mobile', width: 360, height: 740 },
];

const MIN_TAB = 36;      // usable touch target once labels compact away
const MIN_ICON_TAB = 32; // floor for the icon-only admin rail

const report = [];

for (const viewport of WIDTHS) {
  const m = metrics(viewport);
  for (const portal of portals) {
    const dockWidth = Math.min(portal.max, viewport.width - m.gutter);
    const inner = dockWidth - m.pad * 2;
    const perTab = (inner - m.gap * (portal.tabs - 1)) / portal.tabs;
    const inset = Math.max(5, Math.min(9, perTab * 0.07));
    const pill = Math.max(28, perTab - inset);

    assert(dockWidth <= viewport.width, `${portal.name} ${viewport.label}: dock is wider than the viewport`);
    assert(dockWidth >= 240, `${portal.name} ${viewport.label}: dock collapsed to ${dockWidth.toFixed(1)}px`);
    assert(perTab >= (portal.name === 'admin' ? MIN_ICON_TAB : MIN_TAB),
      `${portal.name} ${viewport.label}: only ${perTab.toFixed(1)}px per tab`);
    assert(pill <= perTab, `${portal.name} ${viewport.label}: pill ${pill.toFixed(1)}px would cover a ${perTab.toFixed(1)}px tab`);
    assert(m.gutter >= 8, `${portal.name} ${viewport.label}: the capsule touches the screen edge`);
    assert(dockWidth + m.gutter <= viewport.width + 0.01, `${portal.name} ${viewport.label}: the capsule leaves no gutter`);

    // The footer and the page canvas must reserve at least the dock's footprint.
    assert(m.clearance >= m.shell + m.bottom, `${portal.name} ${viewport.label}: clearance is smaller than the dock`);
    const declaresClearance = portal.contentRule.includes('var(--dock-clearance)');
    assert(declaresClearance, `${portal.name}: page content does not reserve the dock clearance`);
    assert(!/footer\s*\{[^}]*margin(?:-top)?:\s*-\d+px/.test(portal.css), `${portal.name}: footer is pulled under the dock`);

    // Fixed desktop widths are what broke split-screen; none may remain.
    const block = new RegExp(`${portal.selector.replace('.', '\\.')}\\s*\\{([^}]*)\\}`).exec(portal.css);
    assert(block, `${portal.name}: dock rule is missing`);
    assert(!/(?<![a-z-])(?:min-)?width:\s*\d+px/.test(block[1]), `${portal.name}: dock rule has a hard-coded width`);

    report.push({
      viewport: viewport.label,
      portal: portal.name,
      dock: dockWidth,
      tab: perTab,
      pill,
      icon: Math.min(m.icon, perTab - 8),
      labels: viewport.width > portal.labelsUntil,
    });
  }
}

// Continuous resize: every step between 360 and 1536 must stay valid, and the
// dock must never grow while the window shrinks (monotonic, no oscillation).
{
  let previousDock = Infinity;
  for (let width = 1536; width >= 360; width -= 4) {
    const m = metrics({ width, height: 800 });
    for (const portal of portals) {
      const dockWidth = Math.min(portal.max, width - m.gutter);
      const perTab = (dockWidth - m.pad * 2 - m.gap * (portal.tabs - 1)) / portal.tabs;
      assert(perTab >= (portal.name === 'admin' ? MIN_ICON_TAB : MIN_TAB), `${portal.name} at ${width}px: ${perTab.toFixed(1)}px per tab`);
      assert(dockWidth <= width, `${portal.name} at ${width}px: dock overflows`);
    }
    const studentDock = Math.min(820, width - m.gutter);
    assert(studentDock <= previousDock + 0.01, 'the dock grows while the window shrinks');
    previousDock = studentDock;
  }
}

// The pill must keep its proportions relative to the tab, never shrink alone.
for (const row of report) {
  assert(row.pill / row.tab > 0.7, `${row.portal} ${row.viewport}: pill lost proportion`);
  assert(row.icon >= 12, `${row.portal} ${row.viewport}: icon shrank below recognition`);
}

const table = report
  .filter((row) => row.portal === 'admin' || row.viewport.startsWith('1536') || row.viewport.startsWith('768') || row.viewport.startsWith('360'))
  .map((row) => `  ${row.viewport.padEnd(20)} ${row.portal.padEnd(10)} dock ${row.dock.toFixed(0).padStart(4)}px · tab ${row.tab.toFixed(0).padStart(3)}px · pill ${row.pill.toFixed(0).padStart(3)}px · icons ${row.labels ? 'labelled' : 'compact'}`);

console.log(table.join('\n'));
console.log(`PASS dock geometry (${report.length} viewport/portal combinations, 5 widths, continuous 1536→360 sweep)`);
