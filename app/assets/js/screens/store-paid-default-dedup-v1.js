const INSTALL_KEY = '__mgwPaidDefaultDedupV1Installed';

const COPY = Object.freeze({
  'chess:theme:wood': {
    title:'Янтарная доска',
    description:'Янтарно-бордовая доска с глубоким контрастом и тёплым клубным характером',
  },
  'chess:elements:wood': {
    title:'Янтарные фигуры',
    description:'Янтарные и тёмно-вишнёвые фигуры, заметно отличающиеся от базового набора',
  },
  'checkers:theme:wood': {
    title:'Лазурная доска',
    description:'Холодная лазурно-мятная доска с тёмными бирюзовыми клетками',
  },
  'checkers:elements:wood': {
    title:'Керамические шашки',
    description:'Глазурованные бирюзовые и терракотовые шашки с керамическим блеском',
  },
  'reversi:theme:green': {
    title:'Лазурное поле',
    description:'Холодное лазурное поле вместо стандартного зелёного покрытия',
  },
  'reversi:elements:classic': {
    title:'Перламутровые фишки',
    description:'Тёмный индиго и светлый перламутр с мягким цветным отливом',
  },
  'go:theme:wood': {
    title:'Доска сакуры',
    description:'Розово-вишнёвая древесина с тёмной сеткой и спокойной японской фактурой',
  },
  'go:elements:classic': {
    title:'Янтарные камни',
    description:'Дымчатые и медово-янтарные камни вместо стандартной чёрно-белой пары',
  },
  'domino:theme:felt': {
    title:'Бордовый стол',
    description:'Глубокое бордовое сукно с винной кромкой вместо стандартного зелёного стола',
  },
  'domino:elements:ivory': {
    title:'Янтарные костяшки',
    description:'Тёплые янтарные костяшки с тёмными точками вместо стандартных светлых',
  },
  'four_in_a_row:theme:blue': {
    title:'Фиолетовое поле',
    description:'Насыщенное фиолетово-сливовое поле вместо стандартной синей рамы',
  },
  'four_in_a_row:elements:classic': {
    title:'Аркадные фишки',
    description:'Яркая розово-бирюзовая пара вместо стандартных красных и жёлтых фишек',
  },
});

export function installPaidDefaultDedupV1(){
  ensureStyles();
  if (globalThis[INSTALL_KEY]) return;
  globalThis[INSTALL_KEY] = true;

  const schedule = () => {
    queueMicrotask(upgradePaidDefaultDedupV1);
    if (typeof globalThis.requestAnimationFrame === 'function') globalThis.requestAnimationFrame(upgradePaidDefaultDedupV1);
    globalThis.setTimeout(upgradePaidDefaultDedupV1, 0);
    globalThis.setTimeout(upgradePaidDefaultDedupV1, 90);
  };

  document.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;
    if (!target.closest('[data-store-v2-tab="games"], [data-store-v2-game], [data-store-v2-buy], [data-store-v2-equip], [data-store-v2-unequip], #storeV2ConfirmBuy')) return;
    schedule();
  });

  schedule();
}

export function upgradePaidDefaultDedupV1(){
  ensureStyles();
  const roots = [
    document.querySelector('[data-store-v2-panel="games"]'),
    document.getElementById('sheet'),
  ];
  roots.forEach(root => {
    if (!(root instanceof HTMLElement)) return;
    root.querySelectorAll('.store-v2-game-product').forEach(product => {
      if (!(product instanceof HTMLElement)) return;
      const preview = product.querySelector('.store-v2-game-preview[data-game-type]');
      if (!(preview instanceof HTMLElement)) return;
      const gameType = String(preview.dataset.gameType || '');
      const layer = String(preview.dataset.cosmeticLayer || '');
      const variant = String(preview.dataset.cosmeticVariant || '');
      const copy = COPY[`${gameType}:${layer}:${variant}`];
      if (!copy) return;

      const title = product.querySelector('.store-v2-game-product-copy > strong');
      const description = product.querySelector('.store-v2-game-product-copy > p');
      if (title instanceof HTMLElement) title.textContent = copy.title;
      if (description instanceof HTMLElement) description.textContent = copy.description;
      preview.setAttribute('aria-label', copy.title);
      product.dataset.mgwPaidDefaultDedup = 'v1';
    });
  });
}

function ensureStyles(){
  const href = new URL('../../css/games/paid-default-dedup-v1.css?v=1&paid_default=dedup-v1', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-paid-default-dedup]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    document.head.appendChild(existing);
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwPaidDefaultDedup = 'v1';
  link.href = href;
  document.head.appendChild(link);
}
