import { closeSheet } from './components/sheet.js?v=68';
import { getInitData } from './telegram/telegram-app.js?v=27';
import { getSessionId } from './session.js?v=27';

const INVITES_URL = `${window.location.origin}/bot/invites.php`;
let initialized = false;

export function initV109ShareFallbackGuard(){
  if (initialized) return;
  initialized = true;

  window.addEventListener('click', event => {
    const origin = event.target;
    if (!(origin instanceof Element)) return;
    const button = origin.closest('[data-v109-discard-draft]');
    if (!(button instanceof HTMLButtonElement)) return;

    event.preventDefault();
    event.stopImmediatePropagation();

    const token = String(document.querySelector('[data-invite-sheet]')?.dataset.inviteToken || '');
    const context = window.__MGW_V109_LAST_INVITE_CONTEXT__ || null;
    closeSheet();

    if (token) void discard(token);
    document.dispatchEvent(new CustomEvent('mgw:v109-invite-slot-free', {
      detail:{ context },
    }));
  }, true);
}

async function discard(token){
  try {
    await fetch(INVITES_URL, {
      method:'POST',
      headers:{ 'Content-Type':'application/json' },
      body:JSON.stringify({
        initData:getInitData(),
        sessionId:getSessionId(),
        action:'discard_draft',
        token,
      }),
      priority:'high',
    });
  } catch (error) {
    // The stale draft expires server-side; the visible cancel remains immediate.
  }
}
