const STORAGE_KEY = 'mgw.clean.installation.v1';

export function getOrCreateInstallationId(storage){
  const target = storage || readLocalStorage();
  const existing = normalize(target?.getItem?.(STORAGE_KEY));
  if (existing) return existing;

  const created = createInstallationId();
  try {
    target?.setItem?.(STORAGE_KEY, created);
  } catch (error) {
    // A private WebView can deny persistent storage. The generated id remains
    // valid for the current runtime without introducing a second state owner.
  }
  return created;
}

function readLocalStorage(){
  try {
    return window.localStorage;
  } catch (error) {
    return null;
  }
}

function createInstallationId(){
  const webCrypto = globalThis.crypto;
  if (typeof webCrypto?.randomUUID === 'function') {
    return `install_${webCrypto.randomUUID()}`;
  }
  if (typeof webCrypto?.getRandomValues === 'function') {
    const bytes = new Uint8Array(20);
    webCrypto.getRandomValues(bytes);
    return `install_${[...bytes].map(value => value.toString(16).padStart(2, '0')).join('')}`;
  }
  return `install_${Date.now().toString(36)}_${Math.random().toString(36).slice(2)}_${Math.random().toString(36).slice(2)}`;
}

function normalize(value){
  const id = String(value || '').trim();
  return /^[a-zA-Z0-9_-]{20,80}$/.test(id) ? id : '';
}
