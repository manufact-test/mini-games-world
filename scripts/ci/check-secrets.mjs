import { execFileSync } from 'node:child_process';
import { readFileSync, statSync } from 'node:fs';

function trackedFiles() {
  return execFileSync('git', ['ls-files', '-z'])
    .toString('utf8')
    .split('\0')
    .filter(Boolean)
    .sort();
}

const errors = [];
const files = trackedFiles();

const forbiddenPathPatterns = [
  { pattern: /(^|\/)bot\/config\/config\.php$/, label: 'live bot config' },
  { pattern: /(^|\/)bot\/config\/config\.local\.php$/, label: 'local private config' },
  { pattern: /(^|\/)_private_mgw\//, label: 'private config directory' },
  { pattern: /(^|\/)mgw_(?:staging_)?data(?:\/|$)/, label: 'runtime data directory' },
  { pattern: /(^|\/)\.env$/, label: 'environment secret file' },
  { pattern: /(^|\/)\.htpasswd$/, label: 'password file' },
  { pattern: /\.(?:pem|p12|pfx|key)$/i, label: 'private key material' },
];

for (const file of files) {
  for (const rule of forbiddenPathPatterns) {
    if (rule.pattern.test(file)) errors.push(`${file}: tracked ${rule.label} is forbidden`);
  }
}

function isPlaceholder(value) {
  const normalized = String(value).trim().toLowerCase();
  return normalized === ''
    || normalized.includes('paste_')
    || normalized.includes('put_')
    || normalized.includes('change_')
    || normalized.includes('example')
    || normalized.includes('placeholder')
    || normalized.includes('dummy')
    || normalized.includes('fake')
    || normalized.includes('for_test')
    || normalized.includes('test_token')
    || normalized.includes('token_value_for_test');
}

function lineNumber(source, index) {
  return source.slice(0, index).split('\n').length;
}

function report(file, source, index, label) {
  errors.push(`${file}:${lineNumber(source, index)}: possible ${label}`);
}

const directSecretPatterns = [
  { pattern: /-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/g, label: 'private key' },
  { pattern: /\bgh[pousr]_[A-Za-z0-9]{30,}\b/g, label: 'GitHub token' },
  { pattern: /\bgithub_pat_[A-Za-z0-9_]{30,}\b/g, label: 'GitHub fine-grained token' },
  { pattern: /\bAKIA[0-9A-Z]{16}\b/g, label: 'AWS access key' },
];

const assignmentPatterns = [
  { pattern: /['"]bot_token['"]\s*(?:=>|:)\s*['"]([^'"]*)['"]/g, label: 'Telegram bot token value' },
  { pattern: /['"]setup_secret['"]\s*(?:=>|:)\s*['"]([^'"]*)['"]/g, label: 'webhook setup secret' },
  { pattern: /['"]staging_setup_key['"]\s*(?:=>|:)\s*['"]([^'"]*)['"]/g, label: 'staging setup key' },
];

for (const file of files) {
  let stats;
  try {
    stats = statSync(file);
  } catch {
    continue;
  }
  if (!stats.isFile() || stats.size > 2_000_000) continue;

  const buffer = readFileSync(file);
  if (buffer.includes(0)) continue;
  const source = buffer.toString('utf8');

  for (const rule of directSecretPatterns) {
    for (const match of source.matchAll(rule.pattern)) report(file, source, match.index ?? 0, rule.label);
  }

  for (const match of source.matchAll(/\b\d{6,12}:[A-Za-z0-9_-]{30,}\b/g)) {
    const value = match[0];
    if (!isPlaceholder(value)) report(file, source, match.index ?? 0, 'Telegram bot token');
  }

  for (const rule of assignmentPatterns) {
    for (const match of source.matchAll(rule.pattern)) {
      if (!isPlaceholder(match[1])) report(file, source, match.index ?? 0, rule.label);
    }
  }

  for (const match of source.matchAll(/['"]admin_ids['"]\s*(?:=>|:)\s*\[([\s\S]*?)\]/g)) {
    if (/\b\d{6,15}\b/.test(match[1])) {
      report(file, source, match.index ?? 0, 'real Telegram admin ID in tracked config');
    }
  }
}

if (errors.length > 0) {
  console.error('Secret scan failed:');
  for (const error of [...new Set(errors)]) console.error(`- ${error}`);
  process.exit(1);
}

console.log(`Secret scan passed: ${files.length} tracked files`);
