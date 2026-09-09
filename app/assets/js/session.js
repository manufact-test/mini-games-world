const SESSION_KEY = 'mgw_device_session_id';
const DEVICE_KEY = 'mgw_device_id';

function randomId(prefix){
  const random = globalThis.crypto?.randomUUID
    ? globalThis.crypto.randomUUID()
    : `${Date.now()}_${Math.random().toString(16).slice(2)}`;
  return `${prefix}_${random}`;
}

export function getSessionId(){
  let id = localStorage.getItem(SESSION_KEY);
  if (!id) {
    id = randomId('sess');
    localStorage.setItem(SESSION_KEY, id);
  }
  return id;
}

export function getDeviceId(){
  let id = localStorage.getItem(DEVICE_KEY);
  if (!id) {
    id = randomId('device');
    localStorage.setItem(DEVICE_KEY, id);
  }
  return id;
}

export function isSessionLocked(session){
  return !!session?.locked;
}

export function sessionMessage(session){
  return session?.message || 'Игра уже открыта на другом устройстве.';
}
