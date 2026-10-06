import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path, 'utf8');
const countCyrillicLines = source => source.split(/\r?\n/).filter(line => /[\u0400-\u04FF]/.test(line)).length;

const runtimeFiles = [
  "bot/services/invites/GameInviteActionTrait.php",
  "bot/services/invites/GameInviteValidationTrait.php",
  "bot/services/invites/GameInviteCreationTrait.php",
  "bot/services/invites/GameInviteStorageTrait.php",
  "bot/services/GameInviteService.php",
  "bot/services/InviteOpponentService.php",
  "bot/invite-opponents.php",
  "bot/invite-watch.php"
];
const expected = Object.freeze({
  "errors": {
    "other_player": "Это приглашение предназначено другому игроку.",
    "invite_unavailable": "Это приглашение больше недоступно.",
    "inviter_unavailable": "Пригласивший игрок больше недоступен.",
    "finish_search_or_game": "Сначала завершите текущий поиск или игру.",
    "inviter_busy_other_game": "Пригласивший игрок сейчас занят в другой игре.",
    "start_owner_only": "Запустить матч может только пригласивший игрок.",
    "not_participant_invite": "Вы не участвуете в этом приглашении.",
    "use_decline_button": "Используйте кнопку «Отклонить».",
    "rematch_finished_only": "Реванш доступен только после завершённой партии.",
    "rematch_human_only": "Реванш доступен только с живым соперником.",
    "not_participant_match": "Вы не участвуете в этом матче.",
    "rematch_opponent_unavailable": "Соперник для реванша недоступен.",
    "finish_search_match_invite": "Сначала завершите текущий поиск, матч или приглашение.",
    "opponent_busy_search_match_invite": "Соперник сейчас занят поиском, матчем или другим приглашением.",
    "opponent_not_confirmed": "Соперник ещё не подтвердил приглашение.",
    "participant_unavailable": "Один из игроков больше недоступен.",
    "invitee_busy_other_game": "Приглашённый игрок сейчас занят в другой игре.",
    "private_match_failed": "Не удалось создать приватный матч.",
    "insufficient_accept_balance": "Недостаточно коинов для принятия приглашения.",
    "inviter_insufficient_balance": "У пригласившего игрока недостаточно коинов.",
    "invite_not_found": "Приглашение не найдено или уже недоступно.",
    "select_other_player": "Выберите другого игрока.",
    "direct_invite_exists": "Этому игроку уже отправлено другое приглашение.",
    "player_busy_search_match_invite": "Игрок сейчас занят поиском, матчем или другим приглашением.",
    "share_owner_only": "Подтвердить отправку может только создатель приглашения.",
    "draft_unavailable": "Этот черновик приглашения больше недоступен.",
    "finish_search_match_other_invite": "Сначала завершите текущий поиск, матч или другое приглашение.",
    "not_owner": "Вы не создавали это приглашение.",
    "self_open_forbidden": "Нельзя открыть собственное приглашение как соперник.",
    "already_bound_other_player": "Это приглашение уже предназначено другому игроку.",
    "finish_other_invite": "Сначала завершите другое приглашение.",
    "inviter_busy_other_invite": "Пригласивший игрок уже занят другим приглашением.",
    "game_unavailable": "Эта игра пока недоступна.",
    "insufficient_bet": "Недостаточно коинов для выбранной ставки.",
    "inviter_started_other_match": "Пригласивший игрок уже начал другой матч.",
    "rematch_bot_unavailable": "Реванш сейчас недоступен. Выберите «Сыграть ещё»."
  },
  "notifications": {
    "accepted_title": "Соперник согласен",
    "accepted_message": "{name} готов сыграть в «{game}».",
    "declined_message": "{name} отказался от матча «{game}».",
    "owner_cancelled_message": "Матч «{game}» не начался.",
    "invitee_cancelled_message": "{name} отменил участие в матче.",
    "received_rematch_title": "Вам предлагают реванш",
    "received_direct_title": "Вас пригласили сыграть",
    "received_rematch_message": "{name} предлагает реванш в «{game}».",
    "received_direct_message": "{name} приглашает вас в «{game}»."
  },
  "status": {
    "draft": "Ссылка подготовлена",
    "pending": "Ожидает ответа",
    "awaiting_start": "Ожидает запуска",
    "starting": "Матч запускается",
    "active": "Матч начат",
    "declined": "Отклонено",
    "cancelled": "Отменено",
    "expired": "Срок истёк",
    "timed_out": "Время ожидания истекло",
    "unavailable": "Недоступно"
  },
  "opponents": {
    "playing": "сейчас играет",
    "searching": "ищет соперника",
    "online": "онлайн",
    "recent": "был недавно",
    "week": "заходил на этой неделе",
    "recent_player": "недавний игрок",
    "friend": "друг"
  }
});

const ru = JSON.parse(read('app/locales/ru.json'));
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.equal(ru?._meta?.locale, 'ru');
assert.ok(Number(ru?._meta?.version) >= 67, 'RU catalog must be Invite service-chain revision 67 or newer.');
assert.deepEqual(ru?.server?.invite_chain, expected, 'Invite service-chain RU copy must remain byte/meaning stable.');

assert.equal(ru?.server?.invites?.player_fallback, 'Игрок');
assert.equal(ru?.server?.invites?.game_fallback, 'Игра');
assert.equal(ru?.server?.invites?.game_lower_fallback, 'игру');
assert.equal(ru?.server?.invites?.invalid_request, 'Некорректный запрос.');
assert.equal(ru?.server?.invites?.user_not_found, 'Пользователь не найден.');
assert.equal(ru?.server?.notifications?.invite_cancelled_title, 'Приглашение отменено');
assert.equal(ru?.server?.notifications?.opponent_cancelled_title, 'Соперник отменил участие');
assert.equal(ru?.server?.notifications?.invite_declined_title, 'Приглашение отклонено');
assert.equal(ru?.server?.notifications?.inviter_cancelled_message, '{name} отменил приглашение сыграть в «{game}».');
assert.equal(ru?.server?.notifications?.invitee_cancelled_message, '{name} отменил участие в матче «{game}».');

for (const file of runtimeFiles) {
  const source = read(file);
  assert.equal(countCyrillicLines(source), 0, 'Direct Cyrillic remains in active Invite-chain owner: ' + file);
}

const service = read('bot/services/GameInviteService.php');
const action = read('bot/services/invites/GameInviteActionTrait.php');
const validation = read('bot/services/invites/GameInviteValidationTrait.php');
const creation = read('bot/services/invites/GameInviteCreationTrait.php');
const storage = read('bot/services/invites/GameInviteStorageTrait.php');
const opponents = read('bot/services/InviteOpponentService.php');
const opponentEndpoint = read('bot/invite-opponents.php');
const watchEndpoint = read('bot/invite-watch.php');

assert.ok(service.includes("require_once dirname(__DIR__) . '/localization/ServerLocalization.php';"));
assert.ok(service.includes('return ServerLocalization::copy($key, $fallback, $params);'));
for (const source of [action, validation, creation, storage]) {
  assert.ok(source.includes('$this->inviteCopy('), 'Invite trait must resolve player copy through GameInviteService canonical adapter.');
}
assert.ok(opponents.includes("ServerLocalization::copy('server.invite_chain.opponents."));
assert.ok(opponentEndpoint.includes("ServerLocalization::copy('server.invites.invalid_request'"));
assert.ok(watchEndpoint.includes("ServerLocalization::copy('server.invites.invalid_request'"));

assert.ok(service.includes('private const INVITE_TTL_SEC = 120;'));
assert.ok(service.includes('private const DRAFT_TTL_SEC = 900;'));
assert.ok(service.includes('private const READY_TTL_SEC = 90;'));
assert.ok(creation.includes('if ($sameContext) return $this->publicInvite($existing, $userId);'));
assert.ok(action.includes("$invite['status'] = 'awaiting_start';"));
assert.ok(action.includes("$invite['status'] = 'starting';"));
assert.ok(action.includes("$invite['status'] = 'active';"));
assert.ok(validation.includes('UnifiedBalanceRuntimeState::FIELD'));
assert.ok(storage.includes('GameLaunchFinalizationService::finalizeStoredGame($db, $gameId)'));
assert.ok(opponents.includes('private const MAX_ITEMS = 10;'));
assert.ok(opponents.includes("str_starts_with($candidateId, 'bot_')"));
assert.ok(opponentEndpoint.includes('StorageFactory::createJson('));
assert.ok(watchEndpoint.includes('new InviteSignalService($config)'));

assert.ok(Number.isInteger(Number(baseline.scanned_files)) && Number(baseline.scanned_files) > 0,'Successor classification may reduce scan coverage only through an explicit ownership proof; the player-facing audit itself must remain populated.');
assert.ok(Number(baseline.cyrillic_lines_total) <= 1348, 'Later localization may only reduce total debt from the Invite service-chain ceiling.');
assert.equal(Number(baseline.by_scope?.client), 0);
assert.ok(Number(baseline.by_scope?.backend) <= 1348, 'Later localization may only reduce backend debt from the Invite service-chain ceiling.');
assert.equal(Number(baseline.by_scope?.['client-entry']), 0);

console.log('MVP27_1_BACKEND_INVITE_SERVICE_CHAIN_LOCALIZATION=PASS');
console.log('MVP27_1_BACKEND_INVITE_SERVICE_CHAIN_DIRECT_CYRILLIC=0');
console.log('MVP27_1_BACKEND_DEBT_CEILING=1348');
