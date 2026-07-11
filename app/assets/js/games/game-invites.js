import { toast } from '../components/toast.js?v=41';

let initialized = false;

export function initGameInvites(){
  if (initialized) return;
  initialized = true;

  document.addEventListener('click', event => {
    const button = event.target.closest('[data-invite-friend]');
    if (!button) return;

    // Keep one shared entry point for every game until the social MVP connects
    // real Telegram/deep-link invitations.
    event.stopImmediatePropagation();
    toast('Приглашения друзей появятся позже.');
  });
}
