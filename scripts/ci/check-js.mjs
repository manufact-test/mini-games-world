import { execFileSync, spawnSync } from 'node:child_process';
import { dirname, extname, join, normalize, resolve, relative, sep } from 'node:path';
import { existsSync, readFileSync } from 'node:fs';

const root = process.cwd();
const toRepoPath = (value) => relative(root, value).split(sep).join('/');
const normalizeRepoPath = (value) => normalize(value).split(sep).join('/').replace(/^\.\//, '');

function trackedFiles(pattern = '*') {
  const output = execFileSync('git', ['ls-files', '-z', pattern]);
  return output
    .toString('utf8')
    .split('\0')
    .filter(Boolean)
    .map(normalizeRepoPath)
    .sort();
}

const allFiles = new Set(trackedFiles());
const jsFiles = trackedFiles('*.js');
const errors = [];
const explicitVersions = new Map();
const criticalReferences = new Map();

const criticalSingletons = new Set([
  'app/assets/js/state.js',
  'app/assets/js/router.js',
  'app/assets/js/screens/game-screen.js',
]);

function importSpecifiers(source) {
  const values = [];
  const patterns = [
    /\b(?:import|export)\s+(?:[^'"\n]*?\s+from\s*)?['"]([^'"]+)['"]/g,
    /\bimport\s*\(\s*['"]([^'"]+)['"]\s*\)/g,
  ];

  for (const pattern of patterns) {
    for (const match of source.matchAll(pattern)) values.push(match[1]);
  }

  return values;
}

function resolveTrackedImport(importer, specifier) {
  const cleanSpecifier = specifier.split('#', 1)[0].split('?', 1)[0];
  const absoluteBase = resolve(root, dirname(importer), cleanSpecifier);
  const baseRepoPath = toRepoPath(absoluteBase);
  const candidates = extname(baseRepoPath)
    ? [baseRepoPath]
    : [baseRepoPath, `${baseRepoPath}.js`, join(baseRepoPath, 'index.js').split(sep).join('/')];

  for (const candidate of candidates.map(normalizeRepoPath)) {
    if (allFiles.has(candidate) || existsSync(resolve(root, candidate))) return candidate;
  }

  return null;
}

function versionFromSpecifier(specifier) {
  const match = specifier.match(/[?&]v=([^&#]+)/);
  return match ? match[1] : null;
}

for (const file of jsFiles) {
  const source = readFileSync(file, 'utf8').replace(/^\uFEFF/, '');
  const syntax = spawnSync(process.execPath, ['--input-type=module', '--check'], {
    input: source,
    encoding: 'utf8',
  });

  if (syntax.status !== 0) {
    const detail = (syntax.stderr || syntax.stdout || 'JavaScript syntax error').trim();
    errors.push(`${file}: ${detail}`);
    continue;
  }

  for (const specifier of importSpecifiers(source)) {
    if (!specifier.startsWith('./') && !specifier.startsWith('../')) continue;

    const target = resolveTrackedImport(file, specifier);
    if (target === null) {
      errors.push(`${file}: local import does not exist: ${specifier}`);
      continue;
    }

    if (!file.startsWith('app/assets/js/') || !target.startsWith('app/assets/js/')) continue;

    const version = versionFromSpecifier(specifier);
    if (version !== null) {
      if (!explicitVersions.has(target)) explicitVersions.set(target, new Set());
      explicitVersions.get(target).add(version);
    }

    if (criticalSingletons.has(target)) {
      if (!criticalReferences.has(target)) criticalReferences.set(target, []);
      criticalReferences.get(target).push({ importer: file, specifier, version });
    }
  }
}

for (const [target, versions] of explicitVersions) {
  if (versions.size > 1) {
    errors.push(`${target}: conflicting query versions: ${[...versions].sort().join(', ')}`);
  }
}

for (const target of criticalSingletons) {
  const references = criticalReferences.get(target) || [];
  if (references.length === 0) {
    errors.push(`${target}: critical singleton is not imported by tracked Mini App modules`);
    continue;
  }

  const unversioned = references.filter((item) => item.version === null);
  const versions = new Set(references.map((item) => item.version).filter(Boolean));

  if (unversioned.length > 0) {
    for (const item of unversioned) {
      errors.push(`${item.importer}: critical import must include ?v=: ${item.specifier}`);
    }
  }

  if (versions.size !== 1) {
    errors.push(`${target}: critical singleton must use one query version, found ${[...versions].sort().join(', ') || 'none'}`);
  }
}

if (errors.length > 0) {
  console.error('JavaScript checks failed:');
  for (const error of errors) console.error(`- ${error}`);
  process.exit(1);
}

console.log(`JavaScript checks passed: ${jsFiles.length} files`);
console.log(`Query-version checks passed: ${explicitVersions.size} imported modules`);
