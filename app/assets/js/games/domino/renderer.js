import { toast } from '../../components/toast.js?v=41';

const ROUTE = createRoute();
let activeGameId = '';
let selectedTileId = '';
let lastAnimatedMove = -1;

export function renderDominoSurface({ game, me, container, onAction }){
  const gameId = String(game?.id || '');
  if (activeGameId !== gameId) {
    activeGameId = gameId;
    selectedTileId = '';
    lastAnimatedMove = -1;
  }

  const myId = String(me?.id || '');
  const myTurn = game?.status === 'active' && String(game?.turn || '') === myId;
  const hand = Array.isArray(game?.viewer_hand) ? game.viewer_hand : [];
  const selectedSides = Array.isArray(game?.playable_sides?.[selectedTileId])
    ? game.playable_sides[selectedTileId]
    : [];

  if (!hand.some(tile => String(tile?.id || '') === selectedTileId) || selectedSides.length === 0) {
    selectedTileId = '';
  }

  container.className = 'board domino-surface';
  container.dataset.gameType = 'domino';
  container.innerHTML = `
    <div class="domino-panel">
      ${eventBanner(game, myId, myTurn)}
      ${tableMarkup(game, selectedTileId)}
      ${handMarkup(game, hand, myTurn)}
      ${actionsMarkup(game, myTurn, selectedTileId)}
      ${finalMarkup(game)}
    </div>
  `;

  bindHand(container, game, me, myTurn, onAction);
  bindPlacementTargets(container, game, onAction);
  bindDraw(container, game, myTurn, onAction);
  animateLatest(container, game);
}

export function dominoMeta(game){
  const room = String(game?.room_name || 'Игра');
  const bet = Number(game?.bet || 0);
  return `${room} · ${bet} коинов · классическое домино`;
}

export function dominoPlayerMark(player){
  const count = Number(player?.tile_count || 0);
  return `${count} ${declension(count, 'костяшка', 'костяшки', 'костяшек')}`;
}

export function dominoStatus(game, me){
  if (game?.status === 'finished') {
    const winnerId = String(game?.winner_id || '');
    if (!winnerId) return 'Ничья';
    return winnerId === String(me?.id || '') ? 'Победа' : 'Поражение';
  }
  const myTurn = String(game?.turn || '') === String(me?.id || '');
  if (myTurn && game?.can_draw) return 'Нет хода — доберите';
  return myTurn ? 'Ваш ход' : 'Ход соперника';
}

function tableMarkup(game, selectedId){
  const chain = Array.isArray(game?.chain) ? game.chain : [];
  const layout = layoutChain(chain);
  const lastAction = game?.last_action || {};
  const density = chain.length <= 7 ? 'short' : (chain.length <= 16 ? 'medium' : 'long');

  const tiles = chain.map((item, index) => {
    const slot = layout.slots[index] || ROUTE[Math.min(index, ROUTE.length - 1)];
    const isLatest = String(lastAction?.type || '') === 'play'
      && Number(item?.move_number || -1) === Number(game?.move_count || -2);
    const isDouble = Number(item?.left) === Number(item?.right);
    return `
      <div class="domino-chain-slot ${slot.vertical ? 'vertical' : 'horizontal'} ${isDouble ? 'is-double' : ''} ${isLatest ? 'latest' : ''}"
        style="--domino-x:${slot.x}%;--domino-y:${slot.y}%;--domino-rotation:${slot.rotation}deg"
        data-domino-chain-tile="${escapeHtml(String(item?.tile || ''))}">
        ${tileMarkup(Number(item?.left || 0), Number(item?.right || 0), {
          vertical:slot.vertical,
          double:isDouble,
          compact:true,
          title:`${item?.left ?? 0}–${item?.right ?? 0}`,
        })}
      </div>
    `;
  }).join('');

  const leftEnd = Number(game?.open_left ?? 0);
  const rightEnd = Number(game?.open_right ?? 0);
  const selectedSides = selectedId && Array.isArray(game?.playable_sides?.[selectedId])
    ? game.playable_sides[selectedId]
    : [];

  const targets = selectedId
    ? [
        selectedSides.includes('left') && layout.leftTarget
          ? placementTargetMarkup(selectedId, 'left', leftEnd, layout.leftTarget)
          : '',
        selectedSides.includes('right') && layout.rightTarget
          ? placementTargetMarkup(selectedId, 'right', rightEnd, layout.rightTarget)
          : '',
      ].join('')
    : '';

  const caption = selectedId
    ? 'Нажмите на подсвеченное место слева или справа.'
    : `Открытые концы: <strong>${leftEnd}</strong> и <strong>${rightEnd}</strong>`;

  return `
    <div class="domino-table" data-chain-density="${density}">
      <div class="domino-table-topline">
        <div class="domino-opponent-back"><i></i><span>${Number(game?.opponent_tile_count || 0)}</span></div>
        <div class="domino-stock-count"><i></i><span>Запас: ${Number(game?.stock_count || 0)}</span></div>
      </div>
      <div class="domino-chain-area">
        ${tiles}
        ${targets}
      </div>
      <div class="domino-table-caption">${caption}</div>
    </div>
  `;
}

function handMarkup(game, hand, myTurn){
  const playable = game?.playable_sides || {};
  const disabled = game?.status !== 'active' || !myTurn;
  const density = hand.length <= 7 ? 'roomy' : (hand.length <= 10 ? 'compact' : 'tight');

  return `
    <div class="domino-hand-section">
      <div class="domino-hand-title"><span>Ваши костяшки</span><strong>${hand.length}</strong></div>
      <div class="domino-hand ${density}" role="list" style="--domino-hand-count:${Math.max(1, hand.length)}">
        ${hand.map(tile => {
          const id = String(tile?.id || '');
          const legal = Array.isArray(playable[id]) && playable[id].length > 0;
          return `<button class="domino-hand-tile ${legal ? 'playable' : ''} ${selectedTileId === id ? 'selected' : ''}" data-domino-tile="${escapeHtml(id)}" type="button" ${disabled ? 'disabled' : ''} aria-pressed="${selectedTileId === id ? 'true' : 'false'}" aria-label="Костяшка ${Number(tile?.a || 0)}–${Number(tile?.b || 0)}">${tileMarkup(Number(tile?.a || 0), Number(tile?.b || 0), {double:Boolean(tile?.double), title:id})}</button>`;
        }).join('')}
      </div>
    </div>
  `;
}

function actionsMarkup(game, myTurn, selectedId){
  if (game?.status !== 'active') return '';
  if (!myTurn) return '<div class="domino-action-note">Ожидаем ход соперника.</div>';
  if (game?.can_draw) {
    return `
      <button class="btn primary full domino-draw-button" data-domino-draw type="button">
        Добрать из запаса
      </button>
    `;
  }
  if (selectedId) return '<div class="domino-action-note active">Теперь нажмите на место для костяшки на столе.</div>';
  return '<div class="domino-action-note">Подходящие костяшки отмечены аккуратной зелёной рамкой.</div>';
}

function finalMarkup(game){
  if (game?.status !== 'finished') return '';
  const mine = Number(game?.my_points ?? 0);
  const theirs = Number(game?.opponent_points ?? 0);
  const opponent = Array.isArray(game?.opponent_hand) ? game.opponent_hand : [];
  return `
    <div class="domino-final-card">
      <strong>Оставшиеся точки: ${mine} : ${theirs}</strong>
      <span>${game?.end_reason === 'blocked' ? 'Цепочка заблокирована — выигрывает меньшая сумма.' : 'Партия завершена.'}</span>
      ${opponent.length ? `<div class="domino-reveal"><em>Костяшки соперника</em><div>${opponent.map(tile => tileMarkup(Number(tile.a), Number(tile.b), {double:Boolean(tile.double), compact:true})).join('')}</div></div>` : ''}
    </div>
  `;
}

function eventBanner(game, myId, myTurn){
  if (game?.status === 'finished') return '<div class="domino-event-banner finished">Партия завершена — подсчитываем оставшиеся точки</div>';
  const action = game?.last_action || {};
  const actorMe = String(action?.player_id || '') === myId;
  const type = String(action?.type || '');

  if (type === 'start') {
    const [a,b] = parseTileId(String(action?.tile || game?.start_tile || '0-0'));
    return `<div class="domino-event-banner start">Стартовая костяшка ${a}–${b} уже на столе</div>`;
  }
  if (type === 'draw') {
    const count = Number(action?.drawn_count || 0);
    return `<div class="domino-event-banner draw">${actorMe ? 'Вы добрали' : 'Соперник добрал'} ${count} ${declension(count, 'костяшку', 'костяшки', 'костяшек')}</div>`;
  }
  if (type === 'pass') {
    return `<div class="domino-event-banner pass">${actorMe ? 'У вас' : 'У соперника'} нет хода — пропуск</div>`;
  }
  if (type === 'play') {
    const [a,b] = parseTileId(String(action?.tile || '0-0'));
    return `<div class="domino-event-banner play">${actorMe ? 'Вы поставили' : 'Соперник поставил'} ${a}–${b}</div>`;
  }
  return myTurn
    ? '<div class="domino-event-banner your-turn">Ваш ход — выберите костяшку</div>'
    : '<div class="domino-event-banner opponent">Ход соперника</div>';
}

function bindHand(container, game, me, myTurn, onAction){
  container.querySelectorAll('[data-domino-tile]').forEach(button => button.addEventListener('click', () => {
    if (!myTurn || game?.status !== 'active' || container.classList.contains('is-submitting')) return;
    const tileId = String(button.dataset.dominoTile || '');
    const sides = Array.isArray(game?.playable_sides?.[tileId]) ? game.playable_sides[tileId] : [];

    if (sides.length === 0) {
      button.classList.remove('invalid');
      void button.offsetWidth;
      button.classList.add('invalid');
      toast('Эта костяшка не подходит к открытым концам.');
      return;
    }

    selectedTileId = selectedTileId === tileId ? '' : tileId;
    renderDominoSurface({ game, me, container, onAction });
  }));
}

function bindPlacementTargets(container, game, onAction){
  container.querySelectorAll('[data-domino-side]').forEach(button => button.addEventListener('click', () => {
    if (!selectedTileId || container.classList.contains('is-submitting')) return;
    const side = String(button.dataset.dominoSide || '');
    const sides = Array.isArray(game?.playable_sides?.[selectedTileId]) ? game.playable_sides[selectedTileId] : [];
    if (!sides.includes(side)) return;

    container.classList.add('is-submitting');
    const tile = selectedTileId;
    selectedTileId = '';
    onAction?.({type:'play', tile, side});
  }));
}

function bindDraw(container, game, myTurn, onAction){
  container.querySelector('[data-domino-draw]')?.addEventListener('click', () => {
    if (!myTurn || !game?.can_draw || container.classList.contains('is-submitting')) return;
    container.classList.add('is-submitting');
    selectedTileId = '';
    onAction?.({type:'draw'});
  });
}

function animateLatest(container, game){
  const moveCount = Number(game?.move_count || 0);
  if (moveCount <= lastAnimatedMove) return;
  lastAnimatedMove = moveCount;
  const action = String(game?.last_action?.type || '');
  if (action === 'play') container.querySelector('.domino-chain-slot.latest')?.classList.add('animate-in');
  if (action === 'draw') container.querySelector('.domino-hand')?.classList.add('draw-pulse');
}

function placementTargetMarkup(tileId, side, openValue, slot){
  const [a, b] = parseTileId(tileId);
  let left = a;
  let right = b;

  if (side === 'left') {
    if (a === openValue) {
      left = b;
      right = a;
    }
  } else if (b === openValue) {
    left = b;
    right = a;
  }

  const isDouble = left === right;
  return `
    <button class="domino-placement-target ${slot.vertical ? 'vertical' : 'horizontal'} ${isDouble ? 'is-double' : ''}"
      data-domino-side="${side}"
      type="button"
      style="--domino-x:${slot.x}%;--domino-y:${slot.y}%;--domino-rotation:${slot.rotation}deg"
      aria-label="Поставить костяшку ${a}–${b} ${side === 'left' ? 'слева' : 'справа'}">
      ${tileMarkup(left, right, {vertical:slot.vertical, double:isDouble, compact:true, title:`${a}-${b}`})}
      <span>Сюда</span>
    </button>
  `;
}

function tileMarkup(a, b, options = {}){
  const vertical = Boolean(options.vertical);
  const double = Boolean(options.double);
  const classes = ['domino-tile', vertical ? 'vertical' : 'horizontal', double ? 'double' : '', options.compact ? 'compact' : ''].filter(Boolean).join(' ');
  return `<span class="${classes}" title="${escapeHtml(String(options.title || `${a}-${b}`))}">${halfMarkup(a)}${halfMarkup(b)}</span>`;
}

function halfMarkup(value){
  const active = new Set(pipPositions(value));
  return `<span class="domino-half">${Array.from({length:9}, (_, index) => `<i class="${active.has(index + 1) ? 'active' : ''}"></i>`).join('')}</span>`;
}

function pipPositions(value){
  return ({0:[],1:[5],2:[1,9],3:[1,5,9],4:[1,3,7,9],5:[1,3,5,7,9],6:[1,3,4,6,7,9]})[Number(value)] || [];
}

function parseTileId(tileId){
  const parts = String(tileId || '').split('-').map(Number);
  return [Number(parts[0] || 0), Number(parts[1] || 0)];
}

function layoutChain(chain){
  if (chain.length <= 7) {
    const count = Math.max(1, chain.length);
    const spacing = count <= 3 ? 18 : (count <= 5 ? 15 : 12.5);
    const startX = 50 - ((count - 1) * spacing) / 2;
    const slots = chain.map((_, index) => ({x:startX + index * spacing, y:50, vertical:false, rotation:0}));
    return {
      slots,
      leftTarget:{x:Math.max(5, startX - spacing), y:50, vertical:false, rotation:0},
      rightTarget:{x:Math.min(95, startX + count * spacing), y:50, vertical:false, rotation:0},
    };
  }

  const foundStartIndex = chain.findIndex(item => item?.is_start);
  const startIndex = foundStartIndex >= 0 ? foundStartIndex : 0;
  const before = startIndex;
  const after = Math.max(0, chain.length - startIndex - 1);
  const startSlot = Math.max(before, Math.min(13, ROUTE.length - 1 - after));
  const slots = chain.map((_, index) => {
    const routeIndex = startSlot + index - startIndex;
    return ROUTE[Math.max(0, Math.min(ROUTE.length - 1, routeIndex))];
  });
  const firstIndex = Math.max(0, startSlot - before);
  const lastIndex = Math.min(ROUTE.length - 1, startSlot + after);

  return {
    slots,
    leftTarget:firstIndex > 0 ? ROUTE[firstIndex - 1] : null,
    rightTarget:lastIndex < ROUTE.length - 1 ? ROUTE[lastIndex + 1] : null,
  };
}

function createRoute(){
  const route = [];
  for (let index = 0; index < 8; index++) route.push({x:12 + index * 10.8, y:20, vertical:false, rotation:0});
  route.push({x:88, y:34, vertical:true, rotation:0});
  route.push({x:88, y:48, vertical:true, rotation:0});
  for (let index = 0; index < 8; index++) route.push({x:77 - index * 9.3, y:48, vertical:false, rotation:180});
  route.push({x:12, y:63, vertical:true, rotation:180});
  route.push({x:12, y:78, vertical:true, rotation:180});
  for (let index = 0; index < 8; index++) route.push({x:23 + index * 9.3, y:78, vertical:false, rotation:0});
  return route;
}

function declension(value, one, few, many){
  const number = Math.abs(Number(value || 0)) % 100;
  const last = number % 10;
  if (number > 10 && number < 20) return many;
  if (last === 1) return one;
  if (last >= 2 && last <= 4) return few;
  return many;
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
