const STORAGE_KEY = 'mgw.clean.session.v1';

export function getOrCreateSessionId(storage = window.sessionStorage){
  const existing = normalize(storage?.getItem?.(STORAGE_KEY));
  if (existing) return existing;

  const created = createSessionId();
  try {
    storage?.setItem?.(STORAGE_KEY, created);
  } catch (error) {
    // A restricted WebView can deny sessionStorage. The generated id remains
    // valid for the current runtime and is still owned by this one session module.
  }
  return created;
}

function createSessionId(){
  if (typeof crypto?.randomUUID === 'function') {
    return `session_${crypto.randomUUID()}`;
  }
  const bytes = new Uint8Array(20);
  crypto.getRandomValues(bytes);
  return `session_${[...bytes].map(value => value.toString(16).padStart(2, '0')).join('')}`;
}

function normalize(value){
  const id = String(value || '').trim();
  return /^[a-zA-Z0-9_-]{20,96}$/.test(id) ? id : '';
}
