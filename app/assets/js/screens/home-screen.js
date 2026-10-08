import { state } from '../state.js?v=27';
import { APP_CONFIG } from '../config.js?v=38';
import { api } from '../api/client.js?v=47';
import { toast } from '../components/toast.js?v=41';
import { openSheet, closeSheet } from '../components/sheet.js?v=68';
import { showScreen } from '../router.js?v=27';
import { haptic } from '../telegram/telegram-app.js?v=27';
import { renderBalances } from '../ui.js?v=90-wallet-15-3';
import { t, getI18n, previewAccountLocale, formatDate as formatLocalizedDate, formatDateTime as formatLocalizedDateTime } from '@mgw/i18n';
import { accountLinkProfileMarkup } from '../profile/mgw-account-link-ui.js?v=3';

const HISTORY_CACHE_MAX_AGE_MS = 15000;
let historyCache = null;
let historyCacheAt = 0;
let historyCachePromise = null;

const SUPPORT_TICKETS_CACHE_MAX_AGE_MS = 15000;
const PLAYER_REPORT_REASON_CODES = Object.freeze([
  'nickname','avatar','spam','cheating','stalling','other',
]);
function playerReportReasons(){
  return PLAYER_REPORT_REASON_CODES.map(value => [value, t(`home.report.reasons.${value}`)]);
}
let supportTicketsCache = null;
let supportTicketsCacheAt = 0;
let supportTicketsCachePromise = null;

window.__MGW_MATCH_HISTORY_UI_BUILD__ = 'mvp17-5-history-economy-live-owner-v3';
window.__MGW_HISTORY_MODAL_UX_BUILD__ = 'mvp17-5-prefetched-history-v3';

export function initHomeScreen(){
  document.addEventListener('mgw:locale-changed', () => {
    if (state.stats && typeof state.stats === 'object') renderStats(state.stats);
  });
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
  el.innerHTML=`<div class="activity-card"><div class="label">${escapeHtml(t('home.stats.online_players'))}</div><div class="num">${safe.online_players ?? '—'}</div></div><div class="activity-card"><div class="label">${escapeHtml(t('home.stats.active_matches'))}</div><div class="num">${safe.active_games ?? '—'}</div></div>`;
}

function openMoreMenuSheet(){
  void refreshHistoryCache().catch(() => {});
  void refreshSupportTicketsCache().catch(() => {});
  openSheet(`<div class="sheet-head"><div><h2>${escapeHtml(t('home.menu.title'))}</h2></div><button class="close" data-close-sheet type="button">×</button></div><div class="menu-list">
    ${menuItemMarkup('settingsBtn', '⚙️', t('settings.title'))}
    ${menuItemMarkup('rulesBtn', '📘', t('rules.open'))}
    ${menuItemMarkup('feedbackBtn', '💬', t('home.menu.feedback'))}
    ${menuItemMarkup('ideaBtn', '💡', t('home.menu.idea'))}
    ${menuItemMarkup('supportBtn', '⚠️', t('home.menu.report'), 'danger')}
    ${menuItemMarkup('supportTicketsBtn', '🎫', t('home.menu.tickets'), 'support-attention')}
    ${menuItemMarkup('balanceHistoryBtn', '🧾', t('home.menu.balance_history'))}
    ${menuItemMarkup('matchHistoryBtn', '🎮', t('home.menu.match_history'))}
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

function currentInterfaceLocale(){
  try { return getI18n().locale === 'en' ? 'en' : 'ru'; } catch (error) { return 'ru'; }
}

function interfaceLanguageLabel(locale = currentInterfaceLocale()){
  return t(locale === 'en' ? 'settings.language_en' : 'settings.language_ru');
}

function settingsProviderName(provider){
  try { return t(`profile.providers.${provider}`); } catch (error) { return provider || t('profile.provider_unknown'); }
}

function settingsIdentityRow(identity){
  const provider = String(identity?.provider || '').trim().toLowerCase();
  const linkedAt = identity?.linked_at || null;
  return `<div class="profile-v2-linked-row"><span class="profile-v2-provider-mark" aria-hidden="true">${escapeHtml(provider.slice(0,1).toUpperCase() || '•')}</span><span><strong>${escapeHtml(settingsProviderName(provider))}</strong><small>${escapeHtml(linkedAt ? t('profile.linked_since',{ date:formatLocalizedDate(linkedAt) }) : t('profile.linked'))}</small></span><b>${escapeHtml(t('profile.connected'))}</b></div>`;
}

function settingsAccountMarkup(){
  const profile = state.mgwProfile && typeof state.mgwProfile === 'object' ? state.mgwProfile : {};
  const identities = Array.isArray(profile.identities) ? profile.identities : [];
  return `<div class="profile-v2-account-card">
    <button class="profile-v2-setting-row profile-v2-setting-button" id="languageSettingsBtn" type="button"><span><strong>${escapeHtml(t('profile.language'))}</strong><small>${escapeHtml(t('profile.language_note'))}</small></span><b>${escapeHtml(interfaceLanguageLabel())}</b></button>
    <div class="profile-v2-account-divider"></div>
    <button class="profile-v2-setting-row profile-v2-setting-button" type="button" data-open-moderation-center><span><strong>${escapeHtml(t('profile.moderation.title'))}</strong><small>${escapeHtml(t('profile.moderation.settings_note'))}</small></span><b>${escapeHtml(t('profile.moderation.open'))}</b></button>
    <div class="profile-v2-account-divider"></div>
    ${accountLinkProfileMarkup(
      state.profileAuth || { provider:state.user?.mgw_identity_provider || '' },
      identities
    )}
    <div class="profile-v2-linked-head"><strong>${escapeHtml(t('profile.linked_accounts'))}</strong><small>${escapeHtml(t('profile.linked_accounts_note'))}</small></div>
    <div class="profile-v2-linked-list">${identities.length ? identities.map(settingsIdentityRow).join('') : `<div class="profile-v2-empty">${escapeHtml(t('profile.linked_empty'))}</div>`}</div>
  </div>`;
}

function openSettingsSheet(){
  openSheet(`<div class="sheet-head"><div><h2>${escapeHtml(t('settings.title'))}</h2></div><button class="close" data-close-sheet type="button">×</button></div>${settingsAccountMarkup()}`);
  document.getElementById('languageSettingsBtn')?.addEventListener('click', openLanguageSettingsSheet);
}

let languageChangeIntent = 0;

function openLanguageSettingsSheet(){
  const currentLocale = currentInterfaceLocale();
  const option = (id, locale, label) => `<button class="btn menu-item${currentLocale === locale ? ' active' : ''}" id="${id}" type="button">${escapeHtml(label)}<span>${currentLocale === locale ? '✓' : ''}</span></button>`;
  openSheet(`<div class="sheet-head"><div><h2>${escapeHtml(t('settings.language'))}</h2><p>${escapeHtml(t('settings.language_note'))}</p></div><button class="close" data-close-sheet type="button">×</button></div><div class="menu-list">${option('languageRuBtn','ru',t('settings.language_ru'))}${option('languageEnBtn','en',t('settings.language_en'))}</div>`);

  const activate = locale => {
    if (locale === currentInterfaceLocale() && state.mgwProfile?.preferred_locale === locale) {
      closeSheet();
      return;
    }
    const previousLocale = currentInterfaceLocale();
    const requestIntent = ++languageChangeIntent;

    // Close the panel and switch the visible language immediately. Saving the
    // authenticated account takes place in the background, not on the tap path.
    closeSheet();
    previewAccountLocale(locale);
    void api.saveAccountLocale(locale).catch(error => {
      // An older failed request must not undo a newer language selection.
      if (requestIntent !== languageChangeIntent) return;
      previewAccountLocale(state.mgwProfile?.preferred_locale || previousLocale);
      toast(error?.message || t('network.request_failed'));
    });
  };
  document.getElementById('languageRuBtn')?.addEventListener('click', () => activate('ru'));
  document.getElementById('languageEnBtn')?.addEventListener('click', () => activate('en'));
}

function localizedStrongHtml(key, values = {}){
  const params = {};
  const replacements = [];
  let index = 0;
  Object.entries(values).forEach(([name, value]) => {
    const token = `__MGW_STRONG_${index++}_${name}__`;
    params[name] = token;
    replacements.push([token, `<strong>${escapeHtml(value)}</strong>`]);
  });
  let html = escapeHtml(t(key, params));
  replacements.forEach(([token, markup]) => { html = html.split(token).join(markup); });
  return html;
}
function quotedLabel(value){ return `«${String(value ?? '')}»`; }

function openRulesSheet(){
  const economy = APP_CONFIG.matchEconomy || {};
  const entry = Number(economy.entry_cost ?? APP_CONFIG.matchBet);
  const winnerReward = Number(economy.winner_reward);
  const commission = Number(economy.system_sink);
  const drawRefund = Number(economy.draw_refund);
  const pot = Number.isFinite(entry) ? entry * 2 : 0;
  const winnerNet = Number.isFinite(winnerReward) && Number.isFinite(entry) ? winnerReward - entry : 0;

  const amount = value => Number.isFinite(Number(value))
    ? t('home.rules_guide.coin_amount', { count:Math.trunc(Number(value)) })
    : t('home.rules_guide.current_amount');

  const friends = quotedLabel(t('home.rules_guide.friends'));
  const more = quotedLabel(t('home.rules_guide.more'));
  const invite = quotedLabel(t('home.rules_guide.invite'));
  const rules = quotedLabel(t('rules.open'));
  const balanceHistory = quotedLabel(t('home.menu.balance_history'));
  const matchHistory = quotedLabel(t('home.menu.match_history'));
  const weeklyBonus = quotedLabel(t('home.rules_guide.weekly_bonus'));
  const moreFeedback = `${more} → ${quotedLabel(t('home.menu.feedback'))}`;
  const report = quotedLabel(t('home.menu.report'));

  openSheet(`
    <div class="sheet-head">
      <div>
        <h2>${escapeHtml(t('home.rules_guide.title'))}</h2>
        <p>${escapeHtml(t('home.rules_guide.subtitle'))}</p>
      </div>
      <button class="close" data-close-sheet type="button" aria-label="${escapeHtml(t('common.close'))}">×</button>
    </div>

    <div class="rules-content rules-guide">
      <section class="rules-guide-section">
        <h3>${escapeHtml(t('home.rules_guide.start_title'))}</h3>
        <p>${escapeHtml(t('home.rules_guide.start_text_1'))}</p>
        <p>${escapeHtml(t('home.rules_guide.start_text_2'))}</p>
        <p>${localizedStrongHtml('home.rules_guide.start_text_3', { friends, more, invite })}</p>
      </section>

      <section class="rules-guide-section rules-guide-economy">
        <h3>${escapeHtml(t('home.rules_guide.cost_title'))}</h3>
        <p>${localizedStrongHtml('home.rules_guide.cost_text', { entry:amount(entry), pot:amount(pot) })}</p>
        <div class="rules-guide-numbers" role="list" aria-label="${escapeHtml(t('home.rules_guide.calculation_label'))}">
          <div role="listitem"><span>${escapeHtml(t('home.rules_guide.entry_label'))}</span><strong>${escapeHtml(amount(entry))}</strong></div>
          <div role="listitem"><span>${escapeHtml(t('home.rules_guide.pot_label'))}</span><strong>${escapeHtml(amount(pot))}</strong></div>
          <div role="listitem"><span>${escapeHtml(t('home.rules_guide.winner_label'))}</span><strong>${escapeHtml(amount(winnerReward))}</strong></div>
          <div role="listitem"><span>${escapeHtml(t('home.rules_guide.commission_label'))}</span><strong>${escapeHtml(amount(commission))}</strong></div>
        </div>
        <p>${localizedStrongHtml('home.rules_guide.cost_example', { winner_reward:amount(winnerReward), winner_net:`+${amount(winnerNet)}` })}</p>
      </section>

      <section class="rules-guide-section">
        <h3>${escapeHtml(t('home.rules_guide.result_title'))}</h3>
        <p>${localizedStrongHtml('home.rules_guide.win_text', { label:t('home.rules_guide.win_label'), winner_reward:amount(winnerReward), commission:amount(commission) })}</p>
        <p>${localizedStrongHtml('home.rules_guide.loss_text', { label:t('home.rules_guide.loss_label') })}</p>
        <p>${localizedStrongHtml('home.rules_guide.draw_text', { label:t('home.rules_guide.draw_label'), draw_refund:amount(drawRefund) })}</p>
      </section>

      <section class="rules-guide-section">
        <h3>${escapeHtml(t('home.rules_guide.leave_title'))}</h3>
        <p>${escapeHtml(t('home.rules_guide.leave_text'))}</p>
      </section>

      <section class="rules-guide-section">
        <h3>${escapeHtml(t('home.rules_guide.game_rules_title'))}</h3>
        <p>${localizedStrongHtml('home.rules_guide.game_rules_text', { rules })}</p>
      </section>

      <section class="rules-guide-section">
        <h3>${escapeHtml(t('home.rules_guide.history_title'))}</h3>
        <p>${localizedStrongHtml('home.rules_guide.history_text', { more, balance_history:balanceHistory, match_history:matchHistory })}</p>
      </section>

      <section class="rules-guide-section">
        <h3>${escapeHtml(t('home.rules_guide.bonus_title'))}</h3>
        <p>${localizedStrongHtml('home.rules_guide.bonus_text', { weekly_bonus:weeklyBonus })}</p>
        <p>${escapeHtml(t('home.rules_guide.tournaments_text'))}</p>
      </section>

      <section class="rules-guide-section">
        <h3>${escapeHtml(t('home.rules_guide.problems_title'))}</h3>
        <p>${localizedStrongHtml('home.rules_guide.problems_text', { more_feedback:moreFeedback, report })}</p>
      </section>
    </div>

    <button class="btn primary full sheet-bottom-btn" data-close-sheet type="button">${escapeHtml(t('rules.understood'))}</button>
  `);
}

async function openBalanceHistorySheet(){
  try {
    const result=historyCache || await refreshHistoryCache({ force:true });
    if(result.user){state.user=result.user;renderBalances(state.user);}
    renderHistorySheet(result.history||{});
    void refreshHistoryCache({ force:isHistoryCacheStale() }).catch(() => {});
  } catch(error){ openSheet(`<div class="sheet-head"><div><h2>${escapeHtml(t('home.history.balance_title'))}</h2></div><button class="close" data-close-sheet type="button">×</button></div><div class="small-note">${escapeHtml(error.message)}</div><button class="btn ghost full" data-close-sheet type="button">${escapeHtml(t('rules.understood'))}</button>`); }
}
async function openMatchHistorySheet(){
  try {
    const result=historyCache || await refreshHistoryCache({ force:true });
    renderMatchHistorySheet(result.history?.matches||[]);
    void refreshHistoryCache({ force:isHistoryCacheStale() }).catch(() => {});
  } catch(error){ openSheet(`<div class="sheet-head"><div><h2>${escapeHtml(t('home.history.match_title'))}</h2></div><button class="close" data-close-sheet type="button">×</button></div><div class="small-note">${escapeHtml(error.message)}</div><button class="btn ghost full" data-close-sheet type="button">${escapeHtml(t('rules.understood'))}</button>`); }
}
function isHistoryCacheStale(){return !historyCache || (Date.now()-historyCacheAt)>=HISTORY_CACHE_MAX_AGE_MS;}
function refreshHistoryCache({force=false}={}){
  if(!force&&!isHistoryCacheStale())return Promise.resolve(historyCache);
  if(historyCachePromise)return historyCachePromise;
  historyCachePromise=api.history()
    .then(result=>{historyCache=result;historyCacheAt=Date.now();return result;})
    .finally(()=>{historyCachePromise=null;});
  return historyCachePromise;
}
function renderHistorySheet(history){
  const operations=history.operations||[];
  const operationHtml=operations.length?operations.slice(0,20).map(item=>`<div class="history-item"><div><strong>${escapeHtml(item.title||t('home.history.operation'))}</strong><span>${escapeHtml(item.description||'')}</span><em>${escapeHtml(formatDate(item.created_at))}</em></div><b class="${item.tone==='pos'?'pos':(item.tone==='neg'?'neg':'')}">${escapeHtml(item.amount_label||t('home.history.coin_amount',{count:0}))}</b></div>`).join(''):`<div class="small-note">${escapeHtml(t('home.history.operations_empty'))}</div>`;
  openSheet(`<div class="sheet-head"><div><h2>${escapeHtml(t('home.history.balance_title'))}</h2></div><button class="close" data-close-sheet type="button">×</button></div><div class="history-scroll"><div class="history-section"><h3>${escapeHtml(t('home.history.operations_title'))}</h3><div class="history-list">${operationHtml}</div></div></div><button class="btn ghost full" data-close-sheet type="button">${escapeHtml(t('rules.understood'))}</button>`);
}
function renderMatchHistorySheet(matches=[]){
  const matchHtml=matches.length?matches.slice(0,20).map(item=>{
    const result=item.result||t('home.history.match');
    const tone=item.tone==='pos'?'pos':(item.tone==='neg'?'neg':'');
    const game=item.game_title||t('home.history.match');
    const columns=Number(item.board_columns||item.board_size||0);
    const rows=Number(item.board_rows||item.board_size||0);
    const board=columns>0&&rows>0?`${columns}×${rows}`:'';
    const opponent=item.opponent||t('home.history.opponent');
    const economy=item.economy&&typeof item.economy==='object'?item.economy:null;
    const date=formatDate(item.finished_at||item.created_at);
    const delta=economy?matchDelta(economy.ledger_delta):'';
    return `<div class="history-item match-history-item"><div><strong>${escapeHtml(result)}</strong><span>${escapeHtml([game,board].filter(Boolean).join(' · '))}</span><span>${escapeHtml(t('home.history.opponent_line',{opponent}))}</span><em>${escapeHtml(date)}</em></div><b class="${tone}">${escapeHtml(delta)}</b></div>`;
  }).join(''):`<div class="small-note">${escapeHtml(t('home.history.matches_empty'))}</div>`;
  openSheet(`<div class="sheet-head"><div><h2>${escapeHtml(t('home.history.match_title'))}</h2></div><button class="close" data-close-sheet type="button">×</button></div><div class="history-scroll"><div class="history-section"><h3>${escapeHtml(t('home.history.latest_games'))}</h3><div class="history-list">${matchHtml}</div></div></div><button class="btn ghost full" data-close-sheet type="button">${escapeHtml(t('rules.understood'))}</button>`);
}
function matchDelta(value){if(value===null||value===undefined||!Number.isFinite(Number(value)))return'—';const normalized=Math.trunc(Number(value));return `${normalized>0?'+':''}${t('home.history.coin_amount',{count:normalized})}`;}
function formatDate(value){if(!value)return'';const date=new Date(value);if(Number.isNaN(date.getTime()))return String(value);return formatLocalizedDateTime(date,'short',{year:undefined});}
function escapeHtml(value){return String(value??'').replace(/[&<>'"]/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));}
function openSupportForm(type){
  const defaults={feedback:'feedback',idea:'idea'};
  const category=defaults[type]||'other';
  const titles={
    feedback:t('home.menu.feedback'),
    idea:t('home.menu.idea'),
  };
  const messagePlaceholders={
    feedback:t('home.support.message_feedback'),
    idea:t('home.support.message_idea'),
  };
  const title=titles[type]||t('home.support.title_default');
  const messagePlaceholder=messagePlaceholders[type]||t('home.support.message_default');

  openSheet(`<div class="sheet-head support-ticket-create-head"><div><h2>${escapeHtml(title)}</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="support-ticket-create">
      <div class="support-ticket-create-scroll">
        <label class="support-ticket-field">
          <span class="support-ticket-label">${escapeHtml(t('home.support.category_label'))}</span>
          <span class="support-ticket-select-wrap">
            <select id="supportCategory" class="support-ticket-control support-ticket-select">
              <option value="feedback">${escapeHtml(t('home.support.category_feedback'))}</option>
              <option value="idea">${escapeHtml(t('home.support.category_idea'))}</option>
              <option value="technical">${escapeHtml(t('home.support.category_technical'))}</option>
              <option value="payment">${escapeHtml(t('home.support.category_payment'))}</option>
              <option value="game">${escapeHtml(t('home.support.category_game'))}</option>
              <option value="tournament">${escapeHtml(t('home.support.category_tournament'))}</option>
              <option value="account">${escapeHtml(t('home.support.category_account'))}</option>
              <option value="other">${escapeHtml(t('home.support.category_other'))}</option>
            </select>
          </span>
        </label>

        <label class="support-ticket-field">
          <span class="support-ticket-label">${escapeHtml(t('home.support.subject_label'))}</span>
          <input id="supportSubject" class="support-ticket-control" maxlength="160" placeholder="${escapeHtml(t('home.support.subject_placeholder'))}">
        </label>

        <label class="support-ticket-field">
          <span class="support-ticket-label">${escapeHtml(t('home.support.priority_label'))}</span>
          <span class="support-ticket-select-wrap">
            <select id="supportPriority" class="support-ticket-control support-ticket-select">
              <option value="normal">${escapeHtml(t('home.support.priority_normal'))}</option>
              <option value="high">${escapeHtml(t('home.support.priority_high'))}</option>
              <option value="critical">${escapeHtml(t('home.support.priority_critical'))}</option>
              <option value="low">${escapeHtml(t('home.support.priority_low'))}</option>
            </select>
          </span>
        </label>

        <label class="support-ticket-field support-ticket-message-field">
          <span class="support-ticket-label">${escapeHtml(t('home.support.message_label'))}</span>
          <textarea id="supportText" class="support-ticket-control support-ticket-message" maxlength="4000" placeholder="${escapeHtml(messagePlaceholder)}"></textarea>
        </label>

        <div class="support-file-picker">
          <div class="support-file-picker-head">
            <div><strong>${escapeHtml(t('home.support.attachments'))}</strong><small>${escapeHtml(t('home.support.attachments_note'))}</small></div>
            <button class="support-file-add" id="supportFilesTrigger" type="button">${escapeHtml(t('home.support.add_file'))}</button>
          </div>
          <input id="supportFiles" class="support-file-native" type="file" multiple accept="image/jpeg,image/png,image/webp,image/gif,application/pdf,text/plain">
          <div class="support-file-list" id="supportFilesList"></div>
        </div>
      </div>
      <button class="btn primary full support-ticket-submit" id="sendSupport" type="button">${escapeHtml(t('home.support.create'))}</button>
    </div>`);

  const categoryNode=document.getElementById('supportCategory');
  if(categoryNode) categoryNode.value=category;
  const supportFilePicker=mountSupportFilePicker('supportFiles','supportFilesTrigger','supportFilesList');

  document.getElementById('sendSupport')?.addEventListener('click',async()=>{
    const message=document.getElementById('supportText')?.value.trim()||'';
    if(!message)return toast(t('home.support.message_required'));
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
      toast(number?t('home.support.created_case',{number}):t('home.support.created'));
    }catch(error){
      toast(error.message||t('home.support.create_error'));
    }finally{
      if(button)button.disabled=false;
    }
  });
}

function openPlayerReportSheet(){
  let selectedPlayer=null;
  let selectedReason='';

  openSheet(`<div class="sheet-head player-report-head"><div><h2>${escapeHtml(t('home.report.title'))}</h2><p>${escapeHtml(t('home.report.subtitle'))}</p></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="player-report-create">
      <section class="player-report-step">
        <div class="player-report-step-title"><b>1</b><span><strong>${escapeHtml(t('home.report.target_question'))}</strong><small>${escapeHtml(t('home.report.search_note'))}</small></span></div>
        <div class="player-report-search">
          <input id="playerReportSearch" class="form-input" maxlength="40" autocomplete="off" placeholder="${escapeHtml(t('home.report.search_placeholder'))}">
          <button class="btn primary" id="playerReportSearchBtn" type="button">${escapeHtml(t('home.report.search_action'))}</button>
        </div>
        <div class="player-report-search-status" id="playerReportSearchStatus">${escapeHtml(t('home.report.search_hint'))}</div>
        <div class="player-report-results" id="playerReportResults"></div>
        <div class="player-report-selected" id="playerReportSelected" hidden></div>
      </section>

      <section class="player-report-step" id="playerReportReasonStep" hidden>
        <div class="player-report-step-title"><b>2</b><span><strong>${escapeHtml(t('home.report.reason_title'))}</strong><small>${escapeHtml(t('home.report.choose_one'))}</small></span></div>
        <div class="player-report-reasons" id="playerReportReasons">
          ${playerReportReasons().map(([value,label])=>`<button type="button" data-player-report-reason="${escapeHtml(value)}" aria-pressed="false">${escapeHtml(label)}</button>`).join('')}
        </div>
        <label class="player-report-comment">
          <span>${escapeHtml(t('home.report.comment'))} <small>${escapeHtml(t('home.report.optional'))}</small></span>
          <textarea id="playerReportComment" class="form-input" maxlength="800" placeholder="${escapeHtml(t('home.report.comment_placeholder'))}"></textarea>
        </label>
        <button class="btn primary full player-report-submit" id="playerReportSend" type="button" disabled>${escapeHtml(t('home.report.send'))}</button>
      </section>

      <details class="player-report-history">
        <summary><span>${escapeHtml(t('home.report.history_title'))}</span><b id="playerReportHistoryCount">…</b></summary>
        <div class="player-report-history-list" id="playerReportHistoryList">
          <div class="player-report-history-empty">${escapeHtml(t('home.report.history_loading'))}</div>
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
      selected.innerHTML=`<span><strong>${escapeHtml(player?.nickname||t('home.report.player_fallback'))}</strong><small>${escapeHtml(player?.public_mgw_id||'')}</small></span><button type="button" id="playerReportChangeTarget">${escapeHtml(t('home.report.change'))}</button>`;
    }
    if(results)results.innerHTML='';
    if(searchStatus)searchStatus.textContent=t('home.report.selected');
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
      if(searchStatus)searchStatus.textContent=t('home.report.not_found');
      return;
    }
    if(searchStatus)searchStatus.textContent=t('home.report.found',{count:players.length});
    results.innerHTML=players.map((player,index)=>`<button class="player-report-result" type="button" data-player-report-target="${index}">
      <span><strong>${escapeHtml(player?.nickname||t('home.report.player_fallback'))}</strong><small>${escapeHtml(player?.public_mgw_id||'')}</small></span><em>${escapeHtml(t('home.report.choose'))}</em>
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
      if(searchStatus)searchStatus.textContent=t('home.report.enter_query');
      searchInput?.focus();
      return;
    }
    if(!looksLikeMgwId&&Array.from(nicknameQuery).length<2){
      if(searchStatus)searchStatus.textContent=t('home.report.nickname_min');
      searchInput?.focus();
      return;
    }
    if(searchButton)searchButton.disabled=true;
    if(results)results.innerHTML='';
    if(searchStatus)searchStatus.textContent=t('home.report.searching');
    try{
      const response=await api.friends({action:'report_lookup',query});
      const players=Array.isArray(response?.result?.players)?response.result.players.filter(player=>player&&typeof player==='object'):[];
      renderPlayers(players);
    }catch(error){
      if(searchStatus)searchStatus.textContent=error?.message||t('home.report.search_error');
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
    open:t('home.report.status_open'),
    reviewing:t('home.report.status_reviewing'),
    closed:t('home.report.status_closed'),
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
      historyList.innerHTML=`<div class="player-report-history-empty">${escapeHtml(t('home.report.history_empty'))}</div>`;
      return;
    }
    historyList.innerHTML=items.map(report=>`<article class="player-report-history-item">
      <div class="player-report-history-top">
        <span><strong>${escapeHtml(report?.target_nickname||t('home.report.player_fallback'))}</strong><small>${escapeHtml(report?.target_public_mgw_id||'')}</small></span>
        <b class="${escapeHtml(reportStatusTone(report?.status))}">${escapeHtml(reportStatusLabel(report?.status))}</b>
      </div>
      <div class="player-report-history-reason">${escapeHtml(report?.reason_label||report?.reason||t('home.report.complaint_fallback'))}</div>
      <small class="player-report-history-meta">${escapeHtml(formatDate(report?.resolved_at||report?.reviewed_at||report?.created_at||''))}${report?.status==='closed'?escapeHtml(t('home.report.review_complete')):''}</small>
    </article>`).join('');
  };

  const loadReportHistory=async()=>{
    try{
      const response=await api.friends({action:'report_history'});
      renderReportHistory(response?.result?.reports||[]);
    }catch(error){
      if(historyCount)historyCount.textContent='!';
      if(historyList)historyList.innerHTML=`<div class="player-report-history-empty">${escapeHtml(error?.message||t('home.report.history_error'))}</div>`;
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
      toast(caseId?t('home.report.sent_case',{case_id:caseId}):t('home.report.sent'));
    }catch(error){
      toast(error?.message||t('home.report.send_error'));
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
    toast(error.message||t('home.support.tickets_load_error'));
  }
}

function renderSupportTicketsSheet(tickets){
  const rows=tickets.map(ticket=>{
    const status=escapeHtml(supportTicketStatusLabel(ticket));
    const priority=escapeHtml(ticket.priority_label||ticket.priority||'');
    const category=escapeHtml(ticket.category_label||'');
    const date=escapeHtml(formatDate(ticket.updated_at||''));
    return `<button class="support-ticket-row" type="button" data-support-ticket="${escapeHtml(ticket.ticket_number||'')}">
      <span class="support-ticket-row-top"><strong>${escapeHtml(ticket.ticket_number||t('home.support.ticket_fallback'))}</strong><em>${status}</em></span>
      <span class="support-ticket-row-subject">${escapeHtml(ticket.subject||ticket.category_label||'')}</span>
      <span class="support-ticket-row-meta"><span>${category}</span><span>${priority}</span><time>${date}</time></span>
    </button>`;
  }).join('');

  openSheet(`<div class="sheet-head support-hub-head"><div><h2>${escapeHtml(t('home.menu.tickets'))}</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="support-ticket-summary">${tickets.length?escapeHtml(t('home.support.tickets_count',{count:tickets.length})):escapeHtml(t('home.support.tickets_empty'))}</div>
    <div class="support-ticket-list">${rows}</div>`);

  document.querySelectorAll('[data-support-ticket]').forEach(button=>button.addEventListener('click',()=>void openSupportTicketDetail(button.dataset.supportTicket||'',button)));
}

async function openSupportTicketDetail(ticketNumber,sourceButton=null){
  if(!ticketNumber)return;
  if(sourceButton)sourceButton.disabled=true;
  try{
    const result=await api.supportTicket(ticketNumber);
    const ticket=result?.ticket;
    if(!ticket)throw new Error(t('home.support.open_error'));
    renderSupportTicketDetail(ticketNumber,ticket);
  }catch(error){
    toast(error.message||t('home.support.open_error'));
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
          <span data-support-reply-toggle-label>${escapeHtml(closed?t('home.support.closed'):t('home.support.reply'))}</span>
          <span class="support-reply-toggle-icon" aria-hidden="true">＋</span>
        </button>
        <div class="support-reply-composer" id="supportReplyComposer" hidden>
          <textarea id="supportReplyText" class="support-ticket-control support-ticket-message support-reply-text" maxlength="4000" placeholder="${escapeHtml(t('home.support.reply_placeholder'))}"></textarea>
          <div class="support-file-picker support-file-picker--reply">
            <div class="support-file-picker-head">
              <div><strong>${escapeHtml(t('home.support.reply_add'))}</strong><small>${escapeHtml(t('home.support.attachments_note'))}</small></div>
              <button class="support-file-add" id="supportReplyFilesTrigger" type="button">${escapeHtml(t('home.support.reply_file'))}</button>
            </div>
            <input id="supportReplyFiles" class="support-file-native" type="file" multiple accept="image/jpeg,image/png,image/webp,image/gif,application/pdf,text/plain">
            <div class="support-file-list" id="supportReplyFilesList"></div>
          </div>
          <button class="btn primary full support-reply-send" id="supportReplySend" type="button">${escapeHtml(t('home.support.send'))}</button>
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
    if(!message)return toast(t('home.support.message_required'));
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
      toast(t('home.support.sent'));
    }catch(error){
      toast(error.message||t('home.support.send_error'));
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
    open:t('home.support.status_open'),
    in_progress:t('home.support.status_in_progress'),
    waiting_user:t('home.support.status_waiting'),
    waiting_for_user:t('home.support.status_waiting'),
    resolved:t('home.support.status_resolved'),
    closed:t('home.support.status_closed'),
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
          <span class="support-thread-attachment-name">${escapeHtml(file.file_name||t('home.support.attachment_fallback'))}</span>
          <em>${escapeHtml(t('home.support.open'))}</em>
        </button>
        <div class="support-thread-attachment-preview" data-support-attachment-preview="${escapeHtml(file.attachment_id||'')}"></div>
      </div>`).join('');
    return `<article class="support-thread-message ${own?'is-user':'is-admin'}">
      <header><strong>${escapeHtml(own?t('home.support.your_message'):t('home.support.support'))}</strong><time>${escapeHtml(formatDate(message.created_at_utc||''))}</time></header>
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
  if(toggleLabel)toggleLabel.textContent=closed?t('home.support.closed'):t('home.support.reply');
  if(closed)setSupportReplyExpanded(false);
}

function supportFileValidation(file){
  const allowed=['image/jpeg','image/png','image/webp','image/gif','application/pdf','text/plain'];
  if(!file)return t('home.support.file_not_selected');
  if(Number(file.size||0)>2000000)return t('home.support.file_too_large');
  const mime=String(file.type||'').toLowerCase();
  if(!allowed.includes(mime))return t('home.support.file_type_error');
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
      <span><strong>${escapeHtml(file.name)}</strong><small>${escapeHtml(t('home.support.file_size_kb',{count:Math.max(1,Math.ceil(file.size/1024))}))}</small></span>
      <button type="button" data-support-file-remove="${index}" aria-label="${escapeHtml(t('home.support.remove_file'))}">×</button>
    </div>`).join('');
    list.querySelectorAll('[data-support-file-remove]').forEach(button=>button.addEventListener('click',()=>{
      const index=Number(button.dataset.supportFileRemove);
      files=files.filter((_,itemIndex)=>itemIndex!==index);
      render();
    }));
    if(trigger){
      trigger.disabled=files.length>=3;
      trigger.textContent=files.length>=3?t('home.support.file_limit_button'):(triggerId==='supportReplyFilesTrigger'?t('home.support.reply_file'):t('home.support.add_file'));
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
        toast(t('home.support.file_limit_error'));
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
  if(files.length>3)throw new Error(t('home.support.file_limit_error'));
  return Promise.all(files.map(file=>new Promise((resolve,reject)=>{
    const validation=supportFileValidation(file);
    if(validation)return reject(new Error(`${file.name}: ${validation}`));
    const reader=new FileReader();
    reader.onerror=()=>reject(new Error(t('home.support.read_error',{file:file.name})));
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
    if(action)action.textContent=hidden?t('home.support.hide'):t('home.support.open');
    return;
  }
  const label=button?.querySelector('em');
  if(label)label.textContent=t('home.support.loading');
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
      preview.innerHTML=`<img src="${url}" alt="${escapeHtml(file.file_name||t('home.support.attachment_fallback'))}">`;
      preview.dataset.loaded='1';
      preview.hidden=false;
      if(label)label.textContent=t('home.support.hide');
      window.setTimeout(()=>URL.revokeObjectURL(url),300000);
    }else{
      const anchor=document.createElement('a');
      anchor.href=url;
      anchor.download=String(file.file_name||'attachment');
      document.body.append(anchor);
      anchor.click();
      anchor.remove();
      window.setTimeout(()=>URL.revokeObjectURL(url),60000);
      if(label)label.textContent=t('home.support.open');
    }
  }catch(error){
    toast(error.message||t('home.support.attachment_open_error'));
    if(label)label.textContent=t('home.support.open');
  }finally{
    if(button)button.disabled=false;
  }
}
