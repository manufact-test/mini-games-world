import { state } from '../state.js?v=27';
import { APP_CONFIG } from '../config.js?v=38';
import { api } from '../api/client.js?v=47';
import { toast } from '../components/toast.js?v=41';
import { openSheet, closeSheet } from '../components/sheet.js?v=68';
import { showScreen } from '../router.js?v=27';
import { haptic } from '../telegram/telegram-app.js?v=27';
import { renderBalances } from '../ui.js?v=90-wallet-15-3';
import { t, setExplicitLocale } from '@mgw/i18n';

const HISTORY_CACHE_MAX_AGE_MS = 15000;
let historyCache = null;
let historyCacheAt = 0;
let historyCachePromise = null;

const SUPPORT_TICKETS_CACHE_MAX_AGE_MS = 15000;
const PLAYER_REPORT_REASONS = Object.freeze([
  ['nickname','Недопустимый никнейм'],
  ['avatar','Недопустимый аватар'],
  ['spam','Спам'],
  ['cheating','Нечестная игра'],
  ['stalling','Затягивание игры'],
  ['other','Другое'],
]);
let supportTicketsCache = null;
let supportTicketsCacheAt = 0;
let supportTicketsCachePromise = null;

window.__MGW_MATCH_HISTORY_UI_BUILD__ = 'mvp17-5-history-economy-live-owner-v3';
window.__MGW_HISTORY_MODAL_UX_BUILD__ = 'mvp17-5-prefetched-history-v3';

export function initHomeScreen(){
  document.addEventListener('click', event => {
    const target = event.target.closest('button, [role="button"]');
    if (!target) return;
    if (target.id === 'moreMenuOpen' || target.id === 'gameMenuOpen') return openMoreMenuSheet();
    if (target.id === 'profileOpen') return openProfileFromTop();
    if (target.matches('[data-back-home]')) return showScreen('home');
  });
  document.addEventListener('keydown', event => { if (event.key === 'Enter' && event.target?.id === 'profileOpen') openProfileFromTop(); });
  document.addEventListener('mgw:open-language-settings', openLanguageSettingsSheet);
  document.addEventListener('mgw:open-support-ticket', event => {
    const ticketNumber = String(event.detail?.ticket || '').trim();
    if (!/^SUP-[0-9]{6}-[A-F0-9]{8}$/i.test(ticketNumber)) return;
    const preserveSheet = String(event.detail?.source || '') === 'notification';
    showScreen('home');
    if (!preserveSheet) closeSheet();
    void openSupportTicketDetail(ticketNumber);
  });
  document.addEventListener('mgw:game-finished', () => {
    historyCacheAt = 0;
    window.setTimeout(() => { void refreshHistoryCache({ force:true }).catch(() => {}); }, 700);
  });
  document.addEventListener('mgw:app-ready', () => {
    window.setTimeout(() => {
      const activeGameId = String(state.activeGame?.id || '').trim();
      const activeGameStatus = String(state.activeGame?.status || '').trim().toLowerCase();
      if (activeGameId && !['finished','cancelled','canceled','abandoned'].includes(activeGameStatus)) return;
      void refreshHistoryCache({ force:true }).catch(() => {});
    }, 700);
  }, { once:true });
}
function openProfileFromTop(){ document.dispatchEvent(new CustomEvent('mgw:open-profile')); }
export function renderStats(stats){
  const el=document.getElementById('activityGrid'); if(!el)return; const safe=stats||{};
  el.innerHTML=`<div class="activity-card"><div class="label">Игроков онлайн</div><div class="num">${safe.online_players ?? '—'}</div></div><div class="activity-card"><div class="label">Активных матчей</div><div class="num">${safe.active_games ?? '—'}</div></div>`;
}

function openMoreMenuSheet(){
  void refreshHistoryCache().catch(() => {});
  void refreshSupportTicketsCache().catch(() => {});
  openSheet(`<div class="sheet-head"><div><h2>Меню</h2></div><button class="close" data-close-sheet type="button">×</button></div><div class="menu-list">
    ${menuItemMarkup('settingsBtn', '⚙️', t('settings.title'))}
    ${menuItemMarkup('rulesBtn', '📘', 'Правила')}
    ${menuItemMarkup('feedbackBtn', '💬', 'Обратная связь')}
    ${menuItemMarkup('ideaBtn', '💡', 'Предложить идею')}
    ${menuItemMarkup('supportBtn', '⚠️', 'Пожаловаться', 'danger')}
    ${menuItemMarkup('supportTicketsBtn', '🎫', 'Мои обращения', 'support-attention')}
    ${menuItemMarkup('balanceHistoryBtn', '🧾', 'История баланса')}
    ${menuItemMarkup('matchHistoryBtn', '🎮', 'История матчей')}
  </div>`);
  document.getElementById('settingsBtn')?.addEventListener('click', openSettingsSheet);
  document.getElementById('rulesBtn')?.addEventListener('click', openRulesSheet);
  document.getElementById('feedbackBtn')?.addEventListener('click',()=>openSupportForm('feedback'));
  document.getElementById('ideaBtn')?.addEventListener('click',()=>openSupportForm('idea'));
  document.getElementById('supportBtn')?.addEventListener('click',()=>openPlayerReportSheet());
  document.getElementById('supportTicketsBtn')?.addEventListener('click',()=>void openSupportTicketsSheet());
  document.getElementById('balanceHistoryBtn')?.addEventListener('click',openBalanceHistorySheet);
  document.getElementById('matchHistoryBtn')?.addEventListener('click',openMatchHistorySheet);
}

function menuItemMarkup(id, icon, label, tone = ''){
  const toneClass = tone ? ` ${tone}` : '';
  return `<button class="btn menu-item menu-item-standard${toneClass}" id="${escapeHtml(id)}" type="button"><span class="menu-item-icon" aria-hidden="true">${escapeHtml(icon)}</span><span class="menu-item-label">${escapeHtml(label)}</span></button>`;
}

function openSettingsSheet(){
  openSheet(`<div class="sheet-head"><div><h2>${escapeHtml(t('settings.title'))}</h2></div><button class="close" data-close-sheet type="button">×</button></div><div class="menu-list"><button class="btn menu-item" id="languageSettingsBtn" type="button">🌐 ${escapeHtml(t('settings.language'))}<span>${escapeHtml(t('settings.language_ru'))}</span></button></div>`);
  document.getElementById('languageSettingsBtn')?.addEventListener('click', openLanguageSettingsSheet);
}

function openLanguageSettingsSheet(){
  openSheet(`<div class="sheet-head"><div><h2>${escapeHtml(t('settings.language'))}</h2><p>${escapeHtml(t('settings.language_note'))}</p></div><button class="close" data-close-sheet type="button">×</button></div><div class="menu-list"><button class="btn menu-item active" id="languageRuBtn" type="button">${escapeHtml(t('settings.language_ru'))}<span>✓</span></button></div>`);
  document.getElementById('languageRuBtn')?.addEventListener('click', async () => {
    try {
      const result = await api.profileV2({ preferred_locale:'ru' });
      if (result?.profile) state.mgwProfile = result.profile;
      setExplicitLocale('ru');
      closeSheet();
      toast(t('settings.language_saved'));
    } catch (error) { toast(error.message || t('profile.save_error')); }
  });
}

function openRulesSheet(){
  const economy = APP_CONFIG.matchEconomy || {};
  const entry = Number(economy.entry_cost ?? APP_CONFIG.matchBet);
  const winnerReward = Number(economy.winner_reward);
  const commission = Number(economy.system_sink);
  const drawRefund = Number(economy.draw_refund);
  const pot = Number.isFinite(entry) ? entry * 2 : 0;
  const winnerNet = Number.isFinite(winnerReward) && Number.isFinite(entry) ? winnerReward - entry : 0;

  const amount = value => Number.isFinite(Number(value))
    ? `${Math.trunc(Number(value))} коинов`
    : 'текущая сумма';

  openSheet(`
    <div class="sheet-head">
      <div>
        <h2>Как работают обычные матчи</h2>
        <p>Коротко о поиске соперника, коинах, победе, ничьей и истории игры.</p>
      </div>
      <button class="close" data-close-sheet type="button" aria-label="Закрыть">×</button>
    </div>

    <div class="rules-content rules-guide">
      <section class="rules-guide-section">
        <h3>1. Как начать игру</h3>
        <p>Выберите игру на главном экране, настройте доступные для неё параметры и запустите поиск соперника. Пока идёт поиск, коины не списываются. Ставка снимается только тогда, когда матч действительно создан.</p>
        <p>Сначала система старается подобрать другого игрока с подходящими условиями. Если подходящего игрока долго нет, в обычном поиске соперником может стать бот.</p>
        <p>Хотите сыграть с конкретным человеком — откройте <strong>«Друзья»</strong>, найдите его по нику или MGW-ID, откройте меню <strong>«…»</strong> и выберите <strong>«Пригласить в игру»</strong>.</p>
      </section>

      <section class="rules-guide-section rules-guide-economy">
        <h3>2. Сколько стоит матч</h3>
        <p>Участие стоит <strong>${escapeHtml(amount(entry))}</strong> с каждого игрока. Поэтому общий банк обычного матча сейчас составляет <strong>${escapeHtml(amount(pot))}</strong>.</p>
        <div class="rules-guide-numbers" role="list" aria-label="Расчёт обычного матча">
          <div role="listitem"><span>Ставка игрока</span><strong>${escapeHtml(amount(entry))}</strong></div>
          <div role="listitem"><span>Банк матча</span><strong>${escapeHtml(amount(pot))}</strong></div>
          <div role="listitem"><span>Получает победитель</span><strong>${escapeHtml(amount(winnerReward))}</strong></div>
          <div role="listitem"><span>Комиссия системы</span><strong>${escapeHtml(amount(commission))}</strong></div>
        </div>
        <p>Например, при текущих условиях победитель получает обратно свою ставку вместе с выигрышем: на баланс начисляется <strong>${escapeHtml(amount(winnerReward))}</strong>. Чистый результат победителя относительно баланса до матча — <strong>+${escapeHtml(amount(winnerNet))}</strong>.</p>
      </section>

      <section class="rules-guide-section">
        <h3>3. Победа, поражение и ничья</h3>
        <p><strong>Победа:</strong> победитель получает <strong>${escapeHtml(amount(winnerReward))}</strong> из общего банка, а <strong>${escapeHtml(amount(commission))}</strong> составляет комиссия системы.</p>
        <p><strong>Поражение:</strong> ставка за этот матч не возвращается.</p>
        <p><strong>Ничья:</strong> каждому игроку возвращается его ставка — сейчас <strong>${escapeHtml(amount(drawRefund))}</strong>. При ничьей комиссии нет.</p>
      </section>

      <section class="rules-guide-section">
        <h3>4. Не выходите из незавершённого матча</h3>
        <p>Если матч уже начался и вы вручную выходите из него до завершения, приложение предупредит о последствиях. Такой выход может быть засчитан как техническое поражение, поэтому лучше закончить партию или вернуться в неё после временного обрыва связи.</p>
      </section>

      <section class="rules-guide-section">
        <h3>5. Правила конкретной игры</h3>
        <p>У каждой игры свои правила, размеры поля и доступные варианты. Перед матчем или внутри игрового интерфейса откройте кнопку <strong>«Правила»</strong> — там описана именно выбранная игра. Этот раздел объясняет общие правила обычных матчей и коинов.</p>
      </section>

      <section class="rules-guide-section">
        <h3>6. Где проверить результат</h3>
        <p>В меню <strong>«Ещё»</strong> доступны <strong>«История баланса»</strong> и <strong>«История матчей»</strong>. Там можно посмотреть списание за участие, возврат при ничьей, начисление за победу и последние сыгранные партии.</p>
      </section>

      <section class="rules-guide-section">
        <h3>7. Бонусы и турниры</h3>
        <p>Условия еженедельного бесплатного бонуса показываются отдельно в карточке баланса — используйте кнопку <strong>«Еженедельный бонус»</strong>, чтобы увидеть актуальные условия.</p>
        <p>Турниры — отдельный режим со своими условиями регистрации, матчей и наград. Правила обычного матча из этого окна не заменяют правила конкретного турнира.</p>
      </section>

      <section class="rules-guide-section">
        <h3>8. Если что-то пошло не так</h3>
        <p>Если баланс, результат матча или другое действие выглядит неправильно, откройте <strong>«Ещё» → «Обратная связь»</strong>. Для жалобы на другого игрока используйте отдельный пункт <strong>«Пожаловаться»</strong>.</p>
      </section>
    </div>

    <button class="btn primary full sheet-bottom-btn" data-close-sheet type="button">Понятно</button>
  `);
}

async function openBalanceHistorySheet(){
  try {
    const result=historyCache || await refreshHistoryCache({ force:true });
    if(result.user){state.user=result.user;renderBalances(state.user);}
    renderHistorySheet(result.history||{});
    void refreshHistoryCache({ force:isHistoryCacheStale() }).catch(() => {});
  } catch(error){ openSheet(`<div class="sheet-head"><div><h2>История баланса</h2></div><button class="close" data-close-sheet type="button">×</button></div><div class="small-note">${escapeHtml(error.message)}</div><button class="btn ghost full" data-close-sheet type="button">Понятно</button>`); }
}
async function openMatchHistorySheet(){
  try {
    const result=historyCache || await refreshHistoryCache({ force:true });
    renderMatchHistorySheet(result.history?.matches||[]);
    void refreshHistoryCache({ force:isHistoryCacheStale() }).catch(() => {});
  } catch(error){ openSheet(`<div class="sheet-head"><div><h2>История матчей</h2></div><button class="close" data-close-sheet type="button">×</button></div><div class="small-note">${escapeHtml(error.message)}</div><button class="btn ghost full" data-close-sheet type="button">Понятно</button>`); }
}
function isHistoryCacheStale(){return !historyCache || (Date.now()-historyCacheAt)>=HISTORY_CACHE_MAX_AGE_MS;}
function refreshHistoryCache({force=false}={}){
  if(!force&&!isHistoryCacheStale())return Promise.resolve(historyCache);
  if(historyCachePromise)return historyCachePromise;
  historyCachePromise=api.historyFast()
    .then(result=>{historyCache=result;historyCacheAt=Date.now();return result;})
    .finally(()=>{historyCachePromise=null;});
  return historyCachePromise;
}
function renderHistorySheet(history){
  const operations=history.operations||[];
  const operationHtml=operations.length?operations.slice(0,20).map(item=>`<div class="history-item"><div><strong>${escapeHtml(item.title||'Операция')}</strong><span>${escapeHtml(item.description||'')}</span><em>${escapeHtml(formatDate(item.created_at))}</em></div><b class="${item.tone==='pos'?'pos':(item.tone==='neg'?'neg':'')}">${escapeHtml(item.amount_label||'0 коинов')}</b></div>`).join(''):`<div class="small-note">Операций пока нет.</div>`;
  openSheet(`<div class="sheet-head"><div><h2>История баланса</h2></div><button class="close" data-close-sheet type="button">×</button></div><div class="history-scroll"><div class="history-section"><h3>Операции баланса</h3><div class="history-list">${operationHtml}</div></div></div><button class="btn ghost full" data-close-sheet type="button">Понятно</button>`);
}
function renderMatchHistorySheet(matches=[]){
  const matchHtml=matches.length?matches.slice(0,20).map(item=>{
    const result=item.result||'Матч';
    const tone=item.tone==='pos'?'pos':(item.tone==='neg'?'neg':'');
    const game=item.game_title||'Матч';
    const columns=Number(item.board_columns||item.board_size||0);
    const rows=Number(item.board_rows||item.board_size||0);
    const board=columns>0&&rows>0?`${columns}×${rows}`:'';
    const opponent=item.opponent||'Соперник';
    const economy=item.economy&&typeof item.economy==='object'?item.economy:null;
    const date=formatDate(item.finished_at||item.created_at);
    const delta=economy?matchDelta(economy.ledger_delta):'';
    return `<div class="history-item match-history-item"><div><strong>${escapeHtml(result)}</strong><span>${escapeHtml([game,board].filter(Boolean).join(' · '))}</span><span>Соперник: ${escapeHtml(opponent)}</span><em>${escapeHtml(date)}</em></div><b class="${tone}">${escapeHtml(delta)}</b></div>`;
  }).join(''):`<div class="small-note">Истории матчей пока нет.</div>`;
  openSheet(`<div class="sheet-head"><div><h2>История матчей</h2></div><button class="close" data-close-sheet type="button">×</button></div><div class="history-scroll"><div class="history-section"><h3>Последние игры</h3><div class="history-list">${matchHtml}</div></div></div><button class="btn ghost full" data-close-sheet type="button">Понятно</button>`);
}
function matchDelta(value){if(value===null||value===undefined||!Number.isFinite(Number(value)))return'—';const normalized=Math.trunc(Number(value));return `${normalized>0?'+':''}${normalized} коинов`;}
function formatDate(value){if(!value)return'';const date=new Date(value);if(Number.isNaN(date.getTime()))return String(value);return date.toLocaleString('ru-RU',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});}
function escapeHtml(value){return String(value??'').replace(/[&<>'"]/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));}
function openSupportForm(type){
  const defaults={feedback:'feedback',idea:'idea'};
  const category=defaults[type]||'other';
  const titles={
    feedback:'Обратная связь',
    idea:'Предложить идею',
  };
  const messagePlaceholders={
    feedback:'Напишите сообщение',
    idea:'Опишите идею',
  };
  const title=titles[type]||'Обращение';
  const messagePlaceholder=messagePlaceholders[type]||'Опишите ситуацию';

  openSheet(`<div class="sheet-head support-ticket-create-head"><div><h2>${escapeHtml(title)}</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="support-ticket-create">
      <div class="support-ticket-create-scroll">
        <label class="support-ticket-field">
          <span class="support-ticket-label">Категория</span>
          <span class="support-ticket-select-wrap">
            <select id="supportCategory" class="support-ticket-control support-ticket-select">
              <option value="feedback">Обратная связь</option>
              <option value="idea">Предложение</option>
              <option value="technical">Техническая проблема</option>
              <option value="payment">Платёж / коины</option>
              <option value="game">Игра / матч</option>
              <option value="tournament">Турнир</option>
              <option value="account">Аккаунт</option>
              <option value="other">Другое</option>
            </select>
          </span>
        </label>

        <label class="support-ticket-field">
          <span class="support-ticket-label">Тема</span>
          <input id="supportSubject" class="support-ticket-control" maxlength="160" placeholder="Короткая тема">
        </label>

        <label class="support-ticket-field">
          <span class="support-ticket-label">Приоритет</span>
          <span class="support-ticket-select-wrap">
            <select id="supportPriority" class="support-ticket-control support-ticket-select">
              <option value="normal">Обычный</option>
              <option value="high">Высокий</option>
              <option value="critical">Критический</option>
              <option value="low">Низкий</option>
            </select>
          </span>
        </label>

        <label class="support-ticket-field support-ticket-message-field">
          <span class="support-ticket-label">Сообщение</span>
          <textarea id="supportText" class="support-ticket-control support-ticket-message" maxlength="4000" placeholder="${escapeHtml(messagePlaceholder)}"></textarea>
        </label>

        <div class="support-file-picker">
          <div class="support-file-picker-head">
            <div><strong>Вложения</strong><small>Необязательно · до 3 файлов, до 2 МБ каждый</small></div>
            <button class="support-file-add" id="supportFilesTrigger" type="button">＋ Добавить</button>
          </div>
          <input id="supportFiles" class="support-file-native" type="file" multiple accept="image/jpeg,image/png,image/webp,image/gif,application/pdf,text/plain">
          <div class="support-file-list" id="supportFilesList"></div>
        </div>
      </div>
      <button class="btn primary full support-ticket-submit" id="sendSupport" type="button">Создать обращение</button>
    </div>`);

  const categoryNode=document.getElementById('supportCategory');
  if(categoryNode) categoryNode.value=category;
  const supportFilePicker=mountSupportFilePicker('supportFiles','supportFilesTrigger','supportFilesList');

  document.getElementById('sendSupport')?.addEventListener('click',async()=>{
    const message=document.getElementById('supportText')?.value.trim()||'';
    if(!message)return toast('Напишите сообщение.');
    const button=document.getElementById('sendSupport');
    if(button)button.disabled=true;
    try{
      const attachments=await supportFilesPayload(supportFilePicker.getFiles());
      const result=await api.supportCreate({
        category:document.getElementById('supportCategory')?.value||category,
        priority:document.getElementById('supportPriority')?.value||'normal',
        subject:document.getElementById('supportSubject')?.value.trim()||'',
        message,
        attachments,
      });
      const number=result?.ticket?.ticket_number||'';
      supportTicketsCacheAt=0;
      closeSheet();
      toast(number?(`Обращение ${number} создано.`):'Обращение создано.');
    }catch(error){
      toast(error.message||'Не удалось создать обращение.');
    }finally{
      if(button)button.disabled=false;
    }
  });
}

function openPlayerReportSheet(){
  let selectedPlayer=null;
  let selectedReason='';

  openSheet(`<div class="sheet-head player-report-head"><div><h2>Пожаловаться на игрока</h2><p>Найдите игрока и выберите причину жалобы.</p></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="player-report-create">
      <section class="player-report-step">
        <div class="player-report-step-title"><b>1</b><span><strong>На кого вы хотите пожаловаться?</strong><small>Поиск по нику или MGW-ID</small></span></div>
        <div class="player-report-search">
          <input id="playerReportSearch" class="form-input" maxlength="40" autocomplete="off" placeholder="Ник или MGW-ID">
          <button class="btn primary" id="playerReportSearchBtn" type="button">Найти</button>
        </div>
        <div class="player-report-search-status" id="playerReportSearchStatus">Введите минимум 2 символа ника или полный MGW-ID.</div>
        <div class="player-report-results" id="playerReportResults"></div>
        <div class="player-report-selected" id="playerReportSelected" hidden></div>
      </section>

      <section class="player-report-step" id="playerReportReasonStep" hidden>
        <div class="player-report-step-title"><b>2</b><span><strong>Причина жалобы</strong><small>Выберите один вариант</small></span></div>
        <div class="player-report-reasons" id="playerReportReasons">
          ${PLAYER_REPORT_REASONS.map(([value,label])=>`<button type="button" data-player-report-reason="${escapeHtml(value)}" aria-pressed="false">${escapeHtml(label)}</button>`).join('')}
        </div>
        <label class="player-report-comment">
          <span>Комментарий <small>необязательно</small></span>
          <textarea id="playerReportComment" class="form-input" maxlength="800" placeholder="Кратко опишите ситуацию"></textarea>
        </label>
        <button class="btn primary full player-report-submit" id="playerReportSend" type="button" disabled>Отправить жалобу</button>
      </section>

      <details class="player-report-history">
        <summary><span>Мои жалобы</span><b id="playerReportHistoryCount">…</b></summary>
        <div class="player-report-history-list" id="playerReportHistoryList">
          <div class="player-report-history-empty">Загружаю историю…</div>
        </div>
      </details>
    </div>`);

  const searchInput=document.getElementById('playerReportSearch');
  const searchButton=document.getElementById('playerReportSearchBtn');
  const searchStatus=document.getElementById('playerReportSearchStatus');
  const results=document.getElementById('playerReportResults');
  const selected=document.getElementById('playerReportSelected');
  const reasonStep=document.getElementById('playerReportReasonStep');
  const send=document.getElementById('playerReportSend');
  const historyList=document.getElementById('playerReportHistoryList');
  const historyCount=document.getElementById('playerReportHistoryCount');

  const refreshSendState=()=>{
    if(send)send.disabled=!(selectedPlayer&&selectedReason);
  };

  const choosePlayer=player=>{
    selectedPlayer=player;
    if(selected){
      selected.hidden=false;
      selected.innerHTML=`<span><strong>${escapeHtml(player?.nickname||'Игрок')}</strong><small>${escapeHtml(player?.public_mgw_id||'')}</small></span><button type="button" id="playerReportChangeTarget">Изменить</button>`;
    }
    if(results)results.innerHTML='';
    if(searchStatus)searchStatus.textContent='Игрок выбран.';
    if(reasonStep)reasonStep.hidden=false;
    document.getElementById('playerReportChangeTarget')?.addEventListener('click',()=>{
      selectedPlayer=null;
      if(selected)selected.hidden=true;
      if(reasonStep)reasonStep.hidden=true;
      refreshSendState();
      searchInput?.focus();
    });
    refreshSendState();
  };

  const renderPlayers=players=>{
    if(!results)return;
    if(!Array.isArray(players)||players.length===0){
      results.innerHTML='';
      if(searchStatus)searchStatus.textContent='Игроки не найдены. Попробуйте другой ник или MGW-ID.';
      return;
    }
    if(searchStatus)searchStatus.textContent=`Найдено: ${players.length}. Выберите игрока.`;
    results.innerHTML=players.map((player,index)=>`<button class="player-report-result" type="button" data-player-report-target="${index}">
      <span><strong>${escapeHtml(player?.nickname||'Игрок')}</strong><small>${escapeHtml(player?.public_mgw_id||'')}</small></span><em>Выбрать</em>
    </button>`).join('');
    results.querySelectorAll('[data-player-report-target]').forEach(button=>button.addEventListener('click',()=>{
      const index=Number(button.dataset.playerReportTarget);
      const player=players[index];
      if(player)choosePlayer(player);
    }));
  };

  const runSearch=async()=>{
    const query=String(searchInput?.value||'').trim();
    const nicknameQuery=query.replace(/^@/u,'');
    const looksLikeMgwId=/^MGW-(?:ID-)?/iu.test(query);
    if(!query){
      if(searchStatus)searchStatus.textContent='Введите ник или MGW-ID.';
      searchInput?.focus();
      return;
    }
    if(!looksLikeMgwId&&Array.from(nicknameQuery).length<2){
      if(searchStatus)searchStatus.textContent='Для поиска по нику введите минимум 2 символа.';
      searchInput?.focus();
      return;
    }
    if(searchButton)searchButton.disabled=true;
    if(results)results.innerHTML='';
    if(searchStatus)searchStatus.textContent='Ищу игрока…';
    try{
      const response=await api.friends({action:'report_lookup',query});
      const players=Array.isArray(response?.result?.players)?response.result.players.filter(player=>player&&typeof player==='object'):[];
      renderPlayers(players);
    }catch(error){
      if(searchStatus)searchStatus.textContent=error?.message||'Не удалось выполнить поиск.';
    }finally{
      if(searchButton)searchButton.disabled=false;
    }
  };

  searchButton?.addEventListener('click',()=>void runSearch());
  searchInput?.addEventListener('keydown',event=>{
    if(event.key!=='Enter')return;
    event.preventDefault();
    void runSearch();
  });

  document.querySelectorAll('#sheet [data-player-report-reason]').forEach(button=>button.addEventListener('click',()=>{
    const reason=String(button.dataset.playerReportReason||'');
    selectedReason=selectedReason===reason?'':reason;
    document.querySelectorAll('#sheet [data-player-report-reason]').forEach(item=>{
      item.setAttribute('aria-pressed',selectedReason!==''&&String(item.dataset.playerReportReason||'')===selectedReason?'true':'false');
    });
    refreshSendState();
  }));

  const reportStatusLabel=value=>({
    open:'Новая',
    reviewing:'На рассмотрении',
    closed:'Рассмотрена',
  })[String(value||'')]||'—';

  const reportStatusTone=value=>({
    open:'is-new',
    reviewing:'is-reviewing',
    closed:'is-closed',
  })[String(value||'')]||'';

  const renderReportHistory=reports=>{
    const items=Array.isArray(reports)?reports:[];
    if(historyCount)historyCount.textContent=String(items.length);
    if(!historyList)return;
    if(items.length===0){
      historyList.innerHTML='<div class="player-report-history-empty">Вы ещё не отправляли жалобы на игроков.</div>';
      return;
    }
    historyList.innerHTML=items.map(report=>`<article class="player-report-history-item">
      <div class="player-report-history-top">
        <span><strong>${escapeHtml(report?.target_nickname||'Игрок')}</strong><small>${escapeHtml(report?.target_public_mgw_id||'')}</small></span>
        <b class="${escapeHtml(reportStatusTone(report?.status))}">${escapeHtml(reportStatusLabel(report?.status))}</b>
      </div>
      <div class="player-report-history-reason">${escapeHtml(report?.reason_label||report?.reason||'Жалоба')}</div>
      <small class="player-report-history-meta">${escapeHtml(formatDate(report?.resolved_at||report?.reviewed_at||report?.created_at||''))}${report?.status==='closed'?' · рассмотрение завершено':''}</small>
    </article>`).join('');
  };

  const loadReportHistory=async()=>{
    try{
      const response=await api.friends({action:'report_history'});
      renderReportHistory(response?.result?.reports||[]);
    }catch(error){
      if(historyCount)historyCount.textContent='!';
      if(historyList)historyList.innerHTML=`<div class="player-report-history-empty">${escapeHtml(error?.message||'Не удалось загрузить историю жалоб.')}</div>`;
    }
  };

  send?.addEventListener('click',async()=>{
    if(!selectedPlayer||!selectedReason)return;
    const details=String(document.getElementById('playerReportComment')?.value||'').trim();
    send.disabled=true;
    try{
      const response=await api.friends({
        action:'report',
        target_mgw_id:String(selectedPlayer?.mgw_id||''),
        reason:selectedReason,
        details,
        related_match_id:'',
      });
      const caseId=String(response?.result?.report_id||'');
      closeSheet();
      toast(caseId?`Жалоба отправлена · ${caseId}`:'Жалоба отправлена.');
    }catch(error){
      toast(error?.message||'Не удалось отправить жалобу.');
      if(send.isConnected)send.disabled=false;
    }
  });

  void loadReportHistory();
  searchInput?.focus();
}

function isSupportTicketsCacheStale(){
  return !supportTicketsCache || (Date.now()-supportTicketsCacheAt)>=SUPPORT_TICKETS_CACHE_MAX_AGE_MS;
}

function refreshSupportTicketsCache({force=false}={}){
  if(!force&&!isSupportTicketsCacheStale())return Promise.resolve(supportTicketsCache);
  if(supportTicketsCachePromise)return supportTicketsCachePromise;
  supportTicketsCachePromise=api.supportSnapshot()
    .then(result=>{supportTicketsCache=result;supportTicketsCacheAt=Date.now();return result;})
    .finally(()=>{supportTicketsCachePromise=null;});
  return supportTicketsCachePromise;
}

async function openSupportTicketsSheet(){
  try{
    const result=await refreshSupportTicketsCache({force:true});
    const tickets=Array.isArray(result?.tickets)?result.tickets:[];
    renderSupportTicketsSheet(tickets);
  }catch(error){
    toast(error.message||'Не удалось загрузить обращения.');
  }
}

function renderSupportTicketsSheet(tickets){
  const rows=tickets.map(ticket=>{
    const status=escapeHtml(supportTicketStatusLabel(ticket));
    const priority=escapeHtml(ticket.priority_label||ticket.priority||'');
    const category=escapeHtml(ticket.category_label||'');
    const date=escapeHtml(formatDate(ticket.updated_at||''));
    return `<button class="support-ticket-row" type="button" data-support-ticket="${escapeHtml(ticket.ticket_number||'')}">
      <span class="support-ticket-row-top"><strong>${escapeHtml(ticket.ticket_number||'Обращение')}</strong><em>${status}</em></span>
      <span class="support-ticket-row-subject">${escapeHtml(ticket.subject||ticket.category_label||'')}</span>
      <span class="support-ticket-row-meta"><span>${category}</span><span>${priority}</span><time>${date}</time></span>
    </button>`;
  }).join('');

  openSheet(`<div class="sheet-head support-hub-head"><div><h2>Мои обращения</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="support-ticket-summary">${tickets.length?`Обращений: ${tickets.length}`:'Обращений пока нет.'}</div>
    <div class="support-ticket-list">${rows}</div>`);

  document.querySelectorAll('[data-support-ticket]').forEach(button=>button.addEventListener('click',()=>void openSupportTicketDetail(button.dataset.supportTicket||'',button)));
}

async function openSupportTicketDetail(ticketNumber,sourceButton=null){
  if(!ticketNumber)return;
  if(sourceButton)sourceButton.disabled=true;
  try{
    const result=await api.supportTicket(ticketNumber);
    const ticket=result?.ticket;
    if(!ticket)throw new Error('Не удалось открыть обращение.');
    renderSupportTicketDetail(ticketNumber,ticket);
  }catch(error){
    toast(error.message||'Не удалось открыть обращение.');
    if(sourceButton?.isConnected)sourceButton.disabled=false;
  }
}

function renderSupportTicketDetail(ticketNumber,ticket){
  const closed=String(ticket?.status||'').trim().toLowerCase()==='closed';
  openSheet(`<div class="sheet-head support-thread-head"><div><h2>${escapeHtml(ticketNumber)}</h2><p id="supportTicketMeta">${escapeHtml(supportTicketStatusLabel(ticket))}</p></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="support-thread-detail" id="supportThreadDetail">
      <div class="support-thread" id="supportTicketThread"></div>
      <div class="support-reply-panel">
        <button class="support-reply-toggle" id="supportReplyToggle" type="button" aria-expanded="false" ${closed?'disabled':''}>
          <span data-support-reply-toggle-label>${closed?'Обращение закрыто':'Ответить'}</span>
          <span class="support-reply-toggle-icon" aria-hidden="true">＋</span>
        </button>
        <div class="support-reply-composer" id="supportReplyComposer" hidden>
          <textarea id="supportReplyText" class="support-ticket-control support-ticket-message support-reply-text" maxlength="4000" placeholder="Напишите сообщение"></textarea>
          <div class="support-file-picker support-file-picker--reply">
            <div class="support-file-picker-head">
              <div><strong>Добавить к ответу</strong><small>Необязательно · до 3 файлов, до 2 МБ каждый</small></div>
              <button class="support-file-add" id="supportReplyFilesTrigger" type="button">＋ Файл</button>
            </div>
            <input id="supportReplyFiles" class="support-file-native" type="file" multiple accept="image/jpeg,image/png,image/webp,image/gif,application/pdf,text/plain">
            <div class="support-file-list" id="supportReplyFilesList"></div>
          </div>
          <button class="btn primary full support-reply-send" id="supportReplySend" type="button">Отправить</button>
        </div>
      </div>
    </div>`);

  const replyPicker=mountSupportFilePicker('supportReplyFiles','supportReplyFilesTrigger','supportReplyFilesList');
  renderSupportTicketThread(ticket);

  document.getElementById('supportReplyToggle')?.addEventListener('click',()=>{
    const toggle=document.getElementById('supportReplyToggle');
    const expanded=toggle?.getAttribute('aria-expanded')!=='true';
    setSupportReplyExpanded(expanded,{focus:expanded});
  });

  document.getElementById('supportReplySend')?.addEventListener('click',async()=>{
    const message=document.getElementById('supportReplyText')?.value.trim()||'';
    if(!message)return toast('Напишите сообщение.');
    const send=document.getElementById('supportReplySend');
    if(send)send.disabled=true;
    try{
      const attachments=await supportFilesPayload(replyPicker.getFiles());
      const updated=await api.supportReply(ticketNumber,message,attachments);
      supportTicketsCacheAt=0;
      renderSupportTicketThread(updated?.ticket);
      const input=document.getElementById('supportReplyText');
      if(input)input.value='';
      replyPicker.clear();
      setSupportReplyExpanded(false);
      toast('Сообщение отправлено.');
    }catch(error){
      toast(error.message||'Не удалось отправить сообщение.');
    }finally{
      if(send)send.disabled=false;
    }
  });
}

function setSupportReplyExpanded(expanded,{focus=false}={}){
  const detail=document.getElementById('supportThreadDetail');
  const toggle=document.getElementById('supportReplyToggle');
  const composer=document.getElementById('supportReplyComposer');
  if(!toggle||!composer)return;
  if(toggle.disabled)expanded=false;
  composer.hidden=!expanded;
  toggle.setAttribute('aria-expanded',expanded?'true':'false');
  detail?.classList.toggle('is-reply-open',expanded);
  const icon=toggle.querySelector('.support-reply-toggle-icon');
  if(icon)icon.textContent=expanded?'−':'＋';
  if(expanded&&focus){
    window.requestAnimationFrame(()=>{
      document.getElementById('supportReplyText')?.focus({preventScroll:true});
      composer.scrollIntoView({block:'nearest',behavior:'smooth'});
    });
  }
}

function supportTicketStatusLabel(ticket){
  const status=String(ticket?.status||'').trim().toLowerCase();
  const labels={
    open:'Открыто',
    in_progress:'В работе',
    waiting_user:'Ждёт ответа',
    waiting_for_user:'Ждёт ответа',
    resolved:'Решено',
    closed:'Закрыто',
  };
  return labels[status]||String(ticket?.status_label||ticket?.status||'');
}

function renderSupportTicketThread(ticket){
  if(!ticket)return;
  const meta=document.getElementById('supportTicketMeta');
  if(meta)meta.textContent=supportTicketStatusLabel(ticket);
  const thread=document.getElementById('supportTicketThread');
  if(!thread)return;
  thread.innerHTML=(ticket.messages||[]).map(message=>{
    const own=message.actor_type!=='admin';
    const files=(message.attachments||[]).map(file=>`
      <div class="support-thread-attachment-wrap">
        <button class="support-thread-attachment" type="button" data-support-attachment="${escapeHtml(file.attachment_id||'')}">
          <span aria-hidden="true">⌁</span>
          <span class="support-thread-attachment-name">${escapeHtml(file.file_name||'Вложение')}</span>
          <em>Открыть</em>
        </button>
        <div class="support-thread-attachment-preview" data-support-attachment-preview="${escapeHtml(file.attachment_id||'')}"></div>
      </div>`).join('');
    return `<article class="support-thread-message ${own?'is-user':'is-admin'}">
      <header><strong>${own?'Ваше сообщение':'Поддержка'}</strong><time>${escapeHtml(formatDate(message.created_at_utc||''))}</time></header>
      <div class="support-thread-body">${escapeHtml(message.body||'')}</div>
      ${files?`<div class="support-thread-attachments">${files}</div>`:''}
    </article>`;
  }).join('');
  thread.querySelectorAll('[data-support-attachment]').forEach(button=>button.addEventListener('click',()=>void openSupportAttachment(button.dataset.supportAttachment||'',button)));
  thread.scrollTop=thread.scrollHeight;
  const send=document.getElementById('supportReplySend');
  const reply=document.querySelector('.support-reply-composer');
  const toggle=document.getElementById('supportReplyToggle');
  const toggleLabel=toggle?.querySelector('[data-support-reply-toggle-label]');
  const closed=String(ticket.status||'').toLowerCase()==='closed';
  if(send)send.disabled=closed;
  if(reply)reply.classList.toggle('is-disabled',closed);
  if(toggle)toggle.disabled=closed;
  if(toggleLabel)toggleLabel.textContent=closed?'Обращение закрыто':'Ответить';
  if(closed)setSupportReplyExpanded(false);
}

function supportFileValidation(file){
  const allowed=['image/jpeg','image/png','image/webp','image/gif','application/pdf','text/plain'];
  if(!file)return 'Файл не выбран.';
  if(Number(file.size||0)>2000000)return 'Файл больше 2 МБ.';
  const mime=String(file.type||'').toLowerCase();
  if(!allowed.includes(mime))return 'Поддерживаются изображения, PDF и TXT.';
  return '';
}

function mountSupportFilePicker(inputId,triggerId,listId){
  const input=document.getElementById(inputId);
  const trigger=document.getElementById(triggerId);
  const list=document.getElementById(listId);
  let files=[];

  const key=file=>`${file.name}:${file.size}:${file.lastModified}`;
  const render=()=>{
    if(!list)return;
    list.innerHTML=files.map((file,index)=>`<div class="support-file-chip">
      <span><strong>${escapeHtml(file.name)}</strong><small>${Math.max(1,Math.ceil(file.size/1024))} КБ</small></span>
      <button type="button" data-support-file-remove="${index}" aria-label="Удалить файл">×</button>
    </div>`).join('');
    list.querySelectorAll('[data-support-file-remove]').forEach(button=>button.addEventListener('click',()=>{
      const index=Number(button.dataset.supportFileRemove);
      files=files.filter((_,itemIndex)=>itemIndex!==index);
      render();
    }));
    if(trigger){
      trigger.disabled=files.length>=3;
      trigger.textContent=files.length>=3?'Лимит 3 файла':(triggerId==='supportReplyFilesTrigger'?'＋ Файл':'＋ Добавить');
    }
  };

  trigger?.addEventListener('click',()=>input?.click());
  input?.addEventListener('change',()=>{
    const selected=Array.from(input.files||[]);
    input.value='';
    for(const file of selected){
      const validation=supportFileValidation(file);
      if(validation){
        toast(`${file.name}: ${validation}`);
        continue;
      }
      if(files.some(item=>key(item)===key(file)))continue;
      if(files.length>=3){
        toast('Можно приложить не более 3 файлов.');
        break;
      }
      files.push(file);
    }
    render();
  });
  render();

  return{
    getFiles:()=>files.slice(),
    clear:()=>{files=[];if(input)input.value='';render();}
  };
}

async function supportFilesPayload(source){
  const files=Array.isArray(source)?source:Array.from(source?.files||[]);
  if(files.length>3)throw new Error('Можно приложить не более 3 файлов.');
  return Promise.all(files.map(file=>new Promise((resolve,reject)=>{
    const validation=supportFileValidation(file);
    if(validation)return reject(new Error(`${file.name}: ${validation}`));
    const reader=new FileReader();
    reader.onerror=()=>reject(new Error(`${file.name}: не удалось прочитать файл.`));
    reader.onload=()=>resolve({
      file_name:file.name,
      mime_type:String(file.type||'').toLowerCase(),
      content_base64:String(reader.result||'').split(',').pop()||''
    });
    reader.readAsDataURL(file);
  })));
}

async function openSupportAttachment(attachmentId,button){
  if(!attachmentId)return;
  const preview=Array.from(document.querySelectorAll('[data-support-attachment-preview]'))
    .find(node=>node.dataset.supportAttachmentPreview===attachmentId)||null;
  if(preview?.dataset.loaded==='1'){
    const hidden=preview.hidden;
    preview.hidden=!hidden;
    const action=button?.querySelector('em');
    if(action)action.textContent=hidden?'Скрыть':'Открыть';
    return;
  }
  const label=button?.querySelector('em');
  if(label)label.textContent='Загрузка…';
  if(button)button.disabled=true;
  try{
    const result=await api.supportAttachment(attachmentId);
    const file=result?.attachment||{};
    const binary=atob(String(file.content_base64||''));
    const bytes=new Uint8Array(binary.length);
    for(let i=0;i<binary.length;i++)bytes[i]=binary.charCodeAt(i);
    const mime=String(file.mime_type||'application/octet-stream');
    const blob=new Blob([bytes],{type:mime});
    const url=URL.createObjectURL(blob);
    if(mime.startsWith('image/')&&preview){
      preview.innerHTML=`<img src="${url}" alt="${escapeHtml(file.file_name||'Вложение')}">`;
      preview.dataset.loaded='1';
      preview.hidden=false;
      if(label)label.textContent='Скрыть';
      window.setTimeout(()=>URL.revokeObjectURL(url),300000);
    }else{
      const anchor=document.createElement('a');
      anchor.href=url;
      anchor.download=String(file.file_name||'attachment');
      document.body.append(anchor);
      anchor.click();
      anchor.remove();
      window.setTimeout(()=>URL.revokeObjectURL(url),60000);
      if(label)label.textContent='Открыть';
    }
  }catch(error){
    toast(error.message||'Не удалось открыть вложение.');
    if(label)label.textContent='Открыть';
  }finally{
    if(button)button.disabled=false;
  }
}
