import { readFileSync } from 'node:fs';

const scanner = readFileSync('scripts/ci/check-secrets.mjs', 'utf8');
const workflow = readFileSync('.github/workflows/mvp25-5-secret-scan.yml', 'utf8');

const requiredScannerTerms = [
  "bot/config/config\\.php",
  "bot/config/runtime\\.php",
  "_private_mgw",
  "Telegram bot token value",
  "webhook setup secret",
  "Telegram webhook secret_token",
  "staging test auth secret",
  "account-data website hook secret",
  "real Telegram admin ID in tracked config",
  "GitHub token",
  "AWS access key",
];

for (const term of requiredScannerTerms) {
  if (!scanner.includes(term)) {
    throw new Error(`Secret scanner contract missing: ${term}`);
  }
}

const requiredWorkflowTerms = [
  "pull_request:",
  "push:",
  "agent/mvp-13-2-staging",
  "persist-credentials: false",
  "node scripts/ci/check-secrets.mjs",
];

for (const term of requiredWorkflowTerms) {
  if (!workflow.includes(term)) {
    throw new Error(`Secret scan workflow contract missing: ${term}`);
  }
}

console.log('MVP-25.5 secret scan contract: OK');
