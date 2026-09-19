import { api } from '../api/client.js?v=34';
import { state } from '../state.js?v=27';
import { openSheet, closeSheet } from '../components/sheet.js?v=68';
import { toast } from '../components/toast.js?v=27';
import { renderBalances } from '../ui.js?v=89';
import { haptic } from '../telegram/telegram-app.js?v=27';
import { dominoPreviewMarkup, dominoHeaderMarksMarkup } from './store-screen-domino-store-v1.js?v=14&mvp19_9=domino-svg-pips-v48';

const STORE_TABS = Object.freeze([
  { id:'coins', label:'Коины' },
  { id:'profile', label:'Профиль' },
  { id:'games', label:'Игры' },
  { id:'bundles', label:'Наборы' },
]);
const GAME_CATALOG_ORDER = Object.freeze(['tictactoe','chess','checkers','domino']);
const BUNDLE_REFERENCE_GAMES = Object.freeze(['tictactoe','checkers']);

let storeState = null;
let storeSurface = 'tab';
let activeTab = 'profile';
let activeGameCatalog = 'tictactoe';
let activeBundleGame = 'tictactoe';
let storeLoadPromise = null;
let purchaseBusy = false;
let equipBusy = false;
let bundlePreviewResizeBound = false;

ensureBundlePrototypeStyles();

function ensureBundlePrototypeStyles(){
  const href = new URL('../../css/screens/store-bundle-prototype-v1.css?v=9&mvp19_13=checkers-sheet-exact-snapshot-v6', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-store-bundle-prototype]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwStoreBundlePrototype = 'mvp19-13-checkers-sheet-exact-snapshot-v6';
  link.href = href;
  document.head.appendChild(link);
}

export function initStoreScreen(){
  document.addEventListener('click', event => {
    const trigger = event.target.closest('#storeOpen');
    if (!trigger) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    openStoreTab();
  }, true);

  if (!bundlePreviewResizeBound) {
    bundlePreviewResizeBound = true;
    globalThis.addEventListener?.('resize', () => scheduleBundlePreviewFit(currentRoot()), { passive:true });
  }

  const warm = () => void warmStore().catch(() => {});
  if (typeof globalThis.requestIdleCallback === 'function') {
    globalThis.requestIdleCallback(warm, { timeout:900 });
  } else {
    globalThis.setTimeout(warm, 250);
  }
}

export async function openStoreTab(){
  storeSurface = 'tab';
  haptic('light');
  if (storeState) {
    renderStore();
    void refreshStoreSilently();
    return;
  }
  renderStorePending();
  await loadStore();
}

export async function openStoreSheet(){
  storeSurface = 'sheet';
  haptic('light');
  if (storeState) {
    renderStore();
    void refreshStoreSilently();
    return;
  }
  renderStorePending();
  await loadStore();
}

function warmStore(){
  if (storeState) return Promise.resolve(storeState);
  return fetchStore();
}

function fetchStore(){
  if (storeLoadPromise) return storeLoadPromise;
  storeLoadPromise = api.cosmeticStoreStatus()
    .then(result => {
      if (!purchaseBusy && !equipBusy) applyStoreResponse(result);
      return storeState;
    })
    .finally(() => {
      storeLoadPromise = null;
    });
  return storeLoadPromise;
}

async function loadStore(){
  try {
    await fetchStore();
    renderStore();
  } catch (error) {
    renderStoreError(error);
  }
}

async function refreshStoreSilently(){
  try {
    await fetchStore();
    if (!purchaseBusy && !equipBusy) {
      renderStore();
    } else {
      updateVisibleBalance();
    }
  } catch (_) {
    // Keep the already rendered Store snapshot if a background refresh fails.
  }
}

function applyStoreResponse(result){
  storeState = result?.store && typeof result.store === 'object' ? result.store : null;
  if (!storeState) throw new Error('Магазин вернул неполный ответ.');
  if (state.user && typeof state.user === 'object') {
    state.user = { ...state.user, balance:Number(storeState.balance || 0) };
    renderBalances(state.user);
  }
  if (!STORE_TABS.some(tab => tab.id === activeTab)) activeTab = 'profile';
  const catalogs = storeState?.games?.catalogs && typeof storeState.games.catalogs === 'object' ? storeState.games.catalogs : {};
  if (!catalogs[activeGameCatalog]) activeGameCatalog = orderedGameCatalogs(catalogs)[0]?.game_type || 'tictactoe';
}

function renderStorePending(){
  renderStoreSurface(`
    ${renderStoreHead()}
    <div class="store-v2-shell is-pending">
      ${renderBalanceHero()}
      ${renderTabs()}
      <div class="store-v2-content" data-store-v2-panel="${escapeAttr(activeTab)}">
        <div class="store-v2-skeleton-grid" aria-hidden="true">
          ${Array.from({ length:4 }, () => '<span></span>').join('')}
        </div>
      </div>
    </div>
  `);
  bindStoreEvents();
}

function renderStore(){
  if (!storeState) return;
  renderStoreSurface(`
    ${renderStoreHead()}
    <div class="store-v2-shell">
      ${renderBalanceHero()}
      ${renderTabs()}
      <div class="store-v2-content" data-store-v2-panel="${escapeAttr(activeTab)}">
        ${renderActiveTab()}
      </div>
    </div>
  `);
  bindStoreEvents();
  scheduleBundlePreviewFit(currentRoot());
}

function renderBalanceHero(){
  const balance = storeState?.balance ?? state.user?.balance ?? 0;
  return `
    <section class="store-v2-balance">
      <span>Баланс</span>
      <strong data-store-v2-balance>${formatNumber(balance)} <small>коинов</small></strong>
    </section>
  `;
}

function updateVisibleBalance(){
  const root = currentRoot();
  const target = root?.querySelector('[data-store-v2-balance]');
  if (!target) return;
  target.innerHTML = `${formatNumber(storeState?.balance ?? state.user?.balance ?? 0)} <small>коинов</small>`;
}

function storeTabs(){
  const serverTabs = Array.isArray(storeState?.tabs) ? storeState.tabs : [];
  const serverById = new Map(serverTabs.map(tab => [String(tab?.id || ''), tab]));
  return STORE_TABS.map(tab => ({ ...tab, ...(serverById.get(tab.id) || {}) }));
}

function renderTabs(){
  return `
    <div class="store-v2-tabs" role="tablist" aria-label="Разделы магазина">
      ${storeTabs().map(tab => `
        <button class="store-v2-tab ${activeTab === String(tab.id) ? 'active' : ''}" data-store-v2-tab="${escapeAttr(tab.id)}" type="button" role="tab" aria-selected="${activeTab === String(tab.id) ? 'true' : 'false'}">
          ${escapeHtml(tab.label || tab.id)}
        </button>
      `).join('')}
    </div>
  `;
}

function renderActiveTab(){
  switch (activeTab) {
    case 'coins': return renderCoinsTab();
    case 'profile': return renderProfileTab();
    case 'games': return renderGamesTab();
    case 'bundles': return renderBundlesTab();
    default: return renderProfileTab();
  }
}

function renderCoinsTab(){
  const packages = Array.isArray(storeState?.coins?.packages) ? storeState.coins.packages : [];
  return `
    <div class="store-v2-coin-grid">
      ${packages.map(pkg => `
        <article class="store-v2-coin-card">
          <div class="store-v2-coin-mark" aria-hidden="true"><span>MG</span></div>
          <div class="store-v2-coin-copy">
            <strong>${formatNumber(pkg.coins)}</strong>
            <span>коинов</span>
            <b>${formatEuro(pkg.price_eur_cents)}</b>
          </div>
          <em>Скоро</em>
        </article>
      `).join('') || emptyState('Пакеты пока недоступны')}
    </div>
  `;
}

function renderProfileTab(){
  const avatars = Array.isArray(storeState?.profile?.avatars) ? storeState.profile.avatars : [];
  const nameColors = Array.isArray(storeState?.profile?.name_colors) ? storeState.profile.name_colors : [];
  return `
    <div class="store-v2-title-row"><h2>Аватарки</h2></div>
    <div class="store-v2-product-grid">
      ${avatars.map(renderAvatarOffer).join('') || emptyState('Аватарки пока недоступны')}
    </div>
    <section class="store-v2-name-color-section">
      <div class="store-v2-title-row"><h2>Цвет имени</h2></div>
      <div class="store-v2-name-color-grid">
        ${nameColors.map(renderNameColorOffer).join('') || emptyState('Цвета имени пока недоступны')}
      </div>
    </section>
  `;
}

function renderAvatarOffer(offer){
  const owned = Boolean(offer?.already_owned);
  const number = Number(offer?.preview_number || 0);
  const itemId = Array.isArray(offer?.item_ids) ? String(offer.item_ids[0] || '') : '';
  const equippedItemId = String(state.selectedAvatarId || storeState?.inventory?.equipped?.profile_avatar || '');
  const equipped = owned && itemId !== '' && itemId === equippedItemId;
  return `
    <article class="store-v2-product ${owned ? 'owned' : ''} ${equipped ? 'equipped' : ''}">
      <div class="store-v2-avatar-preview" data-avatar-item-id="${escapeAttr(itemId)}" data-avatar-preview="${number}">
        <span>${String(number).padStart(2, '0')}</span>
        ${equipped ? '<i class="store-v2-selected-check" aria-label="Выбрана">✓</i>' : ''}
      </div>
      <strong class="store-v2-product-name">Аватарка ${number || ''}</strong>
      <div class="store-v2-product-foot">
        ${owned
          ? `<b>${equipped ? '' : 'Куплено'}</b>`
          : `<b>${formatNumber(offer?.price_coins || 0)}</b><button class="store-v2-buy" data-store-v2-buy="${escapeAttr(offer?.offer_id || '')}" type="button">Купить</button>`}
      </div>
    </article>
  `;
}

function renderNameColorOffer(offer){
  const owned = Boolean(offer?.already_owned);
  const equipped = owned && Boolean(offer?.equipped);
  const itemId = String(offer?.item_ids?.[0] || '');
  const slot = String(offer?.equip_slot || 'profile_name_color');
  const title = String(offer?.display_name || itemId || 'Цвет имени');
  const nickname = String(state.mgwProfile?.nickname || state.user?.display_name || 'Игрок');
  const tier = ({ normal:'Обычный', rare:'Редкий', gradient:'Градиент' })[String(offer?.metadata?.tier || 'normal')] || 'Цвет имени';
  return `
    <article class="store-v2-name-color-card ${owned ? 'owned' : ''} ${equipped ? 'equipped' : ''}">
      <div class="store-v2-name-color-preview"><strong data-name-color-item-id="${escapeAttr(itemId)}">${escapeHtml(nickname)}</strong>${equipped ? '<i class="store-v2-selected-check" aria-label="Выбран">✓</i>' : ''}</div>
      <div class="store-v2-name-color-copy"><strong>${escapeHtml(title)}</strong><small>${escapeHtml(tier)}</small></div>
      <div class="store-v2-name-color-foot">
        ${owned
          ? (equipped
            ? `<button class="store-v2-equip active" data-store-v2-unequip="${escapeAttr(slot)}" type="button">Снять</button>`
            : `<button class="store-v2-equip" data-store-v2-equip="${escapeAttr(itemId)}" type="button">Выбрать</button>`)
          : `<b>${formatNumber(offer?.price_coins || 0)}</b><button class="store-v2-buy" data-store-v2-buy="${escapeAttr(offer?.offer_id || '')}" type="button">Купить</button>`}
      </div>
    </article>
  `;
}

function orderedGameCatalogs(catalogs = storeState?.games?.catalogs || {}){
  return Object.values(catalogs || {}).filter(Boolean).sort((left, right) => {
    const li = GAME_CATALOG_ORDER.indexOf(String(left?.game_type || ''));
    const ri = GAME_CATALOG_ORDER.indexOf(String(right?.game_type || ''));
    return (li < 0 ? 999 : li) - (ri < 0 ? 999 : ri) || String(left?.title || '').localeCompare(String(right?.title || ''));
  });
}

function gamePresentation(gameType){
  if (gameType === 'chess') {
    return {
      mark:'♞♜',
      groups:[
        ['Доски','Оформление шахматной доски','themes'],
        ['Фигуры','Внешний вид фигур обоих игроков','elements'],
        ['Эффекты','Один выбранный эффект срабатывает в соответствующий момент','effects'],
      ],
      kinds:{ theme:'Шахматная доска', elements:'Комплект фигур', effect:'Эффект партии' },
    };
  }
  if (gameType === 'checkers') {
    return {
      mark:'●○',
      groups:[
        ['Доски','Оформление шашечной доски','themes'],
        ['Шашки','Внешний вид шашек обоих игроков','elements'],
        ['Эффекты','Один выбранный эффект срабатывает на нужном событии','effects'],
      ],
      kinds:{ theme:'Шашечная доска', elements:'Комплект шашек', effect:'Эффект партии' },
    };
  }
  if (gameType === 'domino') {
    return {
      mark:'',
      groups:[
        ['Столы','Оформление игрового стола','themes'],
        ['Костяшки','Комплект костяшек домино','elements'],
        ['Эффекты','','effects'],
      ],
      kinds:{ theme:'Игровой стол', elements:'Комплект костяшек', effect:'Эффект партии' },
    };
  }
  return {
    mark:'✕○',
    groups:[
      ['Поля','Фон и сетка игрового поля','themes'],
      ['Знаки','Внешний вид крестиков и ноликов','elements'],
      ['Эффекты','Один выбранный эффект срабатывает при каждом ходе','effects'],
    ],
    kinds:{ theme:'Игровое поле', elements:'Комплект знаков', effect:'Эффект хода' },
  };
}

function renderGamesTab(){
  const catalogs = orderedGameCatalogs();
  if (!catalogs.length) return emptyState('Игровая косметика пока недоступна');
  let catalog = catalogs.find(item => String(item?.game_type || '') === activeGameCatalog) || catalogs[0];
  activeGameCatalog = String(catalog?.game_type || 'tictactoe');
  const presentation = gamePresentation(activeGameCatalog);
  const selector = catalogs.length > 1 ? `
    <div class="store-v2-game-selector" role="tablist" aria-label="Игры">
      ${catalogs.map(item => {
        const gameType = String(item?.game_type || '');
        const active = gameType === activeGameCatalog;
        const label = gameType === 'domino' ? 'Домино' : String(item?.title || gameType);
        return `<button type="button" role="tab" class="store-v2-game-select${active ? ' active' : ''}" data-store-v2-game="${escapeAttr(gameType)}" aria-selected="${active ? 'true' : 'false'}">${escapeHtml(label)}</button>`;
      }).join('')}
    </div>` : '';
  const title = activeGameCatalog === 'domino' ? 'Домино' : String(catalog.title || activeGameCatalog);
  const marks = activeGameCatalog === 'domino'
    ? dominoHeaderMarksMarkup()
    : `<b>${escapeHtml(presentation.mark.slice(0,1))}</b><b>${escapeHtml(presentation.mark.slice(1))}</b>`;
  return `
    ${selector}
    <div class="store-v2-game-head" data-store-game-type="${escapeAttr(activeGameCatalog)}">
      <div>
        <span>Оформление игры</span>
        <h2>${escapeHtml(title)}</h2>
      </div>
      <div class="store-v2-game-head-marks" aria-hidden="true">${marks}</div>
    </div>
    ${presentation.groups.map(([groupTitle, subtitle, key]) => renderGameCosmeticGroup(groupTitle, subtitle, catalog[key], activeGameCatalog)).join('')}
  `;
}

function renderGameCosmeticGroup(title, subtitle, offers, gameType){
  const items = Array.isArray(offers) ? offers : [];
  return `
    <section class="store-v2-game-group">
      <div class="store-v2-title-row store-v2-game-title-row">
        <div><h2>${escapeHtml(title)}</h2>${subtitle ? `<p>${escapeHtml(subtitle)}</p>` : ''}</div>
      </div>
      <div class="store-v2-game-grid">${items.map(offer => renderGameOffer(offer, gameType)).join('')}</div>
    </section>
  `;
}

function renderGameOffer(offer, gameType){
  const owned = Boolean(offer?.already_owned);
  const equipped = owned && Boolean(offer?.equipped);
  const itemId = String(offer?.item_ids?.[0] || '');
  const slot = String(offer?.equip_slot || '');
  const layer = String(offer?.metadata?.layer || 'theme');
  const variant = String(offer?.metadata?.variant || 'base');
  const price = formatNumber(offer?.price_coins || 0);
  const presentation = gamePresentation(gameType);
  const kind = presentation.kinds[layer] || 'Игровой предмет';
  const description = gameCosmeticDescription(gameType, layer, variant);
  return `
    <article class="store-v2-game-product ${owned ? 'owned' : ''} ${equipped ? 'equipped' : ''}" data-store-game-product="${escapeAttr(gameType)}">
      ${gameCosmeticPreview(gameType, layer, variant, offer?.display_name || '')}
      <div class="store-v2-game-product-copy">
        <span>${escapeHtml(kind)}</span>
        <strong>${escapeHtml(offer?.display_name || itemId)}</strong>
        <p>${escapeHtml(description)}</p>
      </div>
      <div class="store-v2-game-product-foot">
        ${owned
          ? (equipped
            ? `<button class="store-v2-equip active" data-store-v2-unequip="${escapeAttr(slot)}" type="button">Снять</button>`
            : `<button class="store-v2-equip" data-store-v2-equip="${escapeAttr(itemId)}" type="button">Выбрать</button>`)
          : `<button class="store-v2-buy store-v2-game-buy" data-store-v2-buy="${escapeAttr(offer?.offer_id || '')}" type="button"><span>Купить</span><b>${price} коинов</b></button>`}
      </div>
    </article>
  `;
}

function gameCosmeticDescription(gameType, layer, variant){
  if (gameType === 'chess') {
    if (layer === 'theme') return ({ wood:'Янтарно-бордовая доска с глубоким контрастом и тёплым клубным характером', 'tournament-dark':'Контрастная турнирная доска', marble:'Холодный мрамор с прожилками', neon:'Тёмная доска с неоновым свечением' })[variant] || 'Меняет оформление шахматной доски';
    if (layer === 'elements') return ({ wood:'Янтарные и тёмно-вишнёвые фигуры с тёплым блеском и глубоким контрастом', marble:'Светлые мраморные фигуры', metal:'Полированные металлические фигуры', neon:'Фигуры с ярким неоновым контуром' })[variant] || 'Меняет внешний вид шахматных фигур';
    return ({ move:'Световой импульс отмечает завершённый ход', capture:'Вспышка подчёркивает взятие фигуры', check:'Энергетический ореол появляется при шахе' })[variant] || 'Добавляет визуальный эффект партии';
  }
  if (gameType === 'checkers') {
    if (layer === 'theme') return ({ wood:'Холодная лазурно-мятная доска с тёмными бирюзовыми клетками', dark:'Строгая тёмная доска с высоким контрастом', marble:'Светлый камень с холодными прожилками', neon:'Тёмная доска с цианово-фиолетовым свечением' })[variant] || 'Меняет оформление шашечной доски';
    if (layer === 'elements') return ({ wood:'Глазурованные бирюзовые и терракотовые шашки с керамическим блеском', marble:'Гладкие каменные шашки с прожилками', metal:'Полированные металлические шашки', neon:'Шашки с ярким неоновым контуром' })[variant] || 'Меняет внешний вид шашек';
    return ({ move:'Световой след подчёркивает обычный ход', capture:'Короткий ударный всплеск отмечает взятие', promotion:'Коронная вспышка появляется при превращении в дамку' })[variant] || 'Добавляет визуальный эффект партии';
  }
  if (gameType === 'domino') {
    if (layer === 'theme') return ({ felt:'Глубокое бордовое сукно с винной кромкой и мягкой клубной глубиной', midnight:'Тёмно-синий стол с холодной подсветкой и спокойным клубным настроением', walnut:'Тёплый ореховый стол с цельной древесной игровой поверхностью и живой фактурой', neon:'Глубокий тёмный стол с цианово-фиолетовой неоновой кромкой' })[variant] || 'Меняет оформление игрового стола';
    if (layer === 'elements') return ({ ivory:'Тёплые янтарные костяшки с тёмными точками и мягким объёмным блеском', ebony:'Чёрные матовые костяшки классической формы со светлыми точками', marble:'Мраморные костяшки с натуральной минеральной фактурой и чёткими точками', neon:'Тёмные костяшки с яркими неоновыми точками и тонким контуром' })[variant] || 'Меняет внешний вид костяшек';
    return ({ 'precision-drop':'Яркий акцент в момент точного хода', 'stock-pulse':'Эффектный выход костяшки из запаса', 'chain-finale':'Финал с каскадом падающих костяшек' })[variant] || 'Добавляет визуальный эффект партии';
  }
  if (layer === 'theme') {
    return ({ classic:'Тёплая классическая доска', dark:'Строгое тёмное оформление', glass:'Объёмное стеклянное поле', neon:'Неоновая сетка и свечение' })[variant] || 'Меняет фон и сетку поля';
  }
  if (layer === 'elements') {
    return ({ classic:'Чистые классические X и O', '3d':'Объёмные светлые знаки', metal:'Золотой X и стальной O', neon:'Светящиеся неоновые знаки' })[variant] || 'Меняет крестики и нолики';
  }
  const effect = normalizeEffectVariant(variant);
  return ({
    impact:'Знак появляется с коротким ударом и вспышкой',
    sparks:'Вокруг нового знака разлетается короткая вспышка искр',
    wave:'От самого нового знака расходятся две световые волны',
  })[effect] || 'Добавляет визуальный эффект каждому ходу';
}

function normalizeEffectVariant(variant){
  return ({ sign:'impact', 'winning-line':'sparks', 'move-pulse':'wave', 'strike-through':'wave' })[variant] || variant;
}

function checkersMiniBoardMarkup(withStartingPieces = true){
  return `<i class="store-v2-mini-checkers-board" aria-hidden="true">${Array.from({ length:64 }, (_, cell) => {
    const row = Math.floor(cell / 8);
    const col = cell % 8;
    const dark = (row + col) % 2 === 1;
    const hasPiece = withStartingPieces && dark && (row < 3 || row > 4);
    const pieceClass = row < 3 ? 'black' : 'white';
    return `<span class="${dark ? 'dark' : 'light'}">${hasPiece ? `<i class="${pieceClass}"></i>` : ''}</span>`;
  }).join('')}</i>`;
}

function checkersEffectPreviewMarkup(variant){
  const cells = Array.from({ length:64 }, (_, cell) => `<span class="${(Math.floor(cell / 8) + cell % 8) % 2 ? 'dark' : 'light'}"></span>`).join('');
  return `<i class="store-v2-mini-checkers-effect checkers-store-fx-${variant}" data-checkers-effect-preview aria-hidden="true"><span class="checkers-fx-board">${cells}</span><b class="checkers-fx-piece from"></b><b class="checkers-fx-piece target"></b><em class="checkers-fx-impact"></em><u class="checkers-fx-crown">♛</u><small class="checkers-fx-play">▶</small></i>`;
}

function gameCosmeticPreview(gameType, layer, variant, label = ''){
  const safeLayer = ['theme','elements','effect'].includes(String(layer)) ? String(layer) : 'theme';
  const normalizedVariant = gameType === 'tictactoe' && safeLayer === 'effect' ? normalizeEffectVariant(String(variant || 'base')) : String(variant || 'base');
  const safeVariant = normalizedVariant.replace(/[^a-z0-9-]/g, '');
  let content = '';
  if (gameType === 'chess') {
    if (safeLayer === 'theme') {
      const pieces = ['♜','','','♚','','♟','', '', '', '', '♙','', '♔','','','♖'];
      content = `<i class="store-v2-mini-chess-board">${pieces.map(piece => `<span>${piece ? `<b>${piece}</b>` : ''}</span>`).join('')}</i>`;
    } else if (safeLayer === 'elements') {
      content = '<i class="store-v2-mini-chess-pieces"><span>♚</span><span>♞</span><span>♟</span></i>';
    } else {
      content = `<i class="store-v2-mini-chess-effect chess-store-fx-${safeVariant}" aria-hidden="true"><span>♞</span><b></b><em></em></i>`;
    }
  } else if (gameType === 'checkers') {
    if (safeLayer === 'theme') {
      content = checkersMiniBoardMarkup(true);
    } else if (safeLayer === 'elements') {
      content = '<i class="store-v2-mini-checkers-pieces" aria-hidden="true"><span class="black"></span><span class="white"></span><span class="king"><b>♛</b></span></i>';
    } else {
      content = checkersEffectPreviewMarkup(safeVariant);
    }
  } else if (gameType === 'domino') {
    content = dominoPreviewMarkup(safeLayer, safeVariant);
  } else if (safeLayer === 'theme') {
    const marks = ['✕','','○','','○','','✕','','✕'];
    content = `<i class="store-v2-mini-board">${marks.map(mark => `<span>${mark ? `<b>${mark}</b>` : ''}</span>`).join('')}</i>`;
  } else if (safeLayer === 'elements') {
    content = '<i class="store-v2-mini-marks"><span>✕</span><span>○</span></i>';
  } else {
    content = `<i class="store-v2-mini-effect"><span class="ttt-mark ttt-effect-mark ttt-fx-${safeVariant}" aria-hidden="true">✕</span></i>`;
  }
  return `<div class="store-v2-game-preview" data-game-type="${escapeAttr(gameType)}" data-cosmetic-layer="${safeLayer}" data-cosmetic-variant="${safeVariant}" role="img" aria-label="${escapeAttr(label)}">${content}</div>`;
}

function gameBundlesFromSnapshot(snapshot = storeState){
  const gameBundles = Array.isArray(snapshot?.bundles?.game_bundles) ? snapshot.bundles.game_bundles.filter(Boolean) : [];
  if (gameBundles.length) return gameBundles;
  return [snapshot?.bundles?.tictactoe_bundle, snapshot?.bundles?.checkers_bundle].filter(Boolean);
}

function bundleGameType(bundle){
  return String(bundle?.game_type || bundle?.subcategory || '');
}

function bundleMemberOffers(bundle, snapshot = storeState){
  const gameType = bundleGameType(bundle);
  const catalogs = snapshot?.games?.catalogs && typeof snapshot.games.catalogs === 'object' ? snapshot.games.catalogs : {};
  const catalog = catalogs[gameType];
  if (!catalog || typeof catalog !== 'object') return [];
  const memberIds = new Set(Array.isArray(bundle?.item_ids) ? bundle.item_ids.map(String) : []);
  return [catalog.themes, catalog.elements, catalog.effects]
    .flatMap(group => Array.isArray(group) ? group : [])
    .filter(offer => memberIds.has(String(offer?.item_ids?.[0] || '')));
}

function bundlePresentation(gameType){
  if (gameType === 'checkers') {
    return {
      gameTitle:'Шашки',
      description:'Неоновая доска, неоновые шашки и все три эффекта в одном комплекте.',
      labels:{ theme:'Доска', elements:'Шашки', effect:'Эффект' },
    };
  }
  return {
    gameTitle:'Крестики-нолики',
    description:'Лучшее оформление игры и все три эффекта в одном комплекте.',
    labels:{ theme:'Поле', elements:'Знаки', effect:'Эффект' },
  };
}

function bundleMemberLabel(gameType, offer){
  const layer = String(offer?.metadata?.layer || '');
  const presentation = bundlePresentation(gameType);
  return presentation.labels[layer] || 'Эффект';
}

function renderBundleMemberStorePreview(gameType, layer, variant, name, owned, sheet = false){
  const preview = gameCosmeticPreview(gameType, layer, variant, name);
  if (gameType !== 'checkers') return preview;
  return `
    <div
      class="store-v2-bundle-native-store-viewport ${sheet ? 'is-sheet' : ''}"
      data-store-v2-native-preview-viewport
      data-store-v2-native-preview-layer="${escapeAttr(layer)}"
    >
      <div
        class="store-v2-game-product store-v2-bundle-native-store-product ${owned ? 'owned' : ''}"
        data-store-game-product="checkers"
        data-store-v2-native-preview-source
        aria-hidden="true"
      >
        ${preview}
      </div>
    </div>
  `;
}

function renderBundleMembers(bundle, sheet = false){
  const gameType = bundleGameType(bundle) || 'tictactoe';
  const missing = new Set(Array.isArray(bundle?.missing_item_ids) ? bundle.missing_item_ids.map(String) : []);
  const members = bundleMemberOffers(bundle);
  return `
    <div class="store-v2-bundle-reference-members ${sheet ? 'is-sheet' : ''}">
      ${members.map(offer => {
        const itemId = String(offer?.item_ids?.[0] || '');
        const owned = itemId !== '' && !missing.has(itemId);
        const layer = String(offer?.metadata?.layer || 'theme');
        const variant = String(offer?.metadata?.variant || 'base');
        const name = String(offer?.display_name || itemId || 'Предмет');
        return `
          <div
            class="store-v2-bundle-reference-member layer-${escapeAttr(layer)} ${owned ? 'owned' : ''}"
            data-store-bundle-member-game="${escapeAttr(gameType)}"
            data-store-bundle-member-layer="${escapeAttr(layer)}"
          >
            <div class="store-v2-bundle-reference-preview">
              ${renderBundleMemberStorePreview(gameType, layer, variant, name, owned, sheet)}
              ${owned ? '<i class="store-v2-bundle-owned-check" aria-label="Уже в коллекции">✓</i>' : ''}
            </div>
            <div class="store-v2-bundle-reference-member-copy">
              <span>${escapeHtml(bundleMemberLabel(gameType, offer))}</span>
              <strong>${escapeHtml(name)}</strong>
            </div>
          </div>
        `;
      }).join('')}
    </div>
  `;
}

function renderBundlesTab(){
  const bundles = gameBundlesFromSnapshot().filter(bundle => BUNDLE_REFERENCE_GAMES.includes(bundleGameType(bundle)));
  if (!bundles.length) return emptyState('Наборы пока недоступны');

  const availableGames = bundles.map(bundle => bundleGameType(bundle)).filter(Boolean);
  if (!availableGames.includes(activeBundleGame)) activeBundleGame = availableGames[0] || 'tictactoe';
  return `
    <div class="store-v2-bundle-game-picker" aria-label="Выберите игру">
      <div class="store-v2-bundle-game-picker-track" role="tablist">
        ${bundles.map(bundle => {
          const gameType = bundleGameType(bundle);
          const presentation = bundlePresentation(gameType);
          const active = gameType === activeBundleGame;
          return `
            <button
              class="store-v2-bundle-game-option ${active ? 'active' : ''}"
              type="button"
              role="tab"
              aria-selected="${active ? 'true' : 'false'}"
              data-store-v2-bundle-game="${escapeAttr(gameType)}"
            >
              <span>${escapeHtml(presentation.gameTitle)}</span>
            </button>
          `;
        }).join('')}
      </div>
    </div>
    <div class="store-v2-bundle-reference-list" data-store-v2-bundle-stage="${escapeAttr(activeBundleGame)}">
      ${bundles.map(bundle => {
        const gameType = bundleGameType(bundle);
        const active = gameType === activeBundleGame;
        return `
          <div
            class="store-v2-bundle-reference-panel ${active ? 'active' : ''}"
            data-store-v2-bundle-panel="${escapeAttr(gameType)}"
            aria-hidden="${active ? 'false' : 'true'}"
          >
            ${renderGameBundle(bundle)}
          </div>
        `;
      }).join('')}
    </div>
  `;
}

function renderGameBundle(bundle){
  const gameType = bundleGameType(bundle) || 'tictactoe';
  const itemCount = Array.isArray(bundle?.item_ids) ? bundle.item_ids.length : 0;
  const missing = Number(bundle?.missing_count || 0);
  const owned = Number(bundle?.owned_count || 0);
  const allOwned = Boolean(bundle?.already_owned);
  const currentPrice = Number(bundle?.price_coins || 0);
  const regularMissingPrice = regularBundlePrice(bundle);
  const regularFullPrice = Number(bundle?.regular_price_coins || regularMissingPrice || 0);
  const saving = Math.max(0, regularMissingPrice - currentPrice);
  const title = String(bundle?.display_name || 'Неоновый комплект');
  const presentation = bundlePresentation(gameType);
  const progress = allOwned
    ? `${itemCount || 5} из ${itemCount || 5} уже в коллекции`
    : (owned > 0 ? `У вас ${owned} из ${itemCount || 5} · осталось ${missing}` : `${itemCount || 5} предметов · навсегда`);
  return `
    <article class="store-v2-bundle-reference ${allOwned ? 'owned' : ''}" data-store-bundle-game="${escapeAttr(gameType)}">
      <div class="store-v2-bundle-reference-topline">
        <span>${escapeHtml(presentation.gameTitle)}</span>
        <b>Премиум-набор</b>
      </div>
      <div class="store-v2-bundle-reference-hero">
        <div>
          <h2>${escapeHtml(title)}</h2>
          <p>${escapeHtml(presentation.description)}</p>
        </div>
        <em>${itemCount || 5}</em>
      </div>
      ${renderBundleMembers(bundle)}
      <div class="store-v2-bundle-reference-progress">
        <span>${escapeHtml(progress)}</span>
        <i><b style="width:${Math.max(0, Math.min(100, ((itemCount || 5) ? owned / (itemCount || 5) * 100 : 0)))}%"></b></i>
      </div>
      ${allOwned ? '' : `
        <div class="store-v2-bundle-reference-pricing">
          <div>
            <span>${owned > 0 ? 'За оставшиеся предметы' : 'Цена набора'}</span>
            <strong>${formatNumber(currentPrice)} <small>коинов</small></strong>
          </div>
          <div class="store-v2-bundle-reference-saving">
            ${regularMissingPrice > currentPrice ? `<s>${formatNumber(regularMissingPrice)}</s><b>Экономия ${formatNumber(saving)}</b>` : ''}
            ${owned === 0 && regularFullPrice > 0 ? `<small>По отдельности ${formatNumber(regularFullPrice)}</small>` : ''}
          </div>
        </div>
        <button class="store-v2-bundle-reference-buy" data-store-v2-buy="${escapeAttr(bundle?.offer_id || '')}" type="button">
          <span>Посмотреть и купить</span>
          <b>→</b>
        </button>
        <small class="store-v2-bundle-reference-note">Покупка добавляет предметы в коллекцию, но ничего не выбирает автоматически.</small>
      `}
    </article>
  `;
}

function regularBundlePrice(bundle){
  return Number(bundle?.regular_missing_price_coins || bundle?.regular_price_coins || 0);
}

function renderBundleConfirmVisual(bundle){
  const owned = Number(bundle?.owned_count || 0);
  const missing = Number(bundle?.missing_count || 0);
  const itemCount = Array.isArray(bundle?.item_ids) ? bundle.item_ids.length : 0;
  const gameType = bundleGameType(bundle) || 'tictactoe';
  const members = gameType === 'checkers'
    ? '<div class="store-v2-bundle-confirm-clone-host" data-store-v2-bundle-confirm-clone></div>'
    : renderBundleMembers(bundle, true);
  return `
    <div class="store-v2-bundle-confirm-reference">
      <div class="store-v2-bundle-confirm-reference-head">
        <span>В составе</span>
        <b>${owned > 0 ? `${missing} осталось · ${owned} уже есть` : `${itemCount || 5} предметов`}</b>
      </div>
      ${members}
      <p>Оплачиваются только недостающие предметы. После покупки они появятся в коллекции без автоматического выбора.</p>
    </div>
  `;
}

function renderBundleConfirmPricing(bundle){
  const current = Number(bundle?.price_coins || 0);
  const regular = regularBundlePrice(bundle);
  const saving = Math.max(0, regular - current);
  return `
    <div class="store-v2-bundle-confirm-price">
      <div><span>По отдельности</span><s>${formatNumber(regular)}</s></div>
      <div><span>К оплате</span><strong>${formatNumber(current)} коинов</strong></div>
      ${saving > 0 ? `<p>Вы экономите ${formatNumber(saving)} коинов</p>` : ''}
    </div>
  `;
}

function emptyState(title){
  return `<div class="store-v2-empty"><strong>${escapeHtml(title)}</strong></div>`;
}

function bindStoreEvents(){
  const root = currentRoot();
  if (!root) return;
  root.querySelectorAll('[data-store-v2-tab]').forEach(button => {
    button.addEventListener('click', () => activateStoreTab(String(button.dataset.storeV2Tab || 'profile')));
  });
  bindPanelEvents(root);
}

function bindPanelEvents(root){
  root.querySelectorAll('[data-store-v2-game]:not([data-store-v2-bundle-game])').forEach(button => {
    button.addEventListener('click', () => activateGameCatalog(String(button.dataset.storeV2Game || '')));
  });
  root.querySelectorAll('[data-store-v2-bundle-game]').forEach(button => {
    button.addEventListener('click', () => activateBundleGame(String(button.dataset.storeV2BundleGame || '')));
  });
  root.querySelectorAll('[data-store-v2-buy]').forEach(button => {
    button.addEventListener('click', () => {
      const offer = findOffer(String(button.dataset.storeV2Buy || ''));
      if (!offer || offer.already_owned) return;
      haptic('light');
      openPurchaseConfirm(offer);
    });
  });
  root.querySelectorAll('[data-store-v2-equip]').forEach(button => {
    button.addEventListener('click', () => {
      const itemId = String(button.dataset.storeV2Equip || '');
      if (!itemId || button.disabled) return;
      void equipStoreItem(itemId);
    });
  });
  root.querySelectorAll('[data-store-v2-unequip]').forEach(button => {
    button.addEventListener('click', () => {
      const slot = String(button.dataset.storeV2Unequip || '');
      if (!slot || button.disabled) return;
      void unequipStoreSlot(slot);
    });
  });
}

function activateStoreTab(nextTab){
  if (!STORE_TABS.some(tab => tab.id === nextTab) || nextTab === activeTab) return;
  activeTab = nextTab;
  haptic('light');
  const root = currentRoot();
  if (!root) return;
  root.querySelectorAll('[data-store-v2-tab]').forEach(button => {
    const active = String(button.dataset.storeV2Tab || '') === activeTab;
    button.classList.toggle('active', active);
    button.setAttribute('aria-selected', active ? 'true' : 'false');
  });
  const panel = root.querySelector('[data-store-v2-panel]');
  if (!panel) return;
  panel.dataset.storeV2Panel = activeTab;
  panel.innerHTML = storeState ? renderActiveTab() : '<div class="store-v2-skeleton-grid" aria-hidden="true"><span></span><span></span><span></span><span></span></div>';
  bindPanelEvents(panel);
  scheduleBundlePreviewFit(panel);
}

function activateGameCatalog(gameType){
  const catalogs = storeState?.games?.catalogs || {};
  if (!gameType || !catalogs[gameType] || gameType === activeGameCatalog) return;
  activeGameCatalog = gameType;
  haptic('light');
  const root = currentRoot();
  const panel = root?.querySelector('[data-store-v2-panel="games"]');
  if (!panel) return;
  panel.innerHTML = renderGamesTab();
  bindPanelEvents(panel);
}

function activateBundleGame(gameType){
  const bundles = gameBundlesFromSnapshot().filter(bundle => BUNDLE_REFERENCE_GAMES.includes(bundleGameType(bundle)));
  if (!gameType || !bundles.some(bundle => bundleGameType(bundle) === gameType) || gameType === activeBundleGame) return;
  activeBundleGame = gameType;
  haptic('light');
  const root = currentRoot();
  const panel = root?.querySelector('[data-store-v2-panel="bundles"]');
  if (!panel) return;

  panel.querySelectorAll('[data-store-v2-bundle-game]').forEach(button => {
    const active = String(button.dataset.storeV2BundleGame || '') === activeBundleGame;
    button.classList.toggle('active', active);
    button.setAttribute('aria-selected', active ? 'true' : 'false');
  });

  const stage = panel.querySelector('[data-store-v2-bundle-stage]');
  if (stage instanceof HTMLElement) stage.dataset.storeV2BundleStage = activeBundleGame;

  panel.querySelectorAll('[data-store-v2-bundle-panel]').forEach(bundlePanel => {
    if (!(bundlePanel instanceof HTMLElement)) return;
    const active = String(bundlePanel.dataset.storeV2BundlePanel || '') === activeBundleGame;
    bundlePanel.classList.toggle('active', active);
    bundlePanel.setAttribute('aria-hidden', active ? 'false' : 'true');
  });
  scheduleBundlePreviewFit(panel);
}

function hydrateCheckersBundleConfirmFromVisibleCard(){
  const sheet = document.getElementById('sheet');
  const target = sheet?.querySelector('[data-store-v2-bundle-confirm-clone]');
  const root = currentRoot();
  const source = root?.querySelector(
    '[data-store-v2-bundle-panel="checkers"] .store-v2-bundle-reference-members'
  );
  if (!(target instanceof HTMLElement) || !(source instanceof HTMLElement)) return false;

  const rect = source.getBoundingClientRect();
  if (!rect.width || !rect.height) return false;

  const clone = source.cloneNode(true);
  if (!(clone instanceof HTMLElement)) return false;
  clone.classList.remove('is-sheet');
  clone.classList.add('store-v2-bundle-confirm-cloned-members');
  clone.dataset.mgwCheckersFrozenSnapshot = '1';
  clone.querySelectorAll('[id]').forEach(node => node.removeAttribute('id'));
  clone.querySelectorAll('.is-previewing,.is-playing').forEach(node => {
    node.classList.remove('is-previewing','is-playing');
  });
  clone.querySelectorAll('[data-mgw-checkers-fx-observed],[data-mgw-checkers-fx-visible],[data-mgw-checkers-fx-busy]').forEach(node => {
    node.removeAttribute('data-mgw-checkers-fx-observed');
    node.removeAttribute('data-mgw-checkers-fx-visible');
    node.removeAttribute('data-mgw-checkers-fx-busy');
  });

  const sourceWidth = Math.round(rect.width * 100) / 100;
  const sourceHeight = Math.round(rect.height * 100) / 100;
  clone.style.width = `${sourceWidth}px`;
  clone.style.maxWidth = 'none';
  clone.style.position = 'absolute';
  clone.style.left = '0';
  clone.style.top = '0';
  clone.style.transformOrigin = '0 0';

  target.dataset.storeV2BundleConfirmSnapshot = 'checkers';
  target.style.position = 'relative';
  target.style.overflow = 'hidden';
  target.replaceChildren(clone);

  const fitSnapshot = () => {
    const available = target.clientWidth;
    if (!available) return;
    const scale = Math.min(1, available / sourceWidth);
    clone.style.transform = `scale(${scale})`;
    target.style.height = `${Math.ceil(sourceHeight * scale)}px`;
  };

  fitSnapshot();
  globalThis.requestAnimationFrame?.(() => {
    fitSnapshot();
    globalThis.requestAnimationFrame?.(fitSnapshot);
  });
  return true;
}

function fitBundleNativePreviews(root){
  if (!(root instanceof HTMLElement)) return;
  root.querySelectorAll('[data-store-v2-native-preview-viewport]').forEach(viewport => {
    if (!(viewport instanceof HTMLElement)) return;
    const source = viewport.querySelector('[data-store-v2-native-preview-source]');
    if (!(source instanceof HTMLElement)) return;
    const viewportWidth = viewport.clientWidth;
    const viewportHeight = viewport.clientHeight;
    const sourceWidth = source.offsetWidth;
    const sourceHeight = source.offsetHeight;
    if (!viewportWidth || !viewportHeight || !sourceWidth || !sourceHeight) return;
    const scale = Math.max(.35, Math.min(1.16, viewportWidth / sourceWidth, viewportHeight / sourceHeight));
    source.style.setProperty('--mgw-bundle-native-scale', scale.toFixed(4));
  });
}

function scheduleBundlePreviewFit(root){
  if (!(root instanceof HTMLElement)) return;
  const fit = () => fitBundleNativePreviews(root);
  queueMicrotask(fit);
  if (typeof globalThis.requestAnimationFrame === 'function') {
    globalThis.requestAnimationFrame(() => {
      fit();
      globalThis.requestAnimationFrame(fit);
    });
  } else {
    globalThis.setTimeout(fit, 0);
  }
  globalThis.setTimeout(fit, 90);
}

function findOffer(offerId){
  return offersFromSnapshot(storeState).find(item => String(item?.offer_id || '') === offerId) || null;
}

function offersFromSnapshot(snapshot){
  const catalogs = snapshot?.games?.catalogs && typeof snapshot.games.catalogs === 'object' ? Object.values(snapshot.games.catalogs) : [];
  const gameOffers = catalogs.flatMap(catalog => [catalog?.themes, catalog?.elements, catalog?.effects].flatMap(group => Array.isArray(group) ? group : []));
  const offers = [
    ...(Array.isArray(snapshot?.profile?.avatars) ? snapshot.profile.avatars : []),
    ...(Array.isArray(snapshot?.profile?.name_colors) ? snapshot.profile.name_colors : []),
    ...gameOffers,
  ];
  const avatarBundle = snapshot?.bundles?.avatar_bundle;
  if (avatarBundle) offers.push(avatarBundle);
  gameBundlesFromSnapshot(snapshot).forEach(bundle => {
    if (!offers.some(candidate => String(candidate?.offer_id || '') === String(bundle?.offer_id || ''))) offers.push(bundle);
  });
  return offers;
}

function isKnownSelectableSlot(slot){
  const normalized = String(slot || '');
  if (!normalized || !storeState) return false;
  return offersFromSnapshot(storeState).some(candidate => {
    if (String(candidate?.equip_slot || '') !== normalized) return false;
    const itemType = String(candidate?.item_type || '');
    const itemFamily = String(candidate?.item_family || '');
    if (itemType === 'game' && normalized.startsWith('game_')) return true;
    return itemType === 'profile' && itemFamily === 'name_color' && normalized === 'profile_name_color';
  });
}

function purchasedItemIds(offer){
  const missing = Array.isArray(offer?.missing_item_ids) ? offer.missing_item_ids.map(String).filter(Boolean) : [];
  if (missing.length) return missing;
  return Array.isArray(offer?.item_ids) ? offer.item_ids.map(String).filter(Boolean) : [];
}

function applyOptimisticPurchase(offer){
  if (!storeState) return;
  const purchasedIds = purchasedItemIds(offer);
  const purchasedSet = new Set(purchasedIds);
  const next = cloneObject(storeState);
  const price = Math.max(0, Number(offer?.price_coins || 0));
  next.balance = Math.max(0, Number(next.balance || 0) - price);

  const offers = offersFromSnapshot(next);
  offers.forEach(candidate => {
    const ids = Array.isArray(candidate?.item_ids) ? candidate.item_ids.map(String) : [];
    if (!ids.some(itemId => purchasedSet.has(itemId))) return;
    const previousMissing = Array.isArray(candidate.missing_item_ids) ? candidate.missing_item_ids.map(String) : ids;
    const remaining = previousMissing.filter(itemId => !purchasedSet.has(itemId));
    candidate.missing_item_ids = remaining;
    candidate.missing_count = remaining.length;
    candidate.owned_count = Math.max(0, ids.length - remaining.length);
    candidate.already_owned = remaining.length === 0;
    candidate.purchasable = remaining.length > 0;
  });

  const bundles = [next?.bundles?.avatar_bundle, ...gameBundlesFromSnapshot(next)].filter(Boolean);
  const individualByItem = new Map();
  offers.filter(candidate => String(candidate?.offer_type || '') === 'item').forEach(candidate => {
    const itemId = String(candidate?.item_ids?.[0] || '');
    if (itemId) individualByItem.set(itemId, Number(candidate?.full_price_coins || candidate?.price_coins || 0));
  });
  bundles.forEach(bundle => {
    const members = Array.isArray(bundle.item_ids) ? bundle.item_ids.map(String) : [];
    const remaining = Array.isArray(bundle.missing_item_ids) ? bundle.missing_item_ids.map(String) : members;
    const regularMissing = remaining.reduce((total, itemId) => total + Number(individualByItem.get(itemId) || 0), 0);
    bundle.missing_item_ids = remaining;
    bundle.missing_count = remaining.length;
    bundle.owned_count = Math.max(0, members.length - remaining.length);
    bundle.already_owned = remaining.length === 0;
    bundle.purchasable = remaining.length > 0;
    bundle.regular_missing_price_coins = regularMissing;
    bundle.price_coins = remaining.length ? Math.min(Number(bundle.full_price_coins || 0), regularMissing) : 0;
  });

  const inventoryItems = Array.isArray(next?.inventory?.items) ? next.inventory.items : [];
  purchasedIds.forEach(itemId => {
    if (inventoryItems.some(item => String(item?.item_id || '') === itemId)) return;
    const sourceOffer = offers.find(candidate => String(candidate?.item_ids?.[0] || '') === itemId) || {};
    inventoryItems.push({
      item_id:itemId,
      item_type:String(sourceOffer?.item_type || ''),
      item_family:String(sourceOffer?.item_family || ''),
      equip_slot:String(sourceOffer?.equip_slot || ''),
      metadata:cloneObject(sourceOffer?.metadata || {}),
      store_product:true,
      equipped:false,
    });
  });

  storeState = next;
  if (state.user && typeof state.user === 'object') {
    state.user = { ...state.user, balance:Number(next.balance || 0) };
    renderBalances(state.user);
  }
  if (state.profileInventory && typeof state.profileInventory === 'object' && Array.isArray(state.profileInventory.catalog)) {
    const profileInventory = cloneObject(state.profileInventory);
    profileInventory.catalog = profileInventory.catalog.map(item => (
      purchasedSet.has(String(item?.item_id || '')) ? { ...item, owned:true } : item
    ));
    state.profileInventory = profileInventory;
  }
}

function openPurchaseConfirm(offer){
  const token = purchaseToken();
  const isBundle = String(offer.offer_type || '') === 'bundle';
  const isAvatar = String(offer.item_family || '') === 'avatar';
  const isNameColor = String(offer.item_family || '') === 'name_color';
  const number = Number(offer.preview_number || 0);
  const itemId = Array.isArray(offer.item_ids) ? String(offer.item_ids[0] || '') : '';
  const balance = Number(storeState?.balance || 0);
  const price = Number(offer.price_coins || 0);
  const missing = Math.max(0, price - balance);
  const bundleGameType = String(offer?.game_type || offer?.subcategory || '');
  const title = isBundle
    ? String(offer.display_name || (bundleGameType === 'checkers' ? 'Неоновый комплект шашек' : 'Неоновый комплект'))
    : (isAvatar ? `Аватарка ${number}` : String(offer.display_name || (isNameColor ? 'Цвет имени' : 'Игровой предмет')));
  let visual;
  if (isBundle) {
    visual = renderBundleConfirmVisual(offer);
  } else if (isAvatar) {
    visual = `<div class="store-v2-confirm-avatar store-v2-avatar-preview" data-avatar-item-id="${escapeAttr(itemId)}" data-avatar-preview="${number}" role="img" aria-label="${escapeAttr(`Аватарка ${number}`)}"><span>${String(number).padStart(2,'0')}</span></div>`;
  } else if (isNameColor) {
    const nickname = String(state.mgwProfile?.nickname || state.user?.display_name || 'Игрок');
    visual = `<div class="profile-v2-name-color-preview-wrap"><strong data-name-color-item-id="${escapeAttr(itemId)}">${escapeHtml(nickname)}</strong></div>`;
  } else {
    const gameType = String(offer?.metadata?.game_type || offer?.subcategory || 'tictactoe');
    visual = `<div class="store-v2-confirm-game">${gameCosmeticPreview(gameType, offer?.metadata?.layer, offer?.metadata?.variant, title)}</div>`;
  }

  openSheet(`
    <div class="sheet-head"><div><h2>Подтвердить покупку</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="store-v2-confirm ${isBundle ? 'store-v2-confirm-bundle' : ''}">
      ${visual}
      <div class="store-v2-confirm-copy"><strong>${escapeHtml(title)}</strong></div>
      ${isBundle
        ? renderBundleConfirmPricing(offer)
        : `<div class="store-v2-confirm-price"><span>К оплате</span><strong>${formatNumber(price)} коинов</strong></div>`}
      <div class="store-v2-confirm-balance"><span>Останется</span><b>${formatNumber(Math.max(0, balance - price))}</b></div>
      <button class="btn primary full" id="storeV2ConfirmBuy" type="button" ${missing > 0 ? 'disabled' : ''}>${missing > 0 ? `Не хватает ${formatNumber(missing)}` : `Купить за ${formatNumber(price)}`}</button>
    </div>
  `);

  const sheetElement = document.getElementById('sheet');
  const confirmElement = sheetElement?.querySelector('.store-v2-confirm-bundle');
  if (sheetElement instanceof HTMLElement) sheetElement.scrollTop = 0;
  if (confirmElement instanceof HTMLElement) confirmElement.scrollTop = 0;

  if (isBundle && bundleGameType === 'checkers') {
    hydrateCheckersBundleConfirmFromVisibleCard();
  } else {
    scheduleBundlePreviewFit(sheetElement);
  }

  globalThis.requestAnimationFrame?.(() => {
    if (sheetElement instanceof HTMLElement) sheetElement.scrollTop = 0;
    if (confirmElement instanceof HTMLElement) confirmElement.scrollTop = 0;
  });

  document.getElementById('storeV2ConfirmBuy')?.addEventListener('click', event => {
    void purchaseOffer(offer, token, event.currentTarget);
  });
}

async function purchaseOffer(offer, token, button){
  if (purchaseBusy || !button || button.disabled) return;
  purchaseBusy = true;
  button.disabled = true;

  const previousStoreState = cloneObject(storeState);
  const previousUser = cloneObject(state.user);
  const previousProfileInventory = cloneObject(state.profileInventory);
  applyOptimisticPurchase(offer);
  closeSheet();
  renderStore();

  try {
    const result = await api.cosmeticStorePurchase(String(offer.offer_id || ''), token);
    applyStoreResponse(result);
    renderStore();
    haptic('success');
    const isAvatar = String(offer?.item_family || '') === 'avatar';
    const isNameColor = String(offer?.item_family || '') === 'name_color';
    toast(String(offer.offer_type || '') === 'bundle'
      ? 'Комплект добавлен в коллекцию.'
      : (isAvatar ? 'Аватарка добавлена в коллекцию.' : (isNameColor ? 'Цвет имени добавлен в коллекцию.' : 'Предмет добавлен в коллекцию.')));
  } catch (error) {
    storeState = previousStoreState;
    state.user = previousUser;
    state.profileInventory = previousProfileInventory;
    if (state.user) renderBalances(state.user);
    renderStore();
    haptic('error');
    toast(error?.message || 'Не удалось выполнить покупку.');
  } finally {
    purchaseBusy = false;
  }
}

async function equipStoreItem(itemId){
  if (equipBusy || !storeState) return;
  const previousStoreState = cloneObject(storeState);
  const next = cloneObject(storeState);
  const target = offersFromSnapshot(next).find(candidate => String(candidate?.item_ids?.[0] || '') === itemId);
  const slot = String(target?.equip_slot || '');
  if (!target || !target.already_owned || !slot || !isKnownSelectableSlot(slot)) return;

  equipBusy = true;
  offersFromSnapshot(next).forEach(candidate => {
    if (String(candidate?.equip_slot || '') === slot) candidate.equipped = String(candidate?.item_ids?.[0] || '') === itemId;
  });
  next.inventory ||= { items:[], equipped:{} };
  next.inventory.equipped ||= {};
  next.inventory.equipped[slot] = itemId;
  (next.inventory.items || []).forEach(item => {
    if (String(item?.equip_slot || '') === slot) item.equipped = String(item?.item_id || '') === itemId;
  });
  storeState = next;
  renderStore();

  try {
    const result = await api.cosmeticStoreEquip(itemId);
    applyStoreResponse(result);
    renderStore();
    haptic('success');
    toast('Предмет выбран.');
  } catch (error) {
    storeState = previousStoreState;
    renderStore();
    haptic('error');
    toast(error?.message || 'Не удалось выбрать предмет.');
  } finally {
    equipBusy = false;
  }
}

async function unequipStoreSlot(slot){
  if (equipBusy || !storeState || !isKnownSelectableSlot(slot)) return;
  const previousStoreState = cloneObject(storeState);
  const next = cloneObject(storeState);

  equipBusy = true;
  offersFromSnapshot(next).forEach(candidate => {
    if (String(candidate?.equip_slot || '') === slot) candidate.equipped = false;
  });
  next.inventory ||= { items:[], equipped:{} };
  next.inventory.equipped ||= {};
  delete next.inventory.equipped[slot];
  (next.inventory.items || []).forEach(item => {
    if (String(item?.equip_slot || '') === slot) item.equipped = false;
  });
  storeState = next;
  renderStore();

  try {
    const result = await api.cosmeticStoreUnequip(slot);
    applyStoreResponse(result);
    renderStore();
    haptic('success');
    toast('Оформление снято.');
  } catch (error) {
    storeState = previousStoreState;
    renderStore();
    haptic('error');
    toast(error?.message || 'Не удалось снять оформление.');
  } finally {
    equipBusy = false;
  }
}

function renderStoreHead(){
  if (storeSurface === 'tab') {
    return '<div class="page-head app-shell-page-head store-tab-head"><div><h1 class="page-title">Магазин</h1></div></div>';
  }
  return '<div class="sheet-head"><div><h2>Магазин</h2></div><button class="close" data-close-sheet type="button">×</button></div>';
}

function renderStoreSurface(markup){
  if (storeSurface === 'tab') {
    const host = document.getElementById('storeTabSurface');
    if (host) host.innerHTML = markup;
    return;
  }
  openSheet(markup);
}

function renderStoreError(error){
  renderStoreSurface(`
    ${renderStoreHead()}
    <div class="store-v2-empty error"><strong>Магазин временно недоступен</strong><span>${escapeHtml(error?.message || 'Попробуйте ещё раз.')}</span></div>
    <button class="btn ghost full" id="storeV2Retry" type="button">Повторить</button>
  `);
  currentRoot()?.querySelector('#storeV2Retry')?.addEventListener('click', retryStore);
}

function retryStore(){
  return storeSurface === 'tab' ? openStoreTab() : openStoreSheet();
}

function currentRoot(){
  return storeSurface === 'tab' ? document.getElementById('storeTabSurface') : document.getElementById('sheet');
}

function purchaseToken(){
  if (globalThis.crypto?.randomUUID) return `store:${globalThis.crypto.randomUUID()}`;
  return `store:${Date.now().toString(36)}:${Math.random().toString(36).slice(2, 14)}`;
}

function cloneObject(value){ return value && typeof value === 'object' ? JSON.parse(JSON.stringify(value)) : value; }
function formatNumber(value){ return Number(value || 0).toLocaleString('ru-RU'); }
function formatEuro(cents){ return new Intl.NumberFormat('ru-RU', { style:'currency', currency:'EUR' }).format(Number(cents || 0) / 100); }
function escapeHtml(value){
  return String(value ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;');
}
function escapeAttr(value){ return escapeHtml(value); }
