import { readFileSync } from 'node:fs';

const launchUrlSource = readFileSync(
  new URL('../../../bot/helpers/WebAppLaunchUrl.php', import.meta.url),
  'utf8',
);
const entryPathMatch = launchUrlSource.match(
  /private\s+const\s+ENTRY_PATH\s*=\s*['"]([^'"]+)['"]\s*;/,
);

if (!entryPathMatch) {
  throw new Error('WebAppLaunchUrl::ENTRY_PATH is unavailable to the canonical browser suite.');
}

export const TELEGRAM_LAUNCH_PATH = entryPathMatch[1];

export function telegramAppRoute(origin) {
  return new URL(TELEGRAM_LAUNCH_PATH, String(origin)).toString();
}

export function telegramInvitationRoute(origin, token) {
  const url = new URL(TELEGRAM_LAUNCH_PATH, String(origin));
  url.searchParams.set('invite', String(token));
  return url.toString();
}
