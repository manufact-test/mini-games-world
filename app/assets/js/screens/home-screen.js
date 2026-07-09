import { state } from '../state.js?v=27';
import { APP_CONFIG } from '../config.js?v=27';
import { api } from '../api/client.js?v=27';
import { toast } from '../components/toast.js?v=27';
import { openSheet, closeSheet } from '../components/sheet.js?v=27';
import { showScreen } from '../router.js?v=27';
import { haptic } from '../telegram/telegram-app.js?v=27';
import { renderBalances, roomName } from '../ui.js?v=27';
import { startSearchPolling } from './search-screen.js?v=27';
import { startGamePolling } from './game-screen.js?v=27';
import { isSessionLocked, sessionMessage } from '../session.js?v=27';

export function initHomeScreen(){
  document.addEventListener('click', event => {
    const target = event.target.closest('button, [role="button"]');
    if (!target) return;

    if (target.matches('[data-room]')) return setRoom(target.dataset.room);
    if (target.id === 'playTicTacToe') return openGameSetup();
    if (target.id === 'inviteFriend') return toast('Приглашения друзей появятся позже.');
    if (target.id === 'notificationsOpen') return toast('Уведомлений пока нет.');
    if (target.id === 'moreMenuOpen' || target.id === 'gameMenuOpen') return openMoreMenuSheet();
    if (target.id === 'profileOpen') return openProfileFromTop();
    if (target.matches('[data-back-home]')) return showScreen('home');
  });

  document.addEventListener('keydown', event => {
    if (event.key === 'Enter' && event.target?.id === 'profileOpen') openProfileFromTop();
  });
}

function openProfileFromTop(){
  document.dispatchEvent(new CustomEvent('mgw:open-profile'));
}

export function setRoom(room){
  state.room = room;
  state.selectedBet = room === 'match' ? APP_CONFIG.matchBet : APP_CONFIG.goldBets[0];
  document.querySelectorAll('[data-room]').forEach(btn => btn.classList.toggle('active', btn.dataset.room === room));
  renderRoomCard();
}

export function renderRoomCard(){
  const el = document.getElementById('roomCard');
  if (!el) return;
  if (state.room === 'gold') {
    el.innerHTML = `
      <h3>Gold-комната</h3>
      <p>Игра на Gold-коины. Выигрыши можно использовать в магазине призов.</p>
      <div class="room-actions">
        <button class="btn gold" id="topUpGold" type="button">Пополнить Gold</button>
        <button class="btn ghost" id="storeOpen" type="button">Магазин</button>
      </div>
    `;
    document.getElementById('topUpGold')?.addEventListener('click', () => openTopUpSheet('gold'));
    document.getElementById('storeOpen')?.addEventListener('click', openStoreSheet);
  } else {
    el.innerHTML = `
      <h3>Матч-комната</h3>
      <p>Обычная комната для быстрых матчей. Участие всегда стоит 10 коинов.</p>
      <div class="room-actions single">
        <button class="btn primary" id="topUpMatch" type="button">Пополнить</button>
      </div>
    `;
    document.getElementById('topUpMatch')?.addEventListener('click', () => openTopUpSheet('match'));
  }
}

export function renderStats(stats){
  const el = document.getElementById('activityGrid');
  if (!el) return;
  const safe = stats || {};
  el.innerHTML = `
    <div class="activity-card"><div class="label">Игроков онлайн</div><div class="num">${safe.online_players ?? '—'}</div></div>
    <div class="activity-card"><div class="label">Активных матчей</div><div class="num">${safe.active_games ?? '—'}</div></div>
    <div class="activity-card"><div class="label">В Матч-комнате</div><div class="num">${safe.search_match ?? '—'}</div></div>
    <div class="activity-card"><div class="label">В Gold-комнате</div><div class="num">${safe.search_gold ?? '—'}</div></div>
  `;
}

function openGameSetup(){
  if (isSessionLocked(state.session)) return toast(sessionMessage(state.session));
  haptic('light');
  const isGold = state.room === 'gold';
  const betChoices = isGold
    ? APP_CONFIG.goldBets.map(bet => `<button class="choice gold ${bet === state.selectedBet ? 'active' : ''}" data-bet="${bet}" type="button">${bet} коинов</button>`).join('')
    : `<button class="choice active" data-bet="${APP_CONFIG.matchBet}" type="button">${APP_CONFIG.matchBet} коинов</button>`;

  openSheet(`
    <div class="sheet-head">
      <div><h2>Крестики-нолики</h2><p>${roomName(state.room)}. Выберите поле${isGold ? ' и стоимость участия' : ''}.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="setup-scroll">
      <div class="small-note">${isGold ? 'Матч начнётся только с соперником, который выбрал такие же условия.' : 'В Match-комнате участие всегда стоит 10 коинов.'}</div>
      <div class="section-title"><h2>Поле</h2></div>
      <div class="choice-grid field-size-grid" id="boardChoices">
        ${APP_CONFIG.boardSizes.map(size => `<button class="choice ${size === state.selectedBoardSize ? 'active' : ''}" data-board-size="${size}" type="button">${size}×${size}</button>`).join('')}
      </div>
      <div class="section-title"><h2>Стоимость участия</h2></div>
      <div class="choice-grid ${isGold ? '' : 'single-choice'}" id="betChoices">${betChoices}</div>
    </div>
    <button class="btn ${isGold ? 'gold' : 'primary'} full setup-start-btn" id="startSearchBtn" type="button">Начать поиск</button>
  `);

  document.querySelectorAll('[data-board-size]').forEach(btn => btn.addEventListener('click', () => {
    state.selectedBoardSize = Number(btn.dataset.boardSize);
    document.querySelectorAll('[data-board-size]').forEach(item => item.classList.toggle('active', item === btn));
  }));
  document.querySelectorAll('[data-bet]').forEach(btn => btn.addEventListener('click', () => {
    state.selectedBet = Number(btn.dataset.bet);
    document.querySelectorAll('[data-bet]').forEach(item => item.classList.toggle('active', item === btn));
  }));
  document.getElementById('startSearchBtn')?.addEventListener('click', startSearch);
}

async function startSearch(){
  if (isSessionLocked(state.session)) return toast(sessionMessage(state.session));
  try {
    closeSheet();
    const result = await api.startSearch(state.room, state.selectedBet, state.selectedBoardSize);
    state.user = result.user || state.user;
    renderBalances(state.user);
    if (result.game) {
      state.activeGame = result.game;
      showScreen('game');
      startGamePolling(result.game.id);
      return;
    }
    document.getElementById('searchInfo').textContent = `${roomName(state.room)} · участие ${state.selectedBet} коинов · поле ${state.selectedBoardSize}×${state.selectedBoardSize}`;
    showScreen('search');
    startSearchPolling();
  } catch (error) {
    toast(error.message);
  }
}

function openTopUpSheet(room){
  const isGold = room === 'gold';
  const title = isGold ? 'Пополнить Gold' : 'Пополнить Match';
  const coinName = isGold ? 'Gold' : 'Match';
  const rate = isGold ? 1 : 2;
  const balance = isGold ? (state.user?.balance_gold ?? 0) : (state.user?.balance_match ?? 0);

  openSheet(`
    <div class="sheet-head">
      <div><h2>${title}</h2><p>Введите сумму пополнения.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    <div class="topup-calc" data-topup-room="${room}">
      <div class="topup-balance">
        <span>Баланс сейчас</span>
        <strong>${balance} коинов</strong>
      </div>

      <div class="topup-calc-grid">
        <label class="topup-input-card">
          <span>Сумма, ₽</span>
          <input id="topupAmount" type="number" inputmode="numeric" min="1" max="100000" placeholder="0" autocomplete="off">
          <em>Максимум 100 000 ₽</em>
        </label>

        <div class="topup-result-card">
          <span>Вы получите</span>
          <strong id="topupCoins">0 ${coinName}</strong>
          <em id="topupRate">${isGold ? '1 ₽ = 1 Gold' : '1 ₽ = 2 Match'}</em>
        </div>
      </div>

      <button class="btn ${isGold ? 'gold' : 'primary'} full sheet-bottom-btn" id="topupContinue" type="button">
        Продолжить
      </button>
    </div>
  `);

  const input = document.getElementById('topupAmount');
  const coinsEl = document.getElementById('topupCoins');
  const continueBtn = document.getElementById('topupContinue');

  const update = () => {
    let amount = Number(input?.value || 0);
    if (amount < 0) amount = 0;
    if (amount > 100000) {
      amount = 100000;
      input.value = '100000';
      toast('Максимальная сумма пополнения — 100 000 ₽.');
    }

    const coins = Math.floor(amount * rate);
    if (coinsEl) coinsEl.textContent = `${coins.toLocaleString('ru-RU')} ${coinName}`;
  };

  input?.addEventListener('input', update);
  input?.focus();

  continueBtn?.addEventListener('click', async () => {
    const amount = Number(input?.value || 0);
    if (!amount || amount <= 0) {
      toast('Введите сумму пополнения.');
      return;
    }

    if (amount > 100000) {
      toast('Максимальная сумма пополнения — 100 000 ₽.');
      return;
    }

    const coins = Math.floor(amount * rate);

    try {
      continueBtn.disabled = true;
      continueBtn.textContent = 'Создаём заявку...';

      const result = await api.paymentCreateDraft(room, amount);
      const payment = result.payment || {};

      openSheet(`
        <div class="sheet-head">
          <div><h2>Заявка создана</h2><p>Пополнение зафиксировано.</p></div>
          <button class="close" data-close-sheet type="button">×</button>
        </div>

        <div class="topup-success">
          <div>
            <span>Комната</span>
            <strong>${isGold ? 'Gold' : 'Match'}</strong>
          </div>
          <div>
            <span>Сумма</span>
            <strong>${amount.toLocaleString('ru-RU')} ₽</strong>
          </div>
          <div>
            <span>К зачислению</span>
            <strong>${coins.toLocaleString('ru-RU')} ${coinName}</strong>
          </div>
          <div>
            <span>Номер заявки</span>
            <strong>${String(payment.id || '').replace('pay_', '').slice(0, 8).toUpperCase() || '—'}</strong>
          </div>
        </div>

        <div class="small-note">
          Сейчас это заявка на пополнение. Баланс изменится после подтверждения оплаты.
        </div>

        <button class="btn ${isGold ? 'gold' : 'primary'} full sheet-bottom-btn" data-close-sheet type="button">Готово</button>
      `);
    } catch (error) {
      toast(error.message || 'Не удалось создать заявку.');
      continueBtn.disabled = false;
      continueBtn.textContent = 'Продолжить';
    }
  });
}

async function openStoreSheet(){
  try {
    const result = await api.shopStatus();
    if (result.user) { state.user = result.user; renderBalances(state.user); }
    const shop = result.shop || {};
    openSheet(`
      <div class="sheet-head">
        <div><h2>Магазин призов</h2><p>Заказывайте сертификаты за коины из Gold-комнаты.</p></div>
        <button class="close" data-close-sheet type="button">×</button>
      </div>
      <div class="stack">
        <div class="card"><h3>Доступно для магазина</h3><p>Баланс Gold-комнаты: ${shop.balance_gold ?? 0} коинов.<br>Доступно для заказа: ${shop.available ?? 0} коинов.<br>Минимальный заказ: ${shop.min_order ?? 1000} коинов.</p></div>
        <div class="small-note">Купленные коины нельзя сразу тратить в магазине. Они становятся доступными после участия в Gold-матчах.</div>
      </div>
    `);
  } catch (error) { toast(error.message); }
}

function openMoreMenuSheet(){
  openSheet(`
    <div class="sheet-head">
      <div><h2>Меню</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="menu-list">
      <button class="btn menu-item" id="rulesBtn" type="button">📘 Правила</button>
      <button class="btn menu-item" id="feedbackBtn" type="button">💬 Обратная связь</button>
      <button class="btn menu-item" id="ideaBtn" type="button">💡 Предложить идею</button>
      <button class="btn menu-item danger" id="supportBtn" type="button">⚠️ Пожаловаться</button>
      <button class="btn menu-item" id="balanceHistoryBtn" type="button">🧾 История баланса</button>
      <button class="btn menu-item" id="matchHistoryBtn" type="button">🎮 История матчей</button>
    </div>
  `);

  document.getElementById('rulesBtn')?.addEventListener('click', openRulesSheet);
  document.getElementById('feedbackBtn')?.addEventListener('click', () => openSupportForm('feedback'));
  document.getElementById('ideaBtn')?.addEventListener('click', () => openSupportForm('idea'));
  document.getElementById('supportBtn')?.addEventListener('click', () => openSupportForm('complaint'));
  document.getElementById('balanceHistoryBtn')?.addEventListener('click', openBalanceHistorySheet);
  document.getElementById('matchHistoryBtn')?.addEventListener('click', openMatchHistorySheet);
}

function openRulesSheet(){
  const match = state.room === 'match';

  openSheet(`
    <div class="sheet-head">
      <div><h2>${match ? 'Правила Match-комнаты' : 'Правила Gold-комнаты'}</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    ${match ? `
      <div class="rules-content">
        <p><strong>Match-комната</strong> — обычная комната для разминки и быстрых матчей.</p>

        <p>Здесь используются коины Match-комнаты. Это внутренняя игровая валюта для матчей внутри этой комнаты.</p>

        <p>Участие в матче в Match-комнате всегда стоит <strong>10 коинов</strong>.</p>

        <p>Матч начинается только после подбора соперника с подходящими условиями игры.</p>

        <p>Если вы побеждаете, выигрыш начисляется на баланс Match-комнаты.</p>

        <p>Комиссия клуба составляет <strong>10% от общей суммы завершённого матча</strong>.</p>

        <p>Если матч закончился ничьей, коины возвращаются игрокам.</p>

        <p>Каждый понедельник в <strong>12:00</strong> активным игрокам начисляется <strong>+50 коинов</strong>.</p>

        <p>Активным считается игрок, который впервые вошёл в игру или сыграл минимум <strong>3 завершённых матча</strong> за неделю.</p>

        <p>Начисление происходит один раз в неделю. Если игрок не был активен, еженедельные коины не начисляются.</p>

        <p>Коины Match-комнаты используются только внутри Match-комнаты и не переносятся в Gold-комнату.</p>

        <p>Все списания, начисления, комиссии и результаты матчей сохраняются в истории операций.</p>

        <p>Если вы заметили ошибку в балансе или результате матча, отправьте обращение через меню помощи. Администратор сможет проверить историю операций.</p>
      </div>
    ` : `
      <div class="rules-content">
        <p><strong>Gold-комната</strong> — комната для матчей с Gold-коинами и доступом к магазину призов.</p>

        <p>Здесь используются Gold-коины. Это внутренняя игровая валюта, которая применяется для участия в матчах Gold-комнаты и заказа призов в магазине.</p>

        <p>Перед началом матча игрок выбирает стоимость участия. Матч начинается только после подбора соперника с такими же условиями игры.</p>

        <p>Если вы побеждаете, выигрыш начисляется на ваш Gold-баланс.</p>

        <p>Комиссия клуба составляет <strong>10% от общей суммы завершённого матча</strong>.</p>

        <p>Если матч закончился ничьей, коины возвращаются игрокам.</p>

        <p>Gold-коины можно будет пополнить через официальные Telegram-платежи.</p>

        <p>Купленные Gold-коины нельзя сразу использовать для заказа призов. Сначала нужно участвовать в завершённых матчах Gold-комнаты.</p>

        <p>В магазине можно использовать только те Gold-коины, которые уже были задействованы в завершённых Gold-матчах.</p>

        <p>Заказать приз можно только в пределах текущего Gold-баланса.</p>

        <p>Минимальная сумма заказа в магазине — <strong>1000 коинов</strong>.</p>

        <p>Если для магазина доступно меньше <strong>1000 коинов</strong>, заказ приза пока недоступен.</p>

        <p>Призы выдаются вручную администратором после проверки заявки.</p>

        <p>Срок обработки заявки — до <strong>24 часов</strong>.</p>

        <p>Список призов, доступные номиналы и условия выдачи могут меняться.</p>

        <p>Gold-коины нельзя обменять на деньги или вывести на карту. Их можно использовать только внутри игры и магазина призов.</p>

        <p>Все пополнения, начисления, списания, комиссии, результаты матчей и заявки на призы сохраняются в истории операций.</p>

        <p>Если вы заметили ошибку в балансе, матче или заявке на приз, отправьте обращение через меню помощи. Администратор сможет проверить историю операций.</p>
      </div>
    `}

    <button class="btn primary full sheet-bottom-btn" data-close-sheet type="button">Понятно</button>
  `);
}

async function openBalanceHistorySheet(){
  openSheet(`
    <div class="sheet-head">
      <div><h2>История баланса</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="small-note">Загружаем историю…</div>
  `);

  try {
    const result = await api.history();
    if (result.user) { state.user = result.user; renderBalances(state.user); }
    renderHistorySheet(result.history || {}, result.topups || []);
  } catch (error) {
    openSheet(`
      <div class="sheet-head">
        <div><h2>История баланса</h2></div>
        <button class="close" data-close-sheet type="button">×</button>
      </div>
      <div class="small-note">${escapeHtml(error.message)}</div>
      <button class="btn ghost full" data-close-sheet type="button">Понятно</button>
    `);
  }
}

async function openMatchHistorySheet(){
  openSheet(`
    <div class="sheet-head">
      <div><h2>История матчей</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="small-note">Загружаем матчи…</div>
  `);

  try {
    const result = await api.history();
    renderMatchHistorySheet(result.history?.matches || []);
  } catch (error) {
    openSheet(`
      <div class="sheet-head">
        <div><h2>История матчей</h2></div>
        <button class="close" data-close-sheet type="button">×</button>
      </div>
      <div class="small-note">${escapeHtml(error.message)}</div>
      <button class="btn ghost full" data-close-sheet type="button">Понятно</button>
    `);
  }
}

function renderHistorySheet(history, topups = []){
  const operations = history.operations || [];

  const topupHtml = topups.length
    ? topups.slice(0, 20).map(item => {
      const room = item.room === 'match' ? 'Match' : 'Gold';
      const status = topupStatusText(item.status);
      const tone = topupTone(item.status);
      const price = Number(item.price || item.amount_rub || 0).toLocaleString('ru-RU');
      const coins = Number(item.coins || 0).toLocaleString('ru-RU');
      const reason = item.status === 'rejected' && item.reject_reason
        ? `<span>Причина: ${escapeHtml(item.reject_reason)}</span>`
        : '';

      return `
        <div class="history-item">
          <div>
            <strong>${escapeHtml(status)}</strong>
            <span>${escapeHtml(room)} · ${price} ₽ → ${coins} коинов</span>
            ${reason}
            <em>#${escapeHtml(item.short_id || '')} · ${escapeHtml(formatDate(item.created_at))}</em>
          </div>
          <b class="${tone}">${escapeHtml(topupAmountLabel(item))}</b>
        </div>
      `;
    }).join('')
    : `<div class="small-note">Заявок на пополнение пока нет.</div>`;

  const operationHtml = operations.length
    ? operations.slice(0, 20).map(item => `
      <div class="history-item">
        <div>
          <strong>${escapeHtml(item.title || 'Операция')}</strong>
          <span>${escapeHtml(item.description || '')}</span>
          <em>${escapeHtml(formatDate(item.created_at))}</em>
        </div>
        <b class="${item.tone === 'pos' ? 'pos' : (item.tone === 'neg' ? 'neg' : '')}">${escapeHtml(item.amount_label || '0 коинов')}</b>
      </div>
    `).join('')
    : `<div class="small-note">Операций пока нет.</div>`;

  openSheet(`
    <div class="sheet-head">
      <div><h2>История баланса</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    <div class="history-tabs" role="tablist">
      <button class="history-tab active" data-history-tab="operations" type="button">Операции</button>
      <button class="history-tab" data-history-tab="topups" type="button">Пополнения</button>
    </div>

    <div class="history-scroll">
      <div class="history-tab-panel active" data-history-panel="operations">
        <div class="history-section">
          <h3>Операции баланса</h3>
          <div class="history-list">${operationHtml}</div>
        </div>
      </div>

      <div class="history-tab-panel" data-history-panel="topups">
        <div class="history-section">
          <h3>Пополнения</h3>
          <div class="history-list">${topupHtml}</div>
        </div>
      </div>
    </div>

    <button class="btn ghost full" data-close-sheet type="button">Понятно</button>
  `);

  bindHistoryTabs();
}

function renderMatchHistorySheet(matches = []){
  const matchHtml = matches.length
    ? matches.slice(0, 20).map(item => {
      const result = item.result || 'Матч';
      const tone = item.tone === 'pos' ? 'pos' : (item.tone === 'neg' ? 'neg' : '');
      const room = item.room_label || (item.room === 'gold' ? 'Gold' : 'Match');
      const board = item.board_size ? `${item.board_size}×${item.board_size}` : 'поле';
      const bet = Number(item.bet || 0).toLocaleString('ru-RU');
      const opponent = item.opponent || 'Соперник';
      const payout = item.payout ? '+' + Number(item.payout).toLocaleString('ru-RU') + ' коинов' : '';
      const date = formatDate(item.finished_at || item.created_at);

      return `
        <div class="history-item match-history-item">
          <div>
            <strong>${escapeHtml(result)}</strong>
            <span>${escapeHtml(room)} · ${escapeHtml(board)} · ставка ${bet} коинов</span>
            <span>Соперник: ${escapeHtml(opponent)}</span>
            <em>#${escapeHtml(item.short_id || '')} · ${escapeHtml(date)}</em>
          </div>
          <b class="${tone}">${escapeHtml(payout)}</b>
        </div>
      `;
    }).join('')
    : `<div class="small-note">Истории матчей пока нет.</div>`;

  openSheet(`
    <div class="sheet-head">
      <div><h2>История матчей</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    <div class="history-scroll">
      <div class="history-section">
        <h3>Последние игры</h3>
        <div class="history-list">${matchHtml}</div>
      </div>
    </div>

    <button class="btn ghost full" data-close-sheet type="button">Понятно</button>
  `);
}

function bindHistoryTabs(){
  const tabs = document.querySelectorAll('[data-history-tab]');
  const panels = document.querySelectorAll('[data-history-panel]');

  tabs.forEach(tab => {
    tab.addEventListener('click', () => {
      const target = tab.dataset.historyTab;

      tabs.forEach(item => item.classList.toggle('active', item === tab));
      panels.forEach(panel => {
        panel.classList.toggle('active', panel.dataset.historyPanel === target);
      });
    });
  });
}

function topupStatusText(status){
  if (status === 'paid') return 'Пополнение начислено';
  if (status === 'rejected') return 'Заявка отклонена';
  if (status === 'cancelled') return 'Заявка отменена';
  if (status === 'pending') return 'Ожидает оплаты';
  return 'Заявка на пополнение';
}

function topupTone(status){
  if (status === 'paid') return 'pos';
  if (status === 'rejected' || status === 'cancelled') return 'neg';
  return '';
}

function topupAmountLabel(item){
  if (item.status === 'paid') {
    return '+' + Number(item.coins || 0).toLocaleString('ru-RU') + ' коинов';
  }

  if (item.status === 'rejected' || item.status === 'cancelled') {
    return '0 коинов';
  }

  return 'ожидает';
}

function formatDate(value){
  if (!value) return '';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleString('ru-RU', { day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit' });
}

function escapeHtml(value){
  return String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
}

function openSupportForm(type){
  openSheet(`
    <div class="sheet-head">
      <div><h2>${type === 'idea' ? 'Предложить идею' : (type === 'feedback' ? 'Обратная связь' : 'Обращение в поддержку')}</h2><p>Опишите ситуацию, мы сохраним обращение.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <textarea id="supportText" class="form-textarea" placeholder="Напишите сообщение"></textarea>
    <button class="btn primary full" id="sendSupport" type="button">Отправить</button>
  `);
  document.getElementById('sendSupport')?.addEventListener('click', async () => {
    const message = document.getElementById('supportText').value.trim();
    if (!message) return toast('Напишите сообщение.');
    try {
      await api.support(type, message);
      closeSheet();
      toast('Сообщение сохранено.');
    } catch (error) { toast(error.message); }
  });
}
