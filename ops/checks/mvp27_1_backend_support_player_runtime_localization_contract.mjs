import fs from 'node:fs';

const CYR = /[\u0400-\u04FF]/;
const locale = JSON.parse(fs.readFileSync('app/locales/ru.json','utf8'));
const baseline = JSON.parse(fs.readFileSync('ops/checks/mvp27_1_hardcoded_text_baseline.json','utf8'));
const supportServiceSource = fs.readFileSync('bot/support/SupportTicketService.php','utf8');
const successor = supportServiceSource.includes("ServerLocalization::copy('server.support.errors.invalid_queue_mode'");
function assert(ok,msg){ if(!ok) throw new Error(msg); }
function get(key){ return key.split('.').reduce((v,p)=>v?.[p],locale); }
function cyrLines(path){ return fs.readFileSync(path,'utf8').split(/\r?\n/).filter(l=>CYR.test(l)).length; }

assert(locale?._meta?.locale === 'ru','RU locale identity changed.');
assert(Number.isInteger(locale?._meta?.version) && locale._meta.version >= 72,'Support successor must not regress RU locale below version 72.');
assert(/^[a-f0-9]{40}$/.test(String(baseline?.staging_base ?? '')),'Localization ratchet must stay bound to an exact staging SHA.');
assert(Number.isFinite(Number(baseline?.by_scope?.backend)) && Number(baseline.by_scope.backend) <= 885,'Support successor must not increase backend localization debt above 885.');
assert(baseline?.by_scope?.client === 0 && baseline?.by_scope?.['client-entry'] === 0,'Client debt must remain zero.');

const exact = {
  platforms:{telegram:'Приложение в Telegram',google_play:'Приложение из Google Play'},
  categories:{feedback:'Обратная связь',idea:'Предложение',complaint:'Жалоба',technical:'Техническая проблема',payment:'Платёж / коины',game:'Игра / матч',tournament:'Турнир',account:'Аккаунт',other:'Другое'},
  statuses:{open:'Открыт',in_progress:'В работе',waiting_user:'Ждём пользователя',resolved:'Решён',closed:'Закрыт'},
  priorities:{low:'Низкий',normal:'Обычный',high:'Высокий',critical:'Критический'},
  errors:{
    invalid_platform:'Выберите платформу.',
    invalid_category:'Выберите категорию обращения.',
    invalid_priority:'Выберите приоритет обращения.',
    ticket_not_found:'Обращение не найдено.',
    ticket_closed:'Закрытое обращение нельзя продолжить. Создайте новое.',
    user_unavailable:'Профиль MGW недоступен.',
    ticket_required:'Укажите номер обращения.',
    attachment_not_found:'Вложение не найдено.',
    too_many_attachments:'Можно приложить не более 3 файлов к одному сообщению.',
    invalid_attachment:'Некорректное вложение.',
    invalid_attachment_type:'Поддерживаются изображения, PDF и TXT.',
    invalid_attachment_read:'Не удалось прочитать вложение.',
    attachment_too_large:'Размер одного вложения не должен превышать 2 МБ.',
    message_required:'Напишите сообщение.'
  },
  endpoint:{
    invalid_request:'Некорректный запрос.',
    profile_unavailable:'Профиль MGW недоступен для этой сессии.',
    unavailable:'Поддержка MGW временно недоступна.',
    invalid_action:'Некорректное действие поддержки.',
    rate_limited:'Слишком много обращений. Попробуйте немного позже.',
    failed:'Не удалось обработать обращение в поддержку.'
  },
  attachment:{
    method_not_allowed:'Метод запроса не поддерживается.',
    invalid_attachment:'Некорректное вложение.',
    profile_unavailable:'Профиль MGW недоступен для этой сессии.',
    unavailable:'Поддержка MGW временно недоступна.',
    failed:'Не удалось скачать вложение.'
  },
  notifications:{
    reply_title:'Ответ поддержки',
    reply_text:'По обращению {ticket} пришёл новый ответ.'
  }
};
function walk(v,p='server.support'){
  for(const [k,x] of Object.entries(v)){
    const key=p+'.'+k;
    if(typeof x==='string') assert(get(key)===x,'Visible RU Support copy changed for '+key);
    else walk(x,key);
  }
}
walk(exact);

const expectedCyr={
  'bot/support/SupportTicketService.php':successor ? 0 : 5,
  'bot/support.php':0,
  'bot/support-attachment-download.php':0,
  'bot/support/SupportNotificationBridge.php':0,
  'bot/support/SupportTelegramNotifier.php':22
};
for(const [path,n] of Object.entries(expectedCyr)) assert(cyrLines(path)===n,path+' has unexpected residual Cyrillic count.');

const service=supportServiceSource;
if(successor){
  for(const key of [
    'server.support.errors.invalid_queue_mode',
    'server.support.errors.invalid_queue_filter',
    'server.support.errors.invalid_status_admin',
    'server.support.errors.invalid_priority_admin',
    'server.support.errors.reopen_required'
  ]) assert(service.includes(`ServerLocalization::copy('${key}'`),'Successor must keep Support validation copy locale-owned: '+key);
} else {
  for(const adminCopy of [
    'Некорректный режим очереди.',
    'Некорректный фильтр очереди.',
    'Некорректный статус обращения.',
    'Некорректный приоритет обращения.',
    'Сначала откройте обращение заново.'
  ]) assert(service.includes(adminCopy),'Admin-only Support validation must remain outside predecessor player slice: '+adminCopy);
}
assert(service.includes("ServerLocalization::copy('server.support.errors.ticket_closed'"),'Player closed-ticket error must be locale-owned.');
assert(service.includes("ServerLocalization::copy('server.support.errors.attachment_too_large'"),'Player attachment-size error must be locale-owned.');
assert(service.includes("'platform_label' => self::platformLabel($platform)"),'Support platform presentation must resolve locale labels.');
assert(service.includes("'category_label' => self::categoryLabel($category)"),'Support category presentation must resolve locale labels.');

const endpoint=fs.readFileSync('bot/support.php','utf8');
assert(endpoint.includes("'categories' => SupportTicketService::categoryLabels()"),'Support snapshot categories must resolve localized labels.');
assert(endpoint.includes("'statuses' => SupportTicketService::statusLabels()"),'Support snapshot statuses must resolve localized labels.');
assert(endpoint.includes("assertAllowed('support_create', $mgwId)"),'Support create rate-limit ownership changed.');
assert(endpoint.includes("assertAllowed('support_reply', $mgwId)"),'Support reply rate-limit ownership changed.');

const bridge=fs.readFileSync('bot/support/SupportNotificationBridge.php','utf8');
assert(bridge.includes("ServerLocalization::copy('server.support.notifications.reply_title'"),'Support reply notification title must be locale-owned.');
assert(bridge.includes("['ticket'=>$ticketNumber]"),'Support reply notification interpolation owner changed.');

const notifier=fs.readFileSync('bot/support/SupportTelegramNotifier.php','utf8');
assert(notifier.includes('🚨 Критическое обращение поддержки'),'Admin Telegram Support notifier must remain outside player slice.');
assert(notifier.includes('🌐 Открыть в Web Admin'),'Admin Support Web Admin CTA must remain outside player slice.');

console.log('MVP27_1_BACKEND_SUPPORT_PLAYER_RUNTIME_LOCALIZATION=PASS');
console.log('backend_debt='+baseline.by_scope.backend);
console.log('moved_player_facing_cyrillic_lines=52');
