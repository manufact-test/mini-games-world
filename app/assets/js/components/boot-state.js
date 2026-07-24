let readyDispatched = false;

export function showHomeActivity(){
  document.getElementById('activityTitle')?.removeAttribute('hidden');
  document.getElementById('activityGrid')?.removeAttribute('hidden');
  document.getElementById('bootFailureBanner')?.remove();
}

export function showBootFailure(){
  const name = document.getElementById('topName');
  const avatar = document.getElementById('topAvatar');
  if (name) name.textContent = 'Профиль не загружен';
  if (avatar) {
    avatar.textContent = '!';
    avatar.style.backgroundImage = '';
  }

  document.getElementById('activityTitle')?.setAttribute('hidden', '');
  document.getElementById('activityGrid')?.setAttribute('hidden', '');

  const content = document.querySelector('#screen-home .content');
  if (!content || document.getElementById('bootFailureBanner')) return;

  const banner = document.createElement('section');
  banner.id = 'bootFailureBanner';
  banner.className = 'runtime-status-banner';
  banner.setAttribute('role', 'alert');
  banner.innerHTML = `
    <strong>Не удалось загрузить профиль</strong>
    <span>Закройте Mini Games World и откройте его снова из Telegram.</span>
    <button class="btn ghost" type="button" data-retry-bootstrap>Повторить</button>
  `;
  const topbar = content.querySelector('.topbar');
  if (topbar?.nextSibling) content.insertBefore(banner, topbar.nextSibling);
  else content.prepend(banner);

  banner.querySelector('[data-retry-bootstrap]')?.addEventListener('click', () => {
    window.location.reload();
  });
}

export function dispatchAppReady(){
  if (readyDispatched) return;
  readyDispatched = true;
  document.dispatchEvent(new CustomEvent('mgw:app-ready'));
}
