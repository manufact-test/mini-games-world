import { closeSheet } from '../components/sheet.js?v=68';

let initialized = false;

export function initMgwPurchaseFeedback(){
  if (initialized) return;
  initialized = true;
  document.addEventListener('click', handlePurchaseConfirm, true);
}

function handlePurchaseConfirm(event){
  const target = event.target;
  if (!(target instanceof Element)) return;
  const button = target.closest('.store-v2-confirm button[id*="ConfirmBuy"]');
  if (!(button instanceof HTMLButtonElement) || button.disabled) return;
  if (button.dataset.mgwPurchasePending === '1') return;

  button.dataset.mgwPurchasePending = '1';
  button.classList.add('mgw-purchase-pending');
  button.setAttribute('aria-busy', 'true');
  button.textContent = 'Покупаем…';

  // The category owner starts the authoritative request in the same click task.
  // Yield sheet dismissal to the microtask so its target listener always runs first.
  queueMicrotask(() => closeSheet());
}
