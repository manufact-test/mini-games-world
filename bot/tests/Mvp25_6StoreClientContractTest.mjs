import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..', '..');
const storePath = path.join(root, 'app/assets/js/screens/store-screen.js');
const manifestPath = path.join(root, 'app/runtime/client/version-manifest.php');

const source = fs.readFileSync(storePath, 'utf8');
const manifest = fs.readFileSync(manifestPath, 'utf8');

const assertions = [];
const assert = (condition, message) => {
  assertions.push(message);
  if (!condition) throw new Error(message);
};

assert(
  source.includes("{ id:'coins', label:'Коины', available:false }"),
  'Cold Store shell must treat external coin top-up as unavailable before API hydration.'
);
assert(
  source.includes('.filter(tab => tab.available !== false);'),
  'Store tab renderer must hide server-disabled tabs.'
);
assert(
  source.includes("if (!storeTabs().some(tab => String(tab.id) === nextTab) || nextTab === activeTab) return;"),
  'Store navigation must refuse activation of unavailable tabs.'
);
assert(
  source.includes("const visibleTabs = storeTabs();"),
  'Store hydration must reconcile active tab against the visible server-backed tab set.'
);
assert(
  !source.includes('Скоро'),
  'Completed pre-monetization Store must not expose coming-soon copy.'
);
assert(
  source.includes("return emptyState('Пополнение коинов недоступно');"),
  'Defensive direct coin-tab rendering must show a complete disabled state, not a future promise.'
);
assert(
  manifest.includes("./assets/js/screens/store-screen.js?v=69&intent_base=1"),
  'Canonical client manifest must cache-bust the completed Store base module.'
);
assert(
  manifest.includes('mvp25_6=monetization-disabled-complete-v1'),
  'Store manifest identity must record the MVP-25.6 completed disabled-billing state.'
);

console.log(`Mvp25_6StoreClientContractTest: ${assertions.length} assertions passed`);
