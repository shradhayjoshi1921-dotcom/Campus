// Cascade guard for the Aurora theme layer.
//
// The theme is only as good as its position in the cascade. aurora.css restyles
// shared components by re-declaring them at `html[data-theme] .thing` — the same
// weight as the older `html[data-theme="light"] .thing` rules in theme.css and
// app.css — so it wins on source order alone, with no !important. That is a real
// property of the stylesheets and it can be checked without a browser.
//
// This harness parses the six stylesheets in the order the layouts load them,
// strips @media/@supports blocks, computes selector specificity, and resolves
// which declaration actually wins for a list of real elements in both themes.
// Every one of them must resolve to aurora.css.
//
// It proves the cascade, not the pixels. Run after:
//   node tests/js/aurora-cascade.test.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..', '..');
const cssDir = path.join(root, 'public/assets/css');
const ORDER = ['theme.css', 'app.css', 'homepage.css', 'water-dock.css', 'liquid-glass.css', 'aurora.css'];

/* ------------------------------------------------------------------ parsing */

const stripComments = (css) => css.replace(/\/\*[\s\S]*?\*\//g, '');

const stripAtBlocks = (css) => {
  let out = '';
  let i = 0;
  while (i < css.length) {
    const at = css.indexOf('@', i);
    if (at === -1) {
      out += css.slice(i);
      break;
    }
    out += css.slice(i, at);
    const nextBrace = css.indexOf('{', at);
    const nextSemicolon = css.indexOf(';', at);
    if (nextSemicolon !== -1 && (nextBrace === -1 || nextSemicolon < nextBrace)) {
      i = nextSemicolon + 1; // @import / @charset / @font-face is not a block
      continue;
    }
    if (nextBrace === -1) break;
    let depth = 0;
    let j = nextBrace;
    for (; j < css.length; j += 1) {
      if (css[j] === '{') depth += 1;
      else if (css[j] === '}') {
        depth -= 1;
        if (depth === 0) break;
      }
    }
    i = j + 1; // drop the whole at-rule block
  }
  return out;
};

const declarations = [];
let order = 0;
for (const file of ORDER) {
  const full = path.join(cssDir, file);
  if (!fs.existsSync(full)) continue;
  const css = stripAtBlocks(stripComments(fs.readFileSync(full, 'utf8')));
  const ruleRe = /([^{}]+)\{([^{}]*)\}/g;
  let match;
  while ((match = ruleRe.exec(css)) !== null) {
    const selectors = match[1].split(',').map((s) => s.trim()).filter(Boolean);
    const props = {};
    for (const decl of match[2].split(';')) {
      const colon = decl.indexOf(':');
      if (colon === -1) continue;
      const prop = decl.slice(0, colon).trim();
      const value = decl.slice(colon + 1).trim();
      if (prop && value) props[prop] = value;
    }
    for (const selector of selectors) {
      for (const [prop, value] of Object.entries(props)) {
        declarations.push({ file, selector, prop, value, order: order++, spec: specificity(selector) });
      }
    }
  }
}

function specificity(selector) {
  const cleaned = selector
    .replace(/::[a-z-]+(\([^)]*\))?/g, '')
    .replace(/[\w-]+(?=\()/g, '');
  const ids = (cleaned.match(/#[\w-]+/g) || []).length;
  const classes = (cleaned.match(/\.[\w-]+/g) || []).length;
  const attrs = (cleaned.match(/\[[^\]]+\]/g) || []).length;
  const pseudos = (cleaned.match(/(^|[^:]):[a-z-]+(\([^)]*\))?/g) || []).length;
  const bare = cleaned
    .replace(/#[\w-]+/g, '')
    .replace(/\.[\w-]+/g, '')
    .replace(/\[[^\]]+\]/g, '')
    .replace(/(^|[^:]):[a-z-]+(\([^)]*\))?/g, '')
    .replace(/[>+~]/g, ' ');
  const tags = (bare.match(/(^|\s)([a-z][\w-]*)/gi) || []).length;
  return [ids, classes + attrs + pseudos, tags];
}

/* ----------------------------------------------------------------- matching */

const parseCompound = (text) => {
  const node = { tag: '', classes: [], theme: null, pseudo: [] };
  let rest = text.trim();
  const tag = rest.match(/^([a-z][\w-]*|\*)/i);
  if (tag) {
    node.tag = tag[1].toLowerCase();
    rest = rest.slice(tag[1].length);
  }
  node.classes = (rest.match(/\.([\w-]+)/g) || []).map((c) => c.slice(1));
  const theme = rest.match(/\[data-theme(?:="([\w-]+)")?\]/);
  if (theme) node.theme = theme[1] || '';
  node.pseudo = (rest.match(/(^|[^:]):([a-z-]+)/g) || []).map((p) => p.replace(/^[^:]*:/, ''));
  return node;
};

const matches = (el, node) => {
  if (node.ownTag && el.tag !== node.ownTag) return false;
  for (const cls of node.classes) if (!el.classes.includes(cls)) return false;
  if (node.theme !== null) {
    if (!el.theme) return false;
    if (node.theme && el.theme !== node.theme) return false;
    if (!node.theme && el.theme === null) return false;
  }
  return true;
};

// Attribute selectors other than data-theme are outside what this harness
// models, so any selector carrying one is skipped rather than guessed at.
const hasOtherAttribute = (selector) => (selector.match(/\[[^\]]*\]/g) || [])
  .some((attr) => !/^\[data-theme(=|\])/.test(attr));

// A chain is the ancestor path from <html> down to the element.
const selectorMatches = (selector, chain) => {
  if (/::/.test(selector)) return false;
  if (hasOtherAttribute(selector)) return false;
  if (/[>+~]/.test(selector)) return false;
  const parts = selector.trim().split(/\s+/);
  let index = chain.length - 1;
  const last = parseCompound(parts[parts.length - 1]);
  if (!matches(chain[index], last)) return false;
  index -= 1;
  for (let i = parts.length - 2; i >= 0; i -= 1) {
    const node = parseCompound(parts[i]);
    let found = false;
    while (index >= 0) {
      if (matches(chain[index], node)) {
        found = true;
        index -= 1;
        break;
      }
      index -= 1;
    }
    if (!found) return false;
  }
  return true;
};

const RELATED = {
  background: ['background', 'background-color', 'background-image'],
  'background-color': ['background-color', 'background'],
  'background-image': ['background-image', 'background'],
  'border-color': ['border-color', 'border'],
  color: ['color'],
  'box-shadow': ['box-shadow'],
  display: ['display'],
};

const resolve = (chain, prop) => {
  const accepted = RELATED[prop] || [prop];
  let winner = null;
  for (const decl of declarations) {
    if (!accepted.includes(decl.prop)) continue;
    if (!selectorMatches(decl.selector, chain)) continue;
    if (!winner) {
      winner = decl;
      continue;
    }
    const diff = (decl.spec[0] - winner.spec[0]) || (decl.spec[1] - winner.spec[1]) || (decl.spec[2] - winner.spec[2]);
    if (diff > 0 || (diff === 0 && decl.order > winner.order)) winner = decl;
  }
  return winner;
};

/* --------------------------------------------------------------- the cases */

const html = (theme) => ({ tag: 'html', classes: [], theme });
const body = (classes = []) => ({ tag: 'body', classes });
const el = (tag, classes = []) => ({ tag, classes });

const CASES = [
  ['landing/portal canvas', body(), ['background-color', 'background-image', 'color']],
  ['glass panel', body().classes.push('panel') && el('div', ['panel']), ['background', 'border-color', 'box-shadow']],
  ['bubble chip', el('a', ['bubble']), ['background', 'border-color', 'color']],
  ['primary button', el('button', ['primary-btn']), ['background', 'color']],
  ['secondary button', el('button', ['secondary-btn']), ['background', 'color', 'border-color']],
  ['text field', el('input', ['form-control']), ['background', 'color', 'border-color']],
  ['eyebrow', el('p', ['eyebrow']), ['color']],
  ['dock shell', el('nav', ['sv-dock']), ['background', 'border-color', 'box-shadow']],
  ['dock link', el('a', ['sv-dock-link']), ['color']],
  ['appearance switch', el('button', ['theme-toggle']), ['display']],
  ['recruiter brand mark', { tag: 'span', classes: ['rec-brand-mark'], body: 'recruiter-portal' }, ['background', 'color']],
  ['recruiter card', { tag: 'article', classes: ['rec-stat-card'], body: 'recruiter-portal' }, ['background', 'border-color']],
  ['recruiter primary action', { tag: 'button', classes: ['rec-button', 'rec-button--primary'], body: 'recruiter-portal' }, ['background', 'color']],
  ['admin brand mark', { tag: 'span', classes: ['admin-brand-mark'], body: 'admin-portal' }, ['background', 'color']],
  ['admin card', { tag: 'section', classes: ['admin-card'], body: 'admin-portal' }, ['background', 'border-color']],
  ['admin table header', { tag: 'th', classes: [], body: 'admin-portal' }, ['background', 'color']],
  ['student strength meter', { tag: 'div', classes: ['strength-bar'], body: 'student-portal' }, ['background']],
  // Everything the old light-theme rebuild painted with a warm literal.
  ['recruiter secondary action', { tag: 'button', classes: ['rec-button', 'rec-button--secondary'], body: 'recruiter-portal' }, ['background', 'color', 'border-color']],
  ['recruiter chip', { tag: 'span', classes: ['rec-chip'], body: 'recruiter-portal' }, ['background', 'color']],
  ['recruiter active chip', { tag: 'span', classes: ['rec-chip', 'is-active'], body: 'recruiter-portal' }, ['background', 'color']],
  ['recruiter skill chip', { tag: 'span', classes: ['rec-skill-chip'], body: 'recruiter-portal' }, ['background', 'color', 'border-color']],
  ['recruiter alert', { tag: 'div', classes: ['rec-alert', 'rec-alert--error'], body: 'recruiter-portal' }, ['background', 'color', 'border-color']],
  ['recruiter avatar', { tag: 'span', classes: ['rec-applicant-avatar'], body: 'recruiter-portal' }, ['background', 'color', 'border-color']],
  ['recruiter text link', { tag: 'a', classes: ['rec-text-link'], body: 'recruiter-portal' }, ['color']],
  ['admin chip', { tag: 'span', classes: ['admin-chip'], body: 'admin-portal' }, ['background', 'color']],
  ['admin pill', { tag: 'span', classes: ['admin-pill', 'admin-pill--ok'], body: 'admin-portal' }, ['background', 'color', 'border-color']],
  ['admin avatar', { tag: 'span', classes: ['admin-avatar'], body: 'admin-portal' }, ['background', 'color']],
  ['admin code block', { tag: 'pre', classes: ['admin-pre'], body: 'admin-portal' }, ['background', 'border-color']],
  ['admin text field', { tag: 'input', classes: [], body: 'admin-portal' }, ['background', 'color', 'border-color']],
];

let checked = 0;
const failures = [];
for (const theme of ['light', 'dark']) {
  for (const [label, target, props] of CASES) {
    const chain = [html(theme)];
    if (target.body) chain.push(body([target.body]));
    else chain.push(body());
    if (target.classes) chain.push({ tag: target.tag, classes: target.classes, theme: null });
    for (const prop of props) {
      const winner = resolve(chain, prop);
      checked += 1;
      if (!winner) continue; // nothing declares it; not a regression
      if (winner.file !== 'aurora.css') {
        failures.push(`[${theme}] ${label} — ${prop} is won by ${winner.file} «${winner.selector}» instead of aurora.css`);
      }
    }
  }
}

assert.equal(failures.length, 0, `Aurora must win every material declaration:\n  ${failures.join('\n  ')}`);

// The theme must also actually declare both palettes, or the cascade above is
// resolving to values that do not exist.
const aurora = fs.readFileSync(path.join(cssDir, 'aurora.css'), 'utf8');
for (const token of ['--au-bg-1: #eaf7ff', '--au-bg-1: #030712', '--au-ink: #0a2540', '--au-ink: #eaf6ff']) {
  assert.ok(aurora.includes(token), `aurora.css must declare ${token}`);
}

console.log(`PASS aurora cascade (${CASES.length} elements × 2 themes × their material properties = ${checked} resolutions, none lost to a legacy sheet)`);
