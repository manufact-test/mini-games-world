export const state = {
  user: null,
  stats: null,
  room: 'match',
  selectedGame: 'tictactoe',
  selectedBet: 10,
  selectedBoardSize: 3,
  activeGame: null,
  ignoredFinishedGameId: null,
  session: null,
  timers: { stats:null, search:null, game:null },
  polling: { search:false, game:false },
  locks: { startSearch:false, move:false },
  rateLimitNoticeAt: 0
};
