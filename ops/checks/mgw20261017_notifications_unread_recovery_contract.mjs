import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';

const owner = readFileSync('app/assets/js/screens/notifications-screen-v110r13.js','utf8');
const server = readFileSync('bot/notifications.php','utf8');
const policy = readFileSync('bot/services/NotificationService.php','utf8');

assert.match(owner, /if \(!unread\.length && unreadHint === 0\) return;/,
  'Mark-all must not short-circuit with a positive server count and no visible items.');
assert.match(owner, /const hasUnread = unreadHint > 0 \|\|/,
  'The mark-all recovery control must be visible for a server-only unread item.');
assert.match(owner, /button\.hidden = !\(unreadHint > 0 \|\|/,
  'Mark-all visibility must update on sheet refresh.');
assert.match(owner, /if \(isNotificationsSheetOpen\(\)\) syncSheetHead\(visibleSheetItems\(\)\);/,
  'When server unread count arrives late, refresh action visibility.');
assert.match(owner, /setUnreadCount\(Number\(result\?\.unread_count \|\| 0\)\)/,
  'Read mutation must accept server-authoritative unread count.');
assert.match(server, /function mgw_visible_unread_count\(/,
  'Server unread-count ownership is preserved.');
assert.match(server, /if \(\$markAll\)\s*\{\s*\$notifications->markAllRead/,
  'Mark-all must still mutate canonical server state.');
assert.match(policy, /public function markAllRead\(array &\$db, string \$userId\)/,
  'Unread recovery still uses existing idempotent server read-all operation.');
console.log('MGW_NOTIFICATIONS_UNREAD_RECOVERY=PASS');
