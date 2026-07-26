const ICONS = {
  tictactoe: `
    <svg viewBox="0 0 48 48" aria-hidden="true">
      <path d="M8 10L25 27M25 10L8 27" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round"/>
      <circle cx="35" cy="29" r="8.5" fill="none" stroke="currentColor" stroke-width="2.8"/>
    </svg>
  `,
  four_in_a_row: `
    <svg viewBox="0 0 48 48" aria-hidden="true">
      <circle cx="17" cy="17" r="10" fill="#ff385f"/>
      <circle cx="31" cy="31" r="10" fill="#ffd43b"/>
      <circle cx="17" cy="17" r="10" fill="none" stroke="rgba(255,255,255,.4)" stroke-width="1.2"/>
      <circle cx="31" cy="31" r="10" fill="none" stroke="rgba(255,255,255,.4)" stroke-width="1.2"/>
    </svg>
  `,
  battleship: `
    <svg viewBox="0 0 48 48" aria-hidden="true">
      <path d="M9 29H39L35 36H14L9 29Z" fill="currentColor" opacity=".9"/>
      <path d="M18 27V18H29V27M23 18V13H27V18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linejoin="round"/>
      <path d="M8 39C12 42 16 42 20 39C24 42 28 42 32 39C36 42 40 42 42 40" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round"/>
    </svg>
  `,
  checkers: `
    <svg viewBox="0 0 48 48" aria-hidden="true">
      <circle cx="24" cy="24" r="14" fill="currentColor"/>
      <circle cx="24" cy="24" r="10" fill="none" stroke="rgba(10,14,24,.38)" stroke-width="2"/>
      <path d="M16 20H32M16 25H32M18 30H30" stroke="rgba(10,14,24,.35)" stroke-width="1.5" stroke-linecap="round"/>
    </svg>
  `,
  reversi: `
    <svg viewBox="0 0 48 48" aria-hidden="true">
      <circle cx="24" cy="24" r="14" fill="#ffffff"/>
      <path d="M24 10A14 14 0 0 1 24 38Z" fill="#171d2a"/>
      <circle cx="24" cy="24" r="14" fill="none" stroke="currentColor" stroke-width="1.8"/>
    </svg>
  `,
  chess: `
    <svg viewBox="0 0 48 48" aria-hidden="true">
      <path d="M16 37H35M18 33H33L31 28C35 24 34 17 29 14L27 9L20 15L14 18L20 22C16 25 16 29 18 33Z" fill="currentColor" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
      <circle cx="26.5" cy="16.5" r="1.5" fill="#171d2a"/>
    </svg>
  `,
  go: `
    <svg viewBox="0 0 48 48" aria-hidden="true">
      <path d="M10 10H38V38H10Z M10 19H38 M10 29H38 M19 10V38 M29 10V38" fill="none" stroke="currentColor" stroke-width="1.6"/>
      <circle cx="29" cy="19" r="7.2" fill="#ffffff" stroke="rgba(10,14,24,.45)" stroke-width="1.4"/>
    </svg>
  `,
  domino: `
    <svg viewBox="0 0 48 48" aria-hidden="true">
      <rect x="12" y="7" width="24" height="34" rx="7" fill="none" stroke="currentColor" stroke-width="2.4"/>
      <path d="M13 24H35" stroke="currentColor" stroke-width="2"/>
      <circle cx="20" cy="15" r="2.2" fill="currentColor"/>
      <circle cx="29" cy="31" r="2.2" fill="currentColor"/>
      <circle cx="20" cy="35" r="2.2" fill="currentColor"/>
    </svg>
  `,
};

let initialized = false;
let applying = false;

export function initDeterministicGameIcons(){
  if (initialized) return;
  initialized = true;

  renderAllGameIcons();

  const root = document.getElementById('app') || document.body;
  const observer = new MutationObserver(() => {
    if (applying) return;
    queueMicrotask(renderAllGameIcons);
  });
  observer.observe(root, { childList:true, subtree:true, characterData:true });

  document.addEventListener('mgw:app-ready', renderAllGameIcons);
}

function renderAllGameIcons(){
  applying = true;
  try {
    document.querySelectorAll('[data-game-card]').forEach(card => {
      const gameType = String(card.dataset.gameCard || '');
      const icon = card.querySelector('[data-game-icon]');
      const markup = ICONS[gameType];
      if (!icon || !markup || icon.dataset.mgwSvgIcon === gameType) return;
      icon.className = `game-icon game-icon-${gameType}`;
      icon.innerHTML = markup;
      icon.dataset.mgwSvgIcon = gameType;
    });
  } finally {
    applying = false;
  }
}
