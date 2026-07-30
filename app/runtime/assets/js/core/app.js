import { createRuntimeStore } from './store.js';
import { createRuntimeRouter } from './router.js';
import { readCanonicalLaunch } from './launch.js';
import { installRuntimeErrorBoundary } from './error-boundary.js';
import { createRuntimeApi } from './api-client.js';
import { getOrCreateInstallationId } from './installation.js';

export async function startCleanRuntime(){
  const root = document.getElementById('app');
  if (!(root instanceof HTMLElement)) throw new Error('Clean runtime root was not found.');

  const store = createRuntimeStore();
  const router = createRuntimeRouter(root);
  const errors = installRuntimeErrorBoundary({ store, router, root });
  const api = createRuntimeApi();

  try {
    const launch = readCanonicalLaunch();
    const installationId = getOrCreateInstallationId();
    store.setState({ phase:'connecting', launch });

    const bootstrap = await api.bootstrap({ installationId, launch });
    store.setState({
      phase:'ready',
      launch,
      server:bootstrap.server,
      storage:bootstrap.storage,
      installation:bootstrap.installation,
    });

    renderRuntimeDetails(root, store.getState());
    router.show('home');
    document.documentElement.setAttribute('data-mgw-runtime', 'clean-v1');
    document.dispatchEvent(new CustomEvent('mgw:clean-runtime-ready', {
      detail:{
        launch,
        server:bootstrap.server,
        storage:bootstrap.storage,
        installation:bootstrap.installation,
      },
    }));
  } catch (error) {
    errors.showError(error);
  }
}

function renderRuntimeDetails(root, state){
  const target = root.querySelector('[data-launch-details]');
  if (!(target instanceof HTMLElement)) return;
  const launch = state.launch || {};
  const server = state.server || {};
  const storage = state.storage || {};
  const installation = state.installation || {};

  target.replaceChildren(
    detailRow('Runtime', launch.runtime),
    detailRow('Маршрут', launch.path),
    detailRow('Источник', launch.source),
    detailRow('Invite token', launch.inviteToken || 'нет'),
    detailRow('Telegram', launch.telegramAvailable ? 'доступен' : 'не обнаружен'),
    detailRow('Server build', server.build),
    detailRow('Среда', server.environment),
    detailRow('Storage', storage.adapter),
    detailRow('Ревизия', storage.revision),
    detailRow('Запусков staging', installation.launch_count),
  );
}

function detailRow(label, value){
  const row = document.createElement('div');
  const term = document.createElement('dt');
  const description = document.createElement('dd');
  term.textContent = label;
  description.textContent = String(value ?? '—');
  row.append(term, description);
  return row;
}
