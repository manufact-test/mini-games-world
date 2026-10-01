import fs from 'node:fs';

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

const home = fs.readFileSync('app/assets/js/screens/home-screen.js', 'utf8');
const ru = JSON.parse(fs.readFileSync('app/locales/ru.json', 'utf8'));
const manifest = fs.readFileSync('app/runtime/client/version-manifest.php', 'utf8');

assert(!/[\u0400-\u04FF]/.test(home), 'Active Home owner must contain zero hardcoded Cyrillic after final Home migration.');
assert(!home.includes('ru-RU'), 'Home must not hardcode the RU Intl locale.');
assert(home.includes("formatDateTime as formatLocalizedDateTime"), 'Home must import canonical locale-aware datetime formatting.');
assert(home.includes("formatLocalizedDateTime(date,'short',{year:undefined})"),
  'Home compact timestamps must be rendered by canonical i18n while preserving accepted shape.');

for (const apiOwner of [
  'api.supportCreate({',
  'api.supportSnapshot()',
  'api.supportTicket(ticketNumber)',
  'api.supportReply(ticketNumber,message,attachments)',
  'api.supportAttachment(attachmentId)',
]) {
  assert(home.includes(apiOwner), `Accepted Support API owner missing: ${apiOwner}`);
}

for (const invariant of [
  "const allowed=['image/jpeg','image/png','image/webp','image/gif','application/pdf','text/plain'];",
  'Number(file.size||0)>2000000',
  'files.length>=3',
  'files.length>3',
  'maxlength="4000"',
  'maxlength="160"',
  'accept="image/jpeg,image/png,image/webp,image/gif,application/pdf,text/plain"',
]) {
  assert(home.includes(invariant), `Accepted Support/file invariant missing: ${invariant}`);
}

const requiredKeys = [
  'title_default','message_feedback','message_idea','message_default',
  'category_label','category_feedback','category_idea','category_technical','category_payment',
  'category_game','category_tournament','category_account','category_other',
  'subject_label','subject_placeholder','priority_label','priority_normal','priority_high',
  'priority_critical','priority_low','message_label','attachments','attachments_note','add_file',
  'create','message_required','created_case','created','create_error','tickets_load_error',
  'ticket_fallback','tickets_count','tickets_empty','open_error','closed','reply',
  'reply_placeholder','reply_add','reply_file','send','sent','send_error','status_open',
  'status_in_progress','status_waiting','status_resolved','status_closed','attachment_fallback',
  'open','hide','your_message','support','file_not_selected','file_too_large','file_type_error',
  'file_size_kb','remove_file','file_limit_button','file_limit_error','read_error','loading',
  'attachment_open_error'
];
for (const key of requiredKeys) {
  assert(typeof ru.home?.support?.[key] === 'string' && ru.home.support[key].length > 0,
    `RU Support key missing: ${key}`);
  assert(home.includes(`home.support.${key}`), `Home does not consume Support key: ${key}`);
}

assert(manifest.includes('mvp27_1_support=localized-time-v1'),
  'Active Home owner must publish Support/time localization cache identity.');

console.log('MVP27_1_HOME_SUPPORT_LOCALIZATION_CONTRACT=PASS');
