const MIN_SNAKE_TILES = 7;
const COLUMN_X = [10, 26, 42, 58, 74, 90];

let initialized = false;
let observer = null;
let scheduled = false;

export function initDominoChainLayout(){
  if (initialized) return;
  initialized = true;

  const board = document.getElementById('gameBoard');
  if (!board) return;

  observer = new MutationObserver(scheduleEnhancement);
  observer.observe(board, { childList:true, subtree:true });
  scheduleEnhancement();
}

function scheduleEnhancement(){
  if (scheduled) return;
  scheduled = true;

  requestAnimationFrame(() => {
    scheduled = false;
    enhanceCurrentChain();
  });
}

function enhanceCurrentChain(){
  const table = document.querySelector('#gameBoard .domino-table');
  const area = table?.querySelector('.domino-chain-area');
  if (!table || !area) return;

  const tiles = Array.from(area.querySelectorAll(':scope > .domino-chain-slot'));
  if (tiles.length < MIN_SNAKE_TILES) return;

  const rows = requiredRows(tiles.length + 2);
  const route = createSnakeRoute(rows);

  tiles.forEach((tile, index) => applyRouteSlot(tile, route[index + 1]));

  const leftTarget = area.querySelector(':scope > .domino-placement-target[data-domino-side="left"]');
  const rightTarget = area.querySelector(':scope > .domino-placement-target[data-domino-side="right"]');
  applyRouteSlot(leftTarget, route[0]);
  applyRouteSlot(rightTarget, route[tiles.length + 1]);

  table.dataset.chainLayout = 'snake';
  table.dataset.chainRows = String(rows);
  area.style.setProperty('--domino-chain-height', `${chainHeight(rows)}px`);
}

function requiredRows(totalSlots){
  for (let rows = 2; rows <= 5; rows += 1) {
    const capacity = rows * COLUMN_X.length + (rows - 1);
    if (capacity >= totalSlots) return rows;
  }
  return 5;
}

function createSnakeRoute(rows){
  const route = [];
  const rowY = rowPositions(rows);

  rowY.forEach((y, rowIndex) => {
    const reverse = rowIndex % 2 === 1;
    const xs = reverse ? [...COLUMN_X].reverse() : COLUMN_X;

    xs.forEach(x => route.push({
      x,
      y,
      vertical:false,
      rotation:reverse ? 180 : 0,
    }));

    if (rowIndex < rowY.length - 1) {
      route.push({
        x:reverse ? 5 : 95,
        y:(y + rowY[rowIndex + 1]) / 2,
        vertical:true,
        rotation:0,
      });
    }
  });

  return route;
}

function rowPositions(rows){
  if (rows === 2) return [18, 82];
  if (rows === 3) return [14, 50, 86];
  if (rows === 4) return [10, 37, 63, 90];
  return [8, 29, 50, 71, 92];
}

function chainHeight(rows){
  if (rows === 2) return 220;
  if (rows === 3) return 292;
  if (rows === 4) return 356;
  return 414;
}

function applyRouteSlot(element, slot){
  if (!element || !slot) return;

  element.style.setProperty('--domino-x', `${slot.x}%`);
  element.style.setProperty('--domino-y', `${slot.y}%`);
  element.style.setProperty('--domino-rotation', `${slot.rotation}deg`);
  element.classList.toggle('vertical', slot.vertical);
  element.classList.toggle('horizontal', !slot.vertical);
}
