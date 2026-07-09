const KEY = 'mgw_device_session_id';

export function getSessionId(){
  let id = localStorage.getItem(KEY);
  if (!id) {
    const random = crypto?.randomUUID
      ? crypto.randomUUID()
      : `${Date.now()}_${Math.random().toString(16).slice(2)}`;
    id = `sess_${random}`;
    localStorage.setItem(KEY, id);
  }
  return id;
}

export function isSessionLocked(session){
  return !!session?.locked;
}

export function sessionMessage(session){
  return session?.message || 'Игра уже открыта на другом устройстве.';
}
