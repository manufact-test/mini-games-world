import { t } from '@mgw/i18n';

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

const SHORT_WORDS = new RegExp(`(^|[\\s([{"«„“])(${t('typography.short_words_pattern')}) +(?=\\S)`, 'giu');
const THOUSANDS_GROUPS = /(\\d) +(?=\\d{3}(?:\\D|$))/gu;
const NUMBER_UNITS = new RegExp(`(\\d(?:[\\d \\u00A0]*\\d)?) +(${t('typography.number_units_pattern')})`, 'giu');
const WORD_TOKEN = new RegExp(`([${t('typography.word_chars')}][${t('typography.word_chars')}-]{2,}) +(Gold|Match)\\b`, 'gu');
const NUMBER_SIGN = new RegExp(`№ +([${t('typography.number_sign_chars')}])`, 'gu');
const CONTENT_PROBE = new RegExp(`[${t('typography.content_probe_chars')}]`, 'u');

export function typographText(value){
  if (typeof value !== 'string' || value.length < 2) return value;
  if (!CONTENT_PROBE.test(value)) return value;
  if (/https?:\/\/|www\.|[\w.+-]+@[\w.-]+\.\w+/i.test(value)) return value;

  return value
    .replace(THOUSANDS_GROUPS, '$1\u00A0')
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
