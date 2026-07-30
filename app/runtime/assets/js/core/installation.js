const STORAGE_KEY = 'mgw.clean.installation.v1';

export function getOrCreateInstallationId(storage = window.localStorage){
  const existing = normalize(storage?.getItem?.(STORAGE_KEY));
  if (existing) return existing;

  const created = createInstallationId();
  try {
    storage?.setItem?.(STORAGE_KEY, created);
  } catch (error) {
    // A private WebView can deny persistent storage. The generated id remains
    // valid for the current runtime without introducing a second state owner.
  }
  return created;
}

function createInstallationId(){
  if (typeof crypto?.randomUUID === 'function') {
    return `install_${crypto.randomUUID()}`;
  }
  const bytes = new Uint8Array(20);
  crypto.getRandomValues(bytes);
  return `install_${[...bytes].map(value => value.toString(16).padStart(2, '0')).join('')}`;
}

function normalize(value){
  const id = String(value || '').trim();
  return /^[a-zA-Z0-9_-]{20,80}$/.test(id) ? id : '';
}
