import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const assert = (condition, message) => { if (!condition) throw new Error(message); };

const owner = read('app/assets/js/screens/notifications-screen-v110r13.js');
const manifest = read('app/runtime/client/version-manifest.php');
const ru = JSON.parse(read('app/locales/ru.json'));
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert(owner.includes("import { t, formatDateTime as formatLocalizedDateTime } from '@mgw/i18n';"),
  'Notification Center must use canonical translation and datetime owners.');
assert(owner.includes("t('notifications.view_request')") && owner.includes('notifications.invite_actions.'),
  'Friend-request and invite actions must resolve through localization keys.');
assert(owner.includes("t('notifications.request_error'") && owner.includes("t('notifications.friend_request_open_hint')"),
  'Notification request errors and friend-request hint must be localized.');
assert(owner.includes("t('notifications.tournament_local_time'") && owner.includes("notifications.terminal.invite_cancelled_owner"),
  'Tournament local-time output and terminal invite fallback copy must be localized.');
assert(owner.includes("formatLocalizedDateTime(date, 'short')") && owner.includes("formatLocalizedDateTime(date, 'short', { year:undefined })"),
  'Notification timestamps must use canonical locale-aware datetime formatting.');
assert(!owner.includes("Intl.DateTimeFormat('ru-RU'"),
  'Active Notification Center must not hardcode the RU Intl locale.');

for (const forbidden of [
  '>Посмотреть</button>',
  "accept:'Принять приглашение'",
  "decline:'Отклонить'",
  "start:'Начать игру'",
  "cancel:'Отменить'",
  'Ошибка уведомлений:',
  "message += ' Откройте заявку",
  'по вашему времени',
  "? 'Вы отменили своё приглашение.'",
  ": 'Вы отменили участие в матче.'",
  "return 'Вы отклонили приглашение.'",
]) {
  assert(!owner.includes(forbidden), 'Player-facing RU copy remained in Notification Center owner: ' + forbidden);
}

const cyrillicLines = owner.split(/\r?\n/).map(line => line.trim()).filter(line => /[\u0400-\u04FF]/.test(line));
assert(cyrillicLines.length === 0,
  'Successor shell localization must remove all direct Cyrillic, including historical compatibility matchers; got ' + cyrillicLines.length);

for (const [key, expected] of Object.entries({
  balance_already_updated:'Баланс уже обновлён',
  balance_not_changed:'Баланс не изменён',
  balance_label:'Баланс',
  status_label:'Статус',
  status_already:'уже',
  status_updated:'обновлён',
  return_status:'Проверьте статус возврата',
  status_refund:'Статус и возврат можно проверить',
  returned:'Возвращено',
  open_mini_app:'Откройте Mini App',
  tournament_assigned_title:'Дата турнира назначена',
  tournament_starts:'начнётся',
})) {
  assert(ru.notifications?.legacy_cleanup?.[key] === expected,
    'Historical RU compatibility token must remain locale-owned: ' + key);
}
assert(owner.includes('localizedTechnicalNotificationPatterns()')
  && owner.includes("t('notifications.legacy_cleanup.tournament_assigned_title')")
  && owner.includes("t('notifications.legacy_cleanup.tournament_starts')"),
  'Historical Notification compatibility behavior must remain driven by locale-owned tokens.');

assert(owner.includes('notificationCenterBlockedByMatch()')
  && owner.includes('readNotificationId:String(options.readNotificationId')
  && owner.includes('deleteNotificationId:String(options.deleteNotificationId')
  && owner.includes('safeDeepLink')
  && owner.includes('if (eventId) return'),
  'Accepted Notification Center lifecycle, read/delete, deep-link and dedupe owners must remain unchanged.');

assert(Number(ru._meta?.version || 0) >= 44, 'RU locale revision must retain Notification Center localization v44 or newer.');
assert(ru.notifications?.view_request === 'Посмотреть', 'Accepted RU friend-request navigation copy must remain unchanged.');
assert(ru.notifications?.invite_actions?.accept === 'Принять приглашение', 'Accepted RU invite action copy must remain unchanged.');
assert(ru.notifications?.friend_request_open_hint === 'Откройте заявку, чтобы посмотреть профиль и принять или отклонить её.',
  'Accepted RU friend-request hint must remain unchanged.');
assert(ru.notifications?.tournament_local_time === 'начнётся {date} по вашему времени',
  'Accepted RU tournament local-time copy must remain unchanged.');
assert(ru.notifications?.terminal?.invite_declined === 'Вы отклонили приглашение.',
  'Accepted RU terminal invitation fallback must remain unchanged.');

assert(manifest.includes("'./assets/js/screens/notifications-screen-v110r13.js?v=1162&mvp18=friend-request-lifecycle' => './assets/js/screens/notifications-screen-v110r13.js?v=1168&mvp27_1=active-shell-copy-v1&mvp21_3=read-authority-local-time&mvp22_1=support-ticket-deeplink-smooth-v2&mvp25_2=legacy-store-orders-retired-v1&mvp27_1=localized-user-copy-v1'"),
  'Canonical manifest must publish the active-shell localized Notification Center successor.');

assert(Number(baseline.cyrillic_lines_total) <= 3373 && Number(baseline.by_scope?.client) <= 1692,
  'Notification Center localization debt must never regress above the accepted post-slice baseline.');
assert(Number(baseline.by_scope?.backend) <= 1653 && Number(baseline.by_scope?.['client-entry']) <= 28,
  'Notification Center localization must not increase backend or client-entry debt.');

console.log('MVP27_1_NOTIFICATION_CENTER_LOCALIZATION_CONTRACT=PASS');
console.log('MVP27_1_NOTIFICATION_CENTER_LEGACY_RU_MATCHERS=0');
