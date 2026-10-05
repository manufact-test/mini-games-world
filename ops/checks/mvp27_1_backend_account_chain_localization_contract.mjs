import fs from 'node:fs';

const CYRILLIC = /[\u0400-\u04FF]/;

const expected = {
  'server.account_chain.common.player_fallback':'Игрок',
  'server.account_chain.identity.deleted_reopen_later':'Предыдущий аккаунт был удалён. Для нового входа заново откройте MINI GAMES WORLD через Telegram позже.',
  'server.account_chain.identity.deletion_in_progress':'Аккаунт MGW недоступен: удаление данных выполняется или уже завершено.',
  'server.account_chain.auth.open_from_android':'Откройте MINI GAMES WORLD через приложение Android.',
  'server.account_chain.auth.unavailable':'Android-вход сейчас недоступен.',
  'server.account_chain.auth.invalid_invite_link':'Некорректная ссылка приглашения.',
  'server.account_chain.auth.rate_limited':'Слишком много попыток входа. Попробуйте немного позже.',
  'server.account_chain.auth.open_failed':'Не удалось открыть MINI GAMES WORLD. Попробуйте ещё раз.',
  'server.account_chain.reauth.method_not_allowed':'Метод запроса не поддерживается.',
  'server.account_chain.reauth.unavailable':'Подтверждение Android сейчас недоступно.',
  'server.account_chain.reauth.android_only':'Подтверждение доступно только в Android-приложении.',
  'server.account_chain.reauth.invalid_action':'Некорректное действие подтверждения Android.',
  'server.account_chain.reauth.rate_limited':'Слишком много попыток подтверждения. Попробуйте позже.',
  'server.account_chain.reauth.device_confirm_failed':'Не удалось подтвердить это устройство.',
  'server.account_chain.reauth.failed':'Не удалось подтвердить действие. Попробуйте ещё раз.',
  'server.account_chain.reauth.challenge_unavailable':'Эта попытка подтверждения больше недоступна.',
  'server.account_chain.reauth.expired':'Подтверждение устарело. Повторите действие.',
  'server.account_chain.reauth.profile_unavailable':'Не удалось подтвердить профиль MGW.',
  'server.account_chain.reauth.session_unavailable':'Android-сессия недоступна. Перезапустите приложение.',
  'server.account_chain.reauth.challenge_not_found':'Попытка подтверждения не найдена.',
  'server.account_chain.reauth.challenge_invalid':'Некорректная попытка подтверждения.',
  'server.account_chain.reauth.unlock_required':'Подтвердите действие разблокировкой устройства и повторите попытку.',
  'server.account_chain.reauth.telegram_refresh_required':'Для этого действия заново откройте MINI GAMES WORLD из Telegram и повторите попытку.',
  'server.account_chain.export.prepare_directory_failed':'Не удалось подготовить каталог экспорта.',
  'server.account_chain.export.zip_write_failed':'Не удалось записать ZIP-экспорт.',
  'server.account_chain.export.invalid_zip_name':'Некорректное имя файла в ZIP.',
  'server.account_chain.data.account_not_found':'Аккаунт MGW не найден.',
  'server.account_chain.data.deletion_locked':'Удаление аккаунта уже выполняется или завершено.',
  'server.account_chain.data.deletion_not_cancellable':'Нет запланированного удаления, которое можно отменить.',
  'server.account_chain.data.export_still_valid':'Новый экспорт можно запросить позже. Последний архив ещё действует.',
  'server.account_chain.data.export_not_ready':'Экспорт не найден или ещё не готов.',
  'server.account_chain.data.export_expired':'Срок хранения этого экспорта истёк.',
  'server.account_chain.data.export_missing':'Файл экспорта недоступен.',
  'server.account_chain.data.deleted_player':'Удалённый игрок',
  'server.account_chain.data.csv_build_failed':'Не удалось собрать CSV-экспорт.',
  'server.account_chain.data.export_title':'MINI GAMES WORLD — экспорт данных',
  'server.account_chain.data.export_heading':'Экспорт данных MINI GAMES WORLD',
  'server.account_chain.data.export_created_label':'Создано',
  'server.account_chain.data.export_description':'Полный машинно-читаемый экспорт находится в <code>data.json</code>. Табличные данные продублированы в каталоге <code>csv/</code>.',
  'server.account_chain.data.export_section_label':'Раздел',
  'server.account_chain.data.export_records_label':'Записей',
  'server.account_chain.data.image_none':'У аккаунта нет доступного пользовательского изображения.',
  'server.account_chain.data.image_catalog_only':'У аккаунта нет отдельного загруженного изображения. Каталожный аватар описан в data.json.',
  'server.account_chain.data.image_media_root_not_configured':'В профиле есть ссылка на изображение, но приватный media-root не настроен для экспорта. Метаданные сохранены в data.json.',
  'server.account_chain.data.image_media_root_unavailable':'Приватный media-root недоступен; метаданные изображения сохранены в data.json.',
  'server.account_chain.data.image_file_unavailable':'Файл пользовательского изображения не найден или недоступен; его метаданные сохранены в data.json.',
  'server.account_chain.data.image_read_failed':'Не удалось прочитать пользовательское изображение; метаданные сохранены в data.json.',
  'server.account_chain.data.invalid_mgw_id':'Некорректный MGW-ID.',
  'server.account_chain.data.invalid_source':'Некорректный источник запроса.',
  'server.account_chain.data.private_export_dir_failed':'Не удалось подготовить приватный каталог экспортов.',
  'server.account_chain.link.telegram_bot_unavailable':'Telegram-бот для привязки аккаунта сейчас недоступен.',
  'server.account_chain.link.rate_limited':'Слишком много попыток привязки. Попробуйте позже.',
  'server.account_chain.link.invalid_token':'Ссылка привязки недействительна.',
  'server.account_chain.link.telegram_first_open_required':'Сначала откройте MINI GAMES WORLD в Telegram хотя бы один раз, затем повторите привязку.',
  'server.account_chain.link.already_linked':'Этот Telegram уже привязан к текущему MGW-профилю.',
  'server.account_chain.link.target_ownership_missing':'Не удалось подтвердить владельца Telegram-профиля.',
  'server.account_chain.link.target_android_conflict':'Этот MGW-профиль уже привязан к другому Android-устройству.',
  'server.account_chain.link.challenge_claimed':'Эта попытка привязки уже подтверждается другим Telegram-профилем.',
  'server.account_chain.link.confirmation_owner_mismatch':'Эту привязку должен подтвердить тот же Telegram-профиль.',
  'server.account_chain.link.cancel_owner_mismatch':'Эту привязку должен отменить тот же Telegram-профиль.',
  'server.account_chain.link.telegram_confirmation_required':'Сначала подтвердите привязку в Telegram.',
  'server.account_chain.link.target_invalid':'Целевой MGW-профиль недействителен.',
  'server.account_chain.link.android_identity_missing':'Android identity не найдена.',
  'server.account_chain.link.android_identity_conflict':'Android identity принадлежит другому MGW-профилю.',
  'server.account_chain.link.telegram_identity_changed':'Telegram identity изменилась до завершения привязки.',
  'server.account_chain.link.target_android_linked':'Целевой MGW-профиль уже связан с другим Android.',
  'server.account_chain.link.source_other_device_or_session':'Временный Android-профиль уже содержит другое устройство или сессию.',
  'server.account_chain.link.target_device_conflict':'Android-устройство уже зарегистрировано в целевом профиле.',
  'server.account_chain.link.source_not_pristine':'Временный Android-профиль уже не находится в исходном состоянии.',
  'server.account_chain.link.source_extra_identity':'Временный Android-профиль уже содержит дополнительную identity.',
  'server.account_chain.link.source_activity_or_purchases':'На временном Android-профиле уже есть активность или приобретения. Автоматическая привязка остановлена.',
  'server.account_chain.link.source_user_activity':'Временный Android-профиль уже содержит пользовательскую активность. Автоматическая привязка остановлена.',
  'server.account_chain.link.source_owner_changed':'Владелец временного Android-профиля изменился.',
  'server.account_chain.link.source_balance_ambiguous':'Баланс временного Android-профиля неоднозначен.',
  'server.account_chain.link.source_balance_changed':'Баланс временного Android-профиля уже изменился. Автоматическая привязка остановлена.',
  'server.account_chain.link.source_runtime_missing':'Временный Android runtime-профиль не найден.',
  'server.account_chain.link.source_already_used':'Временный Android-профиль уже использовался. Автоматическая привязка остановлена.',
  'server.account_chain.link.source_game_stats':'Временный Android-профиль уже содержит игровую статистику.',
  'server.account_chain.link.source_economy_activity':'Временный Android-профиль уже содержит экономическую активность.',
  'server.account_chain.link.source_non_start_transaction':'Временный Android-профиль уже содержит не-стартовую транзакцию.',
  'server.account_chain.link.source_start_balance_ambiguous':'Стартовый баланс временного Android-профиля неоднозначен.',
  'server.account_chain.link.source_other_user_state':'Временный Android-профиль уже связан с другим пользовательским состоянием.',
  'server.account_chain.link.source_game_or_social_data':'Временный Android-профиль уже связан с игровыми или социальными данными.',
  'server.account_chain.link.source_ledger_activity':'Ledger временного Android-профиля уже содержит активность.',
  'server.account_chain.link.state_changed':'Состояние привязки изменилось.',
  'server.account_chain.link.target_owner_changed':'Владелец целевого MGW-профиля изменился.',
  'server.account_chain.link.target_unavailable':'Целевой MGW-профиль недоступен.',
  'server.account_chain.link.android_auth_required':'Привязку нужно начать из Android-приложения.',
  'server.account_chain.link.android_profile_missing':'Android MGW-профиль не найден.',
  'server.account_chain.link.ownership_missing':'Владелец MGW-профиля не найден.',
  'server.account_chain.link.challenge_device_mismatch':'Эта привязка принадлежит другому Android-устройству.',
  'server.account_chain.link.challenge_profile_changed':'MGW-профиль этой привязки изменился.',
  'server.account_chain.link.challenge_expired':'Ссылка привязки истекла. Создайте новую.',
  'server.account_chain.link.challenge_state_invalid':'Эта попытка привязки уже недоступна.',
  'server.account_chain.link.challenge_not_found':'Попытка привязки не найдена.',
  'server.account_chain.link.link_not_found':'Ссылка привязки не найдена.',
  'server.account_chain.link.identity_ambiguous':'MGW identity неоднозначна.',
  'server.account_chain.link.ownership_ambiguous':'Владелец MGW-профиля неоднозначен.',
  'server.account_chain.link.challenge_invalid':'Идентификатор привязки недействителен.',
  'server.account_chain.link.telegram_identity_invalid':'Telegram identity недействительна.',
  'server.account_chain.link.unavailable':'Привязка аккаунта сейчас недоступна.'
};

const runtimeFiles = [
  'bot/accounts/AccountDataLifecycleService.php',
  'bot/accounts/AccountLinkService.php',
  'bot/accounts/AndroidAccountReauthService.php',
  'bot/accounts/AccountDataZipWriter.php',
  'bot/accounts/AccountReauthGuard.php',
  'bot/accounts/AccountIdentityService.php',
  'bot/accounts/AndroidDeviceAuthService.php',
  'bot/android-reauth.php',
  'bot/android-auth.php',
];

function readPath(source,key){
  let value=source;
  for(const part of key.split('.')){
    if(!value || typeof value !== 'object' || !(part in value)) return undefined;
    value=value[part];
  }
  return value;
}
function assert(condition,message){ if(!condition) throw new Error(message); }

const ru=JSON.parse(fs.readFileSync('app/locales/ru.json','utf8'));
assert(ru?._meta?.locale === 'ru','RU locale metadata changed.');
assert(Number(ru?._meta?.version) === 66,'RU locale version must be exactly 66 for this bundle.');

for(const [key,value] of Object.entries(expected)){
  assert(readPath(ru,key) === value,`Unexpected RU copy for ${key}`);
}

for(const file of runtimeFiles){
  const source=fs.readFileSync(file,'utf8');
  assert(!CYRILLIC.test(source),`Direct Cyrillic remains in account-chain owner: ${file}`);
  assert(source.includes('ServerLocalization::copy('),`Account-chain owner does not use canonical server localization adapter: ${file}`);
}

const adapter=fs.readFileSync('bot/localization/ServerLocalization.php','utf8');
assert(adapter.includes("app/runtime/localization/LocalizationCatalog.php"),'Server adapter must require canonical LocalizationCatalog.');
assert(adapter.includes("new LocalizationCatalog("),'Server adapter must instantiate canonical LocalizationCatalog.');
assert(adapter.includes("->translate($key, $params)"),'Server adapter must delegate translation to LocalizationCatalog.');

const lifecycle=fs.readFileSync('bot/accounts/AccountDataLifecycleService.php','utf8');
assert(lifecycle.includes("display_name=:deleted_player"),'Deleted-player DB anonymization must use localized parameter ownership.');
assert(lifecycle.includes("'deleted_player'=>$deletedPlayer"),'Deleted-player DB parameter is missing.');
assert(lifecycle.includes("$game['player_names'][$legacyUserId] = $deletedPlayer;"),'Runtime game anonymization must preserve localized deleted-player projection.');
assert(lifecycle.includes("$invite['inviter_name'] = $deletedPlayer;"),'Runtime invite anonymization must preserve localized inviter projection.');
assert(lifecycle.includes("$invite['invitee_name'] = $deletedPlayer;"),'Runtime invite anonymization must preserve localized invitee projection.');

const predecessor = {
  'server.account_link.method_not_allowed':'Метод запроса не поддерживается.',
  'server.account_link.invalid_request':'Некорректный запрос.',
  'server.account_link.android_required':'Привязку аккаунта нужно начать из Android-приложения.',
  'server.account_link.unavailable':'Привязка аккаунта временно недоступна.',
  'server.account_link.invalid_action':'Некорректное действие привязки аккаунта.',
  'server.account_link.failed':'Не удалось обработать привязку аккаунта. Попробуйте ещё раз.',
  'server.account_data.unavailable':'Управление данными аккаунта временно недоступно.',
  'server.account_data.method_not_allowed':'Метод запроса не поддерживается.',
  'server.account_data.invalid_request':'Некорректный запрос.',
  'server.account_data.profile_unavailable':'Профиль MGW недоступен для этой сессии.',
  'server.account_data.invalid_action':'Некорректное действие управления данными аккаунта.',
  'server.account_data.failed':'Не удалось обработать данные аккаунта.'
};
for(const [key,value] of Object.entries(predecessor)){
  assert(readPath(ru,key) === value,`Predecessor endpoint localization changed: ${key}`);
}

console.log('MVP27_1_BACKEND_ACCOUNT_CHAIN_LOCALIZATION=PASS');
console.log(`MVP27_1_BACKEND_ACCOUNT_CHAIN_KEYS=${Object.keys(expected).length}`);
console.log('MVP27_1_BACKEND_ACCOUNT_CHAIN_DIRECT_CYRILLIC=0');
