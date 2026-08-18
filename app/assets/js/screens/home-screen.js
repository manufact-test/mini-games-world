import { state } from '../state.js?v=27';
import { APP_CONFIG } from '../config.js?v=38';
import { api } from '../api/client.js?v=47';
import { toast } from '../components/toast.js?v=41';
import { openSheet, closeSheet } from '../components/sheet.js?v=68';
import { showScreen } from '../router.js?v=27';
import { haptic } from '../telegram/telegram-app.js?v=27';
import { renderBalances } from '../ui.js?v=90-wallet-15-3';
import { t, setExplicitLocale } from '@mgw/i18n';

window.__MGW_MATCH_HISTORY_UI_BUILD__ = 'mvp17-5-history-economy-live-owner-v3';
window.__MGW_HISTORY_MODAL_UX_BUILD__ = 'mvp17-5-ready-only-history-sheet-v2';

export function initHomeScreen(){
  document.addEventListener('click', event => {
    const target = event.target.closest('button, [role="button"]');
    if (!target) return;
    if (target.id === 'inviteFriend') return toast('Приглашения друзей появятся позже.');
    if (target.id === 'moreMenuOpen' || target.id === 'gameMenuOpen') return openMoreMenuSheet();
    if (target.id === 'profileOpen') return openProfileFromTop();
    if (target.matches('[data-back-home]')) return showScreen('home');
  });
  document.addEventListener('keydown', event => { if (event.key === 'Enter' && event.target?.id === 'profileOpen') openProfileFromTop(); });
  document.addEventListener('mgw:open-language-settings', openLanguageSettingsSheet);
}
function openProfileFromTop(){ document.dispatchEvent(new CustomEvent('mgw:open-profile')); }
export function setRoom(){ state.room='match'; state.selectedBet=APP_CONFIG.matchBet; renderRoomCard(); }
export function renderRoomCard(){}
export function renderStats(stats){
  const el=document.getElementById('activityGrid'); if(!el)return; const safe=stats||{};
  el.innerHTML=`<div class="activity-card"><div class="label">Игроков онлайн</div><div class="num">${safe.online_players ?? '—'}</div></div><div class="activity-card"><div class="label">Активных матчей</div><div class="num">${safe.active_games ?? '—'}</div></div>`;
}

function openMoreMenuSheet(){
  openSheet(`<div class="sheet-head"><div><h2>Меню</h2></div><button class="close" data-close-sheet type="button">×</button></div><div class="menu-list">
    ${menuItemMarkup('settingsBtn', '⚙️', t('settings.title'))}
    ${menuItemMarkup('rulesBtn', '📘', 'Правила')}
    ${menuItemMarkup('feedbackBtn', '💬', 'Обратная связь')}
    ${menuItemMarkup('ideaBtn', '💡', 'Предложить идею')}
    ${menuItemMarkup('supportBtn', '⚠️', 'Пожаловаться', 'danger')}
    ${menuItemMarkup('balanceHistoryBtn', '🧾', 'История баланса')}
    ${menuItemMarkup('matchHistoryBtn', '🎮', 'История матчей')}
  </div>`);
  document.getElementById('settingsBtn')?.addEventListener('click', openSettingsSheet);
  document.getElementById('rulesBtn')?.addEventListener('click', openRulesSheet);
  document.getElementById('feedbackBtn')?.addEventListener('click',()=>openSupportForm('feedback'));
  document.getElementById('ideaBtn')?.addEventListener('click',()=>openSupportForm('idea'));
  document.getElementById('supportBtn')?.addEventListener('click',()=>openSupportForm('complaint'));
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
  openSheet(`<div class="sheet-head"><div><h2>Правила обычных матчей</h2></div><button class="close" data-close-sheet type="button">×</button></div><div class="rules-content"><p><strong>Обычные матчи</strong> используют единый баланс Mini Games World.</p><p>Стоимость участия в обычном матче — <strong>${APP_CONFIG.matchBet} коинов</strong>.</p><p>Матч начинается после подбора соперника с подходящими условиями игры.</p><p>При победе награда начисляется по действующим серверным правилам экономики. При ничьей стоимость участия возвращается обоим игрокам.</p><p>Все списания, начисления и результаты сохраняются в истории баланса и матчей.</p><p>Условия бесплатного еженедельного начисления всегда доступны по кнопке <strong>«Еженедельный бонус»</strong> в карточке баланса.</p><p>Если вы заметили ошибку в балансе или результате матча, отправьте обращение через меню помощи.</p></div><button class="btn primary full sheet-bottom-btn" data-close-sheet type="button">Понятно</button>`);
}

async function openBalanceHistorySheet(){
  setHistoryButtonsDisabled(true);
  try { const result=await api.history(); if(result.user){state.user=result.user;renderBalances(state.user);} renderHistorySheet(result.history||{},result.topups||[]); }
  catch(error){ openSheet(`<div class="sheet-head"><div><h2>История баланса</h2></div><button class="close" data-close-sheet type="button">×</button></div><div class="small-note">${escapeHtml(error.message)}</div><button class="btn ghost full" data-close-sheet type="button">Понятно</button>`); }
  finally { setHistoryButtonsDisabled(false); }
}
async function openMatchHistorySheet(){
  setHistoryButtonsDisabled(true);
  try { const result=await api.history(); renderMatchHistorySheet(result.history?.matches||[]); }
  catch(error){ openSheet(`<div class="sheet-head"><div><h2>История матчей</h2></div><button class="close" data-close-sheet type="button">×</button></div><div class="small-note">${escapeHtml(error.message)}</div><button class="btn ghost full" data-close-sheet type="button">Понятно</button>`); }
  finally { setHistoryButtonsDisabled(false); }
}
function setHistoryButtonsDisabled(disabled){
  ['balanceHistoryBtn','matchHistoryBtn'].forEach(id=>{const button=document.getElementById(id);if(button)button.disabled=disabled;});
}
function renderHistorySheet(history,topups=[]){
  const operations=history.operations||[];
  const topupHtml=topups.length?topups.slice(0,20).map(item=>{const room=item.room==='match'?'Match':'Gold';const status=topupStatusText(item.status);const tone=topupTone(item.status);const price=Number(item.price||item.amount_rub||0).toLocaleString('ru-RU');const coins=Number(item.coins||0).toLocaleString('ru-RU');const reason=item.status==='rejected'&&item.reject_reason?`<span>Причина: ${escapeHtml(item.reject_reason)}</span>`:'';return `<div class="history-item"><div><strong>${escapeHtml(status)}</strong><span>${escapeHtml(room)} · ${price} ₽ → ${coins} коинов</span>${reason}<em>#${escapeHtml(item.short_id||'')} · ${escapeHtml(formatDate(item.created_at))}</em></div><b class="${tone}">${escapeHtml(topupAmountLabel(item))}</b></div>`;}).join(''):`<div class="small-note">Заявок на пополнение пока нет.</div>`;
  const operationHtml=operations.length?operations.slice(0,20).map(item=>`<div class="history-item"><div><strong>${escapeHtml(item.title||'Операция')}</strong><span>${escapeHtml(item.description||'')}</span><em>${escapeHtml(formatDate(item.created_at))}</em></div><b class="${item.tone==='pos'?'pos':(item.tone==='neg'?'neg':'')}">${escapeHtml(item.amount_label||'0 коинов')}</b></div>`).join(''):`<div class="small-note">Операций пока нет.</div>`;
  openSheet(`<div class="sheet-head"><div><h2>История баланса</h2></div><button class="close" data-close-sheet type="button">×</button></div><div class="history-tabs" role="tablist"><button class="history-tab active" data-history-tab="operations" type="button">Операции</button><button class="history-tab" data-history-tab="topups" type="button">Пополнения</button></div><div class="history-scroll"><div class="history-tab-panel active" data-history-panel="operations"><div class="history-section"><h3>Операции баланса</h3><div class="history-list">${operationHtml}</div></div></div><div class="history-tab-panel" data-history-panel="topups"><div class="history-section"><h3>Пополнения</h3><div class="history-list">${topupHtml}</div></div></div></div><button class="btn ghost full" data-close-sheet type="button">Понятно</button>`); bindHistoryTabs();
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
function bindHistoryTabs(){const tabs=document.querySelectorAll('[data-history-tab]');const panels=document.querySelectorAll('[data-history-panel]');tabs.forEach(tab=>tab.addEventListener('click',()=>{const target=tab.dataset.historyTab;tabs.forEach(item=>item.classList.toggle('active',item===tab));panels.forEach(panel=>panel.classList.toggle('active',panel.dataset.historyPanel===target));}));}
function topupStatusText(status){if(status==='paid')return'Пополнение начислено';if(status==='rejected')return'Заявка отклонена';if(status==='cancelled')return'Заявка отменена';if(status==='pending')return'Ожидает оплаты';return'Заявка на пополнение';}
function topupTone(status){if(status==='paid')return'pos';if(status==='rejected'||status==='cancelled')return'neg';return'';}
function topupAmountLabel(item){if(item.status==='paid')return'+'+Number(item.coins||0).toLocaleString('ru-RU')+' коинов';if(item.status==='rejected'||item.status==='cancelled')return'0 коинов';return'ожидает';}
function formatDate(value){if(!value)return'';const date=new Date(value);if(Number.isNaN(date.getTime()))return String(value);return date.toLocaleString('ru-RU',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});}
function escapeHtml(value){return String(value??'').replace(/[&<>'"]/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));}
function openSupportForm(type){
  openSheet(`<div class="sheet-head"><div><h2>${type==='idea'?'Предложить идею':(type==='feedback'?'Обратная связь':'Обращение в поддержку')}</h2><p>Опишите ситуацию, мы сохраним обращение.</p></div><button class="close" data-close-sheet type="button">×</button></div><textarea id="supportText" class="form-textarea" placeholder="Напишите сообщение"></textarea><button class="btn primary full" id="sendSupport" type="button">Отправить</button>`);
  document.getElementById('sendSupport')?.addEventListener('click',async()=>{const message=document.getElementById('supportText').value.trim();if(!message)return toast('Напишите сообщение.');try{await api.support(type,message);closeSheet();toast('Сообщение сохранено.');}catch(error){toast(error.message);}});
}
