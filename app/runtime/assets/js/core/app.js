import { createRuntimeStore } from './store.js?v=7';
import { createRuntimeRouter } from './router.js';
import { readCanonicalLaunch } from './launch.js';
import { installRuntimeErrorBoundary } from './error-boundary.js';
import { createRuntimeApi } from './api-client.js?v=6';
import { getOrCreateInstallationId } from './installation.js';
import { getOrCreateSessionId } from './session.js';
import { readTelegramInitData, readPresenceContext } from './client-context.js';
import { createPresenceOwner } from './presence-owner.js';
import { createMatchOwner } from './match-owner.js?v=7';

const CLIENT_BUILD = 'clean-client-v7-surrender-transition';

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
    const sessionId = getOrCreateSessionId();
    const initData = readTelegramInitData();
    const requestContext = () => ({
      installationId,
      sessionId,
      initData,
      launch,
      presence:readPresenceContext(),
    });

    window.Telegram?.WebApp?.ready?.();
    window.Telegram?.WebApp?.expand?.();
    store.setState({ phase:'connecting', launch });

    const bootstrap = await api.bootstrap(requestContext());
    store.setState({
      phase:'ready',
      launch,
      server:bootstrap.server,
      storage:bootstrap.storage,
      installation:bootstrap.installation,
      account:bootstrap.account,
      session:bootstrap.session,
      presence:bootstrap.presence,
      matchmaking:bootstrap.matchmaking,
      activeMatch:bootstrap.active_match,
      matchResult:bootstrap.match_result,
      balances:bootstrap.balances,
    });

    store.subscribe(state => renderRuntimeDetails(root, state));
    renderRuntimeDetails(root, store.getState());
    document.documentElement.setAttribute('data-mgw-runtime', 'clean-v1');
    document.documentElement.setAttribute('data-mgw-client-build', CLIENT_BUILD);

    const matchOwner = createMatchOwner({ root, api, store, router, requestContext });
    matchOwner.start();

    const presenceOwner = createPresenceOwner({ api, store, requestContext });
    presenceOwner.start();

    document.dispatchEvent(new CustomEvent('mgw:clean-runtime-ready', {
      detail:{
        launch,
        client_build:CLIENT_BUILD,
        server:bootstrap.server,
        storage:bootstrap.storage,
        account:bootstrap.account,
        session:bootstrap.session,
        presence:bootstrap.presence,
        matchmaking:bootstrap.matchmaking,
        active_match:bootstrap.active_match,
        match_result:bootstrap.match_result,
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
  const account = state.account || {};
  const session = state.session || {};
  const presence = state.presence || {};

  target.replaceChildren(
    detailRow('Runtime', launch.runtime),
    detailRow('Client build', CLIENT_BUILD),
    detailRow('Источник', launch.source),
    detailRow('Авторизация', authLabel(account.auth_method)),
    detailRow('Игрок', account.first_name || 'Staging player'),
    detailRow('Сессия', session.locked ? 'занята другим устройством' : 'активна'),
    detailRow('Присутствие', presence.state === 'online' ? 'онлайн' : presence.state || '—'),
    detailRow('Server build', server.build),
    detailRow('Storage', storage.adapter),
    detailRow('Ревизия', storage.revision),
    detailRow('Запусков staging', installation.launch_count),
  );
}

function authLabel(method){
  return method === 'telegram' ? 'Telegram' : method === 'browser_staging' ? 'Browser staging' : '—';
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
