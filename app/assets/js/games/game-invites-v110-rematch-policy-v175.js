import { state } from '../state.js?v=27';
import {
  initGameInvites as initBaseGameInvites,
  openIncomingInviteIfPresent,
} from './game-invites-v110.js?v=1142&zone=unified&rematch=optimistic&terminal=self-silent';

let policyInitialized = false;
let resultObserver = null;
let syncQueued = false;

export { openIncomingInviteIfPresent };

export function initGameInvites(){
  // Install the bot-opaque result policy before the legacy invite owner starts
  // observing result sheets. This keeps the final action hierarchy stable from
  // the first paint instead of correcting it after a 40 ms legacy enhancement.
  initRematchPresentationPolicy();
  initBaseGameInvites();
}

function initRematchPresentationPolicy(){
  if (policyInitialized) return;
  policyInitialized = true;

  const style = document.createElement('style');
  style.id = 'mgwRematchPresentationPolicy';
  style.textContent = `
    #sheet [data-create-rematch]:not([data-mgw-rematch-available="true"]) {
      display: none !important;
    }
  `;
  document.head.append(style);

  const sheet = document.getElementById('sheet');
  if (sheet) {
    resultObserver = new MutationObserver(queueResultPolicySync);
    resultObserver.observe(sheet, { childList:true, subtree:true, characterData:true, attributes:true, attributeFilter:['class'] });
  }

  document.addEventListener('mgw:game-finished', queueResultPolicySync);
  document.addEventListener('mgw:sheet-opened', queueResultPolicySync);
  queueResultPolicySync();
}

function queueResultPolicySync(){
  if (syncQueued) return;
  syncQueued = true;
  queueMicrotask(() => {
    syncQueued = false;
    syncResultActions();
  });
}

function syncResultActions(){
  const game = state.activeGame;
  const directRematchAvailable = String(game?.status || '') === 'finished'
    && game?.rematch_available === true;

  const playAgain = document.getElementById('newOpponent');
  if (playAgain) {
    if (playAgain.textContent !== 'Сыграть ещё') playAgain.textContent = 'Сыграть ещё';

    // Legacy v110 turns this button into ghost styling 40 ms after every
    // two-player result. For an automated opponent that creates the visible
    // purple flash even though the direct-rematch button is immediately hidden.
    // Neutral capability ownership must also own the button hierarchy.
    if (directRematchAvailable) {
      playAgain.classList.remove('primary');
      playAgain.classList.add('ghost');
    } else {
      playAgain.classList.remove('ghost');
      playAgain.classList.add('primary');
    }
  }

  document.querySelectorAll('#sheet [data-create-rematch]').forEach(button => {
    if (!(button instanceof HTMLButtonElement)) return;
    if (directRematchAvailable) {
      button.dataset.mgwRematchAvailable = 'true';
      button.removeAttribute('aria-hidden');
      return;
    }

    delete button.dataset.mgwRematchAvailable;
    button.setAttribute('aria-hidden', 'true');
  });
}
