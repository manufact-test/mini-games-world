const INITIAL_STATE = Object.freeze({
  phase:'booting',
  launch:null,
  account:null,
  session:null,
  presence:null,
  matchmaking:null,
  activeMatch:null,
  matchResult:null,
  invite:null,
  notifications:[],
  balances:null,
  error:null,
});

export function createRuntimeStore(seed = {}){
  let state = Object.freeze({ ...INITIAL_STATE, ...seed });
  const listeners = new Set();

  return Object.freeze({
    getState(){
      return state;
    },
    setState(patch){
      if (!patch || typeof patch !== 'object' || Array.isArray(patch)) {
        throw new TypeError('Runtime state patch must be an object.');
      }
      state = Object.freeze({ ...state, ...patch });
      for (const listener of [...listeners]) listener(state);
      return state;
    },
    subscribe(listener){
      if (typeof listener !== 'function') throw new TypeError('Runtime listener must be a function.');
      listeners.add(listener);
      return () => listeners.delete(listener);
    },
  });
}
