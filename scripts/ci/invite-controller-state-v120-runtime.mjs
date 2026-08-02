import fs from 'node:fs/promises';
import assert from 'node:assert/strict';

const sourcePath = new URL('../../app/assets/js/games/invite-controller-state-v120.js', import.meta.url);
const source = await fs.readFile(sourcePath, 'utf8');
const moduleUrl = `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`;
const stateApi = await import(moduleUrl);

const {
  createInviteControllerState,
  beginControllerRequest,
  canApplyControllerResponse,
  applyInviteSnapshot,
  applyNotificationSnapshot,
  beginEntryResolution,
  applyEntrySnapshot,
  shouldStartBackgroundLoops,
  shouldAnnounceNotification,
  markNotificationAnnounced,
  removeInviteNotifications,
  sortedNotifications,
  isActionableActiveInvite,
} = stateApi;

const tokenA = 'abcdef0123456789abcdef01';
const tokenB = '1234567890abcdef12345678';

const entryState = createInviteControllerState(tokenA);
beginEntryResolution(entryState);
assert.equal(shouldStartBackgroundLoops(entryState), false, 'deep-link must block background loops');
const opened = applyEntrySnapshot(entryState, {
  opened_invite:{ token:tokenA, status:'pending', is_invitee:true, is_owner:false },
  invite:null,
  tracked_invite:null,
  invite_events:[{
    id:'invite-a', type:'invite_received', title:'Вас пригласили сыграть',
    invite_token:tokenA, invite_status:'pending', invite_is_owner:false,
    actions:['accept','decline'], read:false, created_at:'2026-08-02T13:00:00Z',
  }],
  unread_count:1,
});
assert.equal(opened.token, tokenA, 'deep-link must expose the exact opened invite');
assert.equal(shouldStartBackgroundLoops(entryState), true, 'loops may start only after entry resolves');
assert.equal(isActionableActiveInvite(opened), false, 'received pending invite must not block other games');
const entryItem = sortedNotifications(entryState)[0];
assert.equal(shouldAnnounceNotification(entryState, entryItem), false, 'deep-link invite must not also become a blue toast');

const baselineState = createInviteControllerState('');
applyNotificationSnapshot(baselineState, {
  items:[{
    id:'baseline-item', type:'profile_notice', title:'Старое уведомление',
    read:false, created_at:'2026-08-02T12:00:00Z',
  }],
  unread_count:1,
}, { announce:false });
assert.equal(shouldAnnounceNotification(baselineState, sortedNotifications(baselineState)[0]), false, 'baseline notifications must never reappear as fresh toasts');

const oldRequest = beginControllerRequest(entryState, 'notifications');
const newRequest = beginControllerRequest(entryState, 'notifications');
assert.equal(canApplyControllerResponse(entryState, newRequest), true, 'new response must apply');
applyNotificationSnapshot(entryState, { items:[], unread_count:0 });
assert.equal(sortedNotifications(entryState)[0].invite_token, tokenA, 'older notification snapshot must not erase fresh invite event');
assert.equal(canApplyControllerResponse(entryState, oldRequest), false, 'older response must be rejected');

applyInviteSnapshot(entryState, {
  invite:null,
  tracked_invite:null,
  invite_events:[{
    id:'invite-b', type:'invite_received', title:'Второе приглашение',
    invite_token:tokenB, invite_status:'pending', invite_is_owner:false,
    actions:['accept','decline'], read:false, created_at:'2026-08-02T13:00:01Z',
  }],
  unread_count:2,
}, { announce:true });
assert.equal(sortedNotifications(entryState)[0].invite_token, tokenB, 'second invitation must be immediately visible without waiting for notification API');
markNotificationAnnounced(entryState, 'invite-b');
assert.equal(shouldAnnounceNotification(entryState, sortedNotifications(entryState)[0]), false, 'one event must announce only once');

applyInviteSnapshot(entryState, {
  invite:null, tracked_invite:null,
  invite_events:[{
    id:'invite-b-declined', type:'invite_declined', title:'Приглашение отклонено',
    invite_token:tokenB, invite_status:'declined', invite_is_owner:true,
    actions:[], read:false, created_at:'2026-08-02T13:00:02Z',
  }],
  unread_count:1,
}, { announce:true });
const tokenBItems = sortedNotifications(entryState).filter(item => item.invite_token === tokenB);
assert.equal(tokenBItems.length, 1, 'terminal invite event must replace the stale actionable card for the same token');
assert.equal(tokenBItems[0].type, 'invite_declined', 'terminal invite event must remain visible to the inviter');
assert.deepEqual(tokenBItems[0].actions, [], 'terminal invite event must expose no stale actions');

removeInviteNotifications(entryState, tokenB);
assert.equal(sortedNotifications(entryState).some(item => item.invite_token === tokenB), false, 'decline must remove the actor card immediately');

const ownerState = createInviteControllerState('');
applyInviteSnapshot(ownerState, {
  invite:{ token:tokenB, status:'pending', is_owner:true, is_invitee:false },
  invite_events:[], unread_count:0,
});
assert.equal(isActionableActiveInvite(ownerState.activeInvite), true, 'owner pending invite must still protect conflicting launch');

console.log('ProductionV120InviteControllerStateRuntime: assertions passed');
