/**
 * Runs the HAIKUFIT test suites and prints one summary.
 *   node run.mjs            all offline suites (worker + theme)
 *   node run.mjs worker     rewards worker only (unit tests, Shopify mocked)
 *   node run.mjs theme      theme only (rendered with liquidjs, driven in Chromium)
 * Live site checks: node --test live/   (needs network access to haikufit.in)
 * Set CHROMIUM_PATH if Playwright's own browser isn't installed.
 */
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const here = path.dirname(fileURLToPath(import.meta.url));
const only = process.argv[2];

const suites = [
  { group: 'worker', name: 'Rewards worker: Dojo codes', cwd: 'worker', files: ['test.mjs'] },
  { group: 'worker', name: 'Rewards worker: store credit', cwd: 'worker', files: ['credit.mjs'] },
  { group: 'worker', name: 'Rewards worker: order tracking', cwd: 'worker', files: ['track.mjs'] },
  { group: 'worker', name: 'Rewards worker: security & limits', cwd: 'worker', files: ['sec.mjs', 'health.mjs'] },
  { group: 'theme', name: 'Theme: pages, layout, flows', cwd: 'theme', render: {}, files: ['flows.cjs', 'sweep.mjs', 'audit.cjs'] },
  { group: 'theme', name: 'Theme: cart, coupons, Cashfree note', cwd: 'theme', render: {}, files: ['coupon.mjs', 'cfnote.mjs'] },
  { group: 'theme', name: 'Theme: motion name: 'Theme: motion & gestures, katana rail' gestures, clothing rail', cwd: 'theme', render: {}, files: ['motion-test.mjs', 'rail-test.mjs'] },
  { group: 'theme', name: 'Theme: stored-data XSS', cwd: 'theme', render: {}, files: ['xss.mjs'] },
  { group: 'theme', name: 'Theme: store credit, Dojo rewards, order tracking', cwd: 'theme', render: { CREDIT_TEST: '1' }, files: ['credit-ui.mjs', 'credit-clash.mjs', 'claim.mjs', 'track-ui.mjs'] },
  { group: 'theme', name: 'Theme: size measurements & gift box', cwd: 'theme', render: { GIFT: '1' }, files: ['gift-test.mjs'] },
  { group: 'theme', name: 'Theme: cart savings breakdown', cwd: 'theme', render: { SAVINGS: '1' }, files: ['savings-test.mjs'] },
];

let lastRender = null;
const results = [];
for (const s of suites) {
  if (only && s.group !== only) continue;
  const cwd = path.join(here, s.cwd);
  if (s.render) {
    const key = JSON.stringify(s.render);
    if (key !== lastRender) {
      const r = spawnSync('node', ['render.mjs'], { cwd, env: { ...process.env, ...s.render }, encoding: 'utf8' });
      if (r.status !== 0) { results.push({ name: `${s.name} (render)`, pass: 0, fail: 1, notes: [r.stderr.slice(0, 400)] }); continue; }
      lastRender = key;
    }
  }
  let pass = 0, fail = 0; const notes = [];
  for (const f of s.files) {
    const r = spawnSync('node', [f], { cwd, env: process.env, encoding: 'utf8', timeout: 300000 });
    const out = `${r.stdout || ''}\n${r.stderr || ''}`;
    const lines = out.split('\n');
    const p = lines.filter((l) => /^PASS\b|✓/.test(l) && !/FAIL/.test(l)).length;
    const bad = lines.filter((l) => /\bFAIL\b/.test(l) || /errors?:? \[\s*'/.test(l));
    if (/sweep/.test(f) && !/clean:/.test(out)) bad.push('sweep reported layout problems');
    if (/audit/.test(f) && !/ 0 with issues/.test(out)) bad.push((out.match(/checked .*/) || ['audit found issues'])[0]);
    if (r.status !== 0) bad.push(`${f} exited with ${r.status}: ${(r.stderr || '').split('\n').find((x) => x.trim()) || ''}`);
    pass += p + (bad.length ? 0 : (/sweep|audit/.test(f) ? 1 : 0));
    fail += bad.length;
    bad.forEach((b) => notes.push(`${f}: ${b.trim().slice(0, 200)}`));
  }
  results.push({ name: s.name, pass, fail, notes });
}

let tp = 0, tf = 0;
for (const r of results) {
  tp += r.pass; tf += r.fail;
  console.log(`${r.fail ? '✗' : '✓'} ${r.name.padEnd(50)} ${String(r.pass).padStart(3)} passed${r.fail ? `, ${r.fail} failed` : ''}`);
  r.notes.forEach((n) => console.log(`    - ${n}`));
}
console.log(`\n${tp} checks passed, ${tf} failed`);
process.exit(tf ? 1 : 0);
