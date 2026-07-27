import { toast } from './components/toast.js?v=41';
import { currentV99PassiveLock } from './production-v99-session-transport.js?v=99';

const START_IDS = new Set([
  'startSearchBtn',
  'startFourSearchBtn',
  'startBattleshipSearchBtn',
  'startCheckersSearchBtn',
  'startReversiSearchBtn',
  'startChessSearchBtn',
  'startGoSearchBtn',
  'startDominoSearchBtn',
]);

let initialized = false;
let lastToastAt = 0;

export function initV99ExplicitLockGuard(){
  if (initialized) return;
  initialized = true;

  document.addEventListener('click', event => {
    const lock = currentV99PassiveLock();
    if (!lock?.locked) return;

    const origin = event.target;
    if (!(origin instanceof Element)) return;
    const button = origin.closest('button, [role="button"]');
    if (!(button instanceof Element)) return;

    const inviteAction = String(button.closest('[data-invite-action]')?.dataset.inviteAction || '');
    const explicitStart = START_IDS.has(String(button.id || ''))
      || ['accept','start'].includes(inviteAction)
      || Boolean(button.closest('[data-create-rematch]'));
    if (!explicitStart) return;

    event.preventDefault();
    event.stopImmediatePropagation();
    const now = Date.now();
    if (now - lastToastAt < 1800) return;
    lastToastAt = now;
    toast(String(lock.message || 'У вас уже идёт активная игра на другом устройстве.'));
  }, true);
}
