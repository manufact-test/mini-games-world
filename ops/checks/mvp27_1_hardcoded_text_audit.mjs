import fs from 'node:fs';
import path from 'node:path';

const CYRILLIC = /[\u0400-\u04FF]/;
const ROOTS = [
  { name:'client', path:'app/assets/js', extensions:new Set(['.js']) },
  { name:'client-entry', path:'app', extensions:new Set(['.php']) },
  { name:'backend', path:'bot', extensions:new Set(['.php']) },
];

const SKIP_PARTS = new Set([
  '.git','node_modules','vendor','docs','tests','test','database','migrations','admin',
]);
const SKIP_PREFIXES = [
  'app/assets/js/localization/',
  'app/locales/',
  'app/runtime/localization/',
  'app/runtime/server/', // staging-only rejected clean-runtime server; not reachable from factual Telegram v110 graph
  'bot/tests/',
  'bot/incident/',
];

const ADMIN_CONTROL_PLANE_PATTERNS = [
  /^app\/admin\.php$/,
  /^app\/assets\/js\/admin(?:-|\/)/,
  /^bot\/services\/Admin[^/]*\.php$/,
  /^bot\/helpers\/Admin[^/]*\.php$/,
  /^bot\/operations\/Admin[^/]*\.php$/,
];

function normalized(file) {
  return file.split(path.sep).join('/');
}

function shouldSkip(file) {
  const n = normalized(file);
  if (SKIP_PREFIXES.some(prefix => n.startsWith(prefix))) return true;
  if (ADMIN_CONTROL_PLANE_PATTERNS.some(pattern => pattern.test(n))) return true;
  return n.split('/').some(part => SKIP_PARTS.has(part));
}

function collect(dir, extensions, out = []) {
  if (!fs.existsSync(dir)) return out;
  for (const entry of fs.readdirSync(dir, { withFileTypes:true })) {
    const full = path.join(dir, entry.name);
    if (shouldSkip(full)) continue;
    if (entry.isDirectory()) collect(full, extensions, out);
    else if (extensions.has(path.extname(entry.name))) out.push(full);
  }
  return out;
}

const baselinePath = 'ops/checks/mvp27_1_hardcoded_text_baseline.json';
const baseline = fs.existsSync(baselinePath)
  ? JSON.parse(fs.readFileSync(baselinePath, 'utf8'))
  : null;

const findings = [];
const scanned = new Set();

for (const root of ROOTS) {
  for (const file of collect(root.path, root.extensions)) {
    const n = normalized(file);
    if (scanned.has(n)) continue;
    scanned.add(n);
    const lines = fs.readFileSync(file, 'utf8').split(/\r?\n/);
    lines.forEach((line, index) => {
      if (!CYRILLIC.test(line)) return;
      findings.push({ scope:root.name, file:n, line:index + 1, text:line.trim().slice(0,220) });
    });
  }
}

const byScope = new Map();
const byFile = new Map();
for (const finding of findings) {
  byScope.set(finding.scope, (byScope.get(finding.scope) || 0) + 1);
  byFile.set(finding.file, (byFile.get(finding.file) || 0) + 1);
}

const topFiles = [...byFile.entries()]
  .sort((a,b) => b[1] - a[1] || a[0].localeCompare(b[0]))
  .slice(0,40);

console.log('MVP27_1_HARDCODED_TEXT_AUDIT=PASS');
console.log(`MVP27_1_SCANNED_FILES=${scanned.size}`);
console.log(`MVP27_1_CYRILLIC_LINES_TOTAL=${findings.length}`);
for (const [scope,count] of [...byScope.entries()].sort()) {
  console.log(`MVP27_1_CYRILLIC_LINES_${scope.toUpperCase().replace(/[^A-Z0-9]+/g,'_')}=${count}`);
}
console.log('MVP27_1_TOP_FILES_BEGIN');
for (const [file,count] of topFiles) console.log(`${String(count).padStart(4,' ')}  ${file}`);
console.log('MVP27_1_TOP_FILES_END');

if (process.argv.includes('--sample')) {
  console.log('MVP27_1_SAMPLE_BEGIN');
  for (const finding of findings.slice(0,120)) {
    console.log(`${finding.scope}\t${finding.file}:${finding.line}\t${finding.text}`);
  }
  console.log('MVP27_1_SAMPLE_END');
}

if (scanned.size === 0) throw new Error('Localization audit scanned no runtime files.');

if (baseline) {
  const limits = [
    ['total', findings.length, Number(baseline.cyrillic_lines_total)],
    ['client', byScope.get('client') || 0, Number(baseline.by_scope?.client)],
    ['backend', byScope.get('backend') || 0, Number(baseline.by_scope?.backend)],
    ['client-entry', byScope.get('client-entry') || 0, Number(baseline.by_scope?.['client-entry'])],
  ];
  for (const [label,current,limit] of limits) {
    if (!Number.isFinite(limit)) throw new Error(`Invalid localization baseline for ${label}.`);
    if (current > limit) {
      throw new Error(`Localization debt regression: ${label} increased from baseline ${limit} to ${current}.`);
    }
  }
  console.log('MVP27_1_BASELINE_RATCHET=PASS');
}
