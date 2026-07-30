import { createRuntimeStore } from './store.js';
import { createRuntimeRouter } from './router.js';
import { readCanonicalLaunch } from './launch.js';
import { installRuntimeErrorBoundary } from './error-boundary.js';

export async function startCleanRuntime(){
  const root = document.getElementById('app');
  if (!(root instanceof HTMLElement)) throw new Error('Clean runtime root was not found.');

  const store = createRuntimeStore();
  const router = createRuntimeRouter(root);
  const errors = installRuntimeErrorBoundary({ store, router, root });

  try {
    const launch = readCanonicalLaunch();
    store.setState({ phase:'ready', launch });
    renderLaunchDetails(root, launch);
    router.show('home');
    document.documentElement.setAttribute('data-mgw-runtime', 'clean-v1');
    document.dispatchEvent(new CustomEvent('mgw:clean-runtime-ready', { detail:{ launch } }));
  } catch (error) {
    errors.showError(error);
  }
}

function renderLaunchDetails(root, launch){
  const target = root.querySelector('[data-launch-details]');
  if (!(target instanceof HTMLElement)) return;
  target.replaceChildren(
    detailRow('Runtime', launch.runtime),
    detailRow('Маршрут', launch.path),
    detailRow('Источник', launch.source),
    detailRow('Invite token', launch.inviteToken || 'нет'),
    detailRow('Telegram', launch.telegramAvailable ? 'доступен' : 'не обнаружен'),
  );
}

function detailRow(label, value){
  const row = document.createElement('div');
  const term = document.createElement('dt');
  const description = document.createElement('dd');
  term.textContent = label;
  description.textContent = String(value || '—');
  row.append(term, description);
  return row;
}
