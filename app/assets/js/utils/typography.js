const SKIP_SELECTOR = [
  'script',
  'style',
  'textarea',
  'input',
  'select',
  'option',
  'code',
  'pre',
  '[contenteditable="true"]',
  '[data-no-typography]',
].join(',');

const SHORT_WORDS = /(^|[\s([{"«„“])((?:а|и|но|да|не|ни|в|во|на|за|к|ко|с|со|у|о|об|от|до|по|из|для|под|над|при|без|про|через|или|ли|же|бы)) +(?=\S)/giu;
const NUMBER_UNITS = /(\d(?:[\d \u00A0]*\d)?) +(Gold|Match|коин(?:а|ы|ов)?|₽|руб(?:\.|ля|лей)?|%|час(?:а|ов)?|минут(?:а|ы)?|секунд(?:а|ы)?)/giu;
const WORD_TOKEN = /([А-ЯЁа-яёA-Za-z][А-ЯЁа-яёA-Za-z-]{2,}) +(Gold|Match)\b/gu;
const NUMBER_SIGN = /№ +([A-ZА-ЯЁ0-9])/gu;

export function typographText(value){
  if (typeof value !== 'string' || value.length < 2) return value;
  if (!/[А-ЯЁа-яё0-9]/u.test(value)) return value;
  if (/https?:\/\/|www\.|[\w.+-]+@[\w.-]+\.\w+/i.test(value)) return value;

  return value
    .replace(NUMBER_UNITS, '$1\u00A0$2')
    .replace(WORD_TOKEN, '$1\u00A0$2')
    .replace(NUMBER_SIGN, '№\u00A0$1')
    .replace(SHORT_WORDS, '$1$2\u00A0');
}

export function typographRoot(root){
  if (!root) return;

  if (root.nodeType === Node.TEXT_NODE) {
    typographTextNode(root);
    return;
  }

  if (root.nodeType !== Node.ELEMENT_NODE && root.nodeType !== Node.DOCUMENT_FRAGMENT_NODE) {
    return;
  }

  if (root.nodeType === Node.ELEMENT_NODE && shouldSkip(root)) {
    return;
  }

  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
  const nodes = [];
  let node = walker.nextNode();

  while (node) {
    nodes.push(node);
    node = walker.nextNode();
  }

  nodes.forEach(typographTextNode);
}

export function initTypography(root = document.getElementById('app') || document.body){
  if (!root) return null;

  typographRoot(root);

  // Observe only added/replaced DOM nodes. Typography changes text-node values,
  // but characterData is deliberately not observed, so this cannot self-loop.
  const observer = new MutationObserver(records => {
    for (const record of records) {
      if (record.type !== 'childList') continue;
      record.addedNodes.forEach(typographRoot);
    }
  });

  observer.observe(root, {
    childList: true,
    subtree: true,
  });

  return observer;
}

function typographTextNode(node){
  const parent = node.parentElement;
  if (!parent || shouldSkip(parent)) return;

  const current = node.nodeValue || '';
  if (!current.trim()) return;

  const next = typographText(current);
  if (next !== current) {
    node.nodeValue = next;
  }
}

function shouldSkip(element){
  return Boolean(element.closest?.(SKIP_SELECTOR));
}
