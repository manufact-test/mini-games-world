from pathlib import Path


def replace_once(path, old, new):
    p = Path(path)
    text = p.read_text(encoding='utf-8')
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{path}: expected exactly one anchor, found {count}: {old[:100]!r}')
    p.write_text(text.replace(old, new, 1), encoding='utf-8')

# 1. Authoritative invite notification first-frame snapshot.
replace_once(
    'bot/services/GameInviteService.php',
    "            $type = (string)($notification['type'] ?? '');\n            $events[] = [\n",
    "            $type = (string)($notification['type'] ?? '');\n            $inviteSnapshot = is_array($invite) ? $this->publicInvite($invite, $userId) : null;\n            $events[] = [\n",
)
replace_once(
    'bot/services/GameInviteService.php',
    "                'game_title' => is_array($invite) ? (string)($invite['game_title'] ?? '') : '',\n                'actions' => $this->liveInviteActions($invite, $userId),\n",
    "                'game_title' => is_array($invite) ? (string)($invite['game_title'] ?? '') : '',\n                'invite_snapshot' => $inviteSnapshot,\n                'actions' => $this->liveInviteActions($invite, $userId),\n",
)

# 2. Carry that same safe projection through the notification action button.
replace_once(
    'app/assets/js/screens/notifications-screen-v110r12.js',
    "    invite_is_owner:Boolean(value.invite_is_owner),\n    actions:Array.isArray(value.actions) ? value.actions.map(String) : [],\n",
    "    invite_is_owner:Boolean(value.invite_is_owner),\n    invite_snapshot:value.invite_snapshot && typeof value.invite_snapshot === 'object'\n      ? cloneItem(value.invite_snapshot)\n      : null,\n    actions:Array.isArray(value.actions) ? value.actions.map(String) : [],\n",
)
replace_once(
    'app/assets/js/screens/notifications-screen-v110r12.js',
    "function renderInviteActions(item){\n  const token = String(item.invite_token || '');\n  const actions = Array.isArray(item.actions) ? item.actions : [];\n  if (!token || !actions.length) return '';\n  return `<div class=\"notification-actions invite-actions\">${actions.map(action => {\n    const primary = action === 'accept' || action === 'start';\n    return `<button class=\"btn ${primary ? 'primary' : 'ghost'} full\" data-invite-action=\"${escapeHtml(action)}\" data-invite-token=\"${escapeHtml(token)}\" type=\"button\">${escapeHtml(actionLabel(action))}</button>`;\n  }).join('')}</div>`;\n}\n",
    "function renderInviteActions(item){\n  const token = String(item.invite_token || '');\n  const actions = Array.isArray(item.actions) ? item.actions : [];\n  if (!token || !actions.length) return '';\n  const snapshot = inviteActionSnapshot(item);\n  const snapshotAttribute = snapshot\n    ? ` data-invite-snapshot=\"${escapeHtml(JSON.stringify(snapshot))}\"`\n    : '';\n  return `<div class=\"notification-actions invite-actions\">${actions.map(action => {\n    const primary = action === 'accept' || action === 'start';\n    return `<button class=\"btn ${primary ? 'primary' : 'ghost'} full\" data-invite-action=\"${escapeHtml(action)}\" data-invite-token=\"${escapeHtml(token)}\"${snapshotAttribute} type=\"button\">${escapeHtml(actionLabel(action))}</button>`;\n  }).join('')}</div>`;\n}\n\nfunction inviteActionSnapshot(item){\n  const snapshot = item?.invite_snapshot && typeof item.invite_snapshot === 'object'\n    ? cloneItem(item.invite_snapshot)\n    : null;\n  if (!snapshot || String(snapshot.token || '') !== String(item?.invite_token || '')) return null;\n  return snapshot;\n}\n",
)

# 3. Direct invite: first-frame Cancel is a real user intent, serialized once the token exists.
replace_once(
    'app/assets/js/games/game-invites-v110.js',
    "let directInviteRequestGeneration = 0;\nlet inviteStartPending = false;\n",
    "let directInviteRequestGeneration = 0;\nconst directInviteCancelIntents = new Set();\nlet inviteStartPending = false;\n",
)
replace_once(
    'app/assets/js/games/game-invites-v110.js',
    "    currentInvite = result.invite || null;\n    if (!currentInvite?.token) throw new Error('Не удалось создать приглашение.');\n\n    if (isDirectInvitePendingSurfaceOpen(requestGeneration)) {\n      finalizeDirectInvitePendingSurface(currentInvite, requestGeneration);\n    } else if (isPassiveOwnerPending(currentInvite)) {\n      currentInvite = null;\n    }\n\n    dispatchNotificationCount(result.unread_count);\n",
    "    currentInvite = result.invite || null;\n    if (!currentInvite?.token) throw new Error('Не удалось создать приглашение.');\n\n    if (directInviteCancelIntents.has(requestGeneration)) {\n      await settleQueuedDirectInviteCancel(currentInvite, requestGeneration);\n      window.setTimeout(cancelWarmShareDraft, 180);\n      return;\n    }\n\n    if (isDirectInvitePendingSurfaceOpen(requestGeneration)) {\n      finalizeDirectInvitePendingSurface(currentInvite, requestGeneration);\n    } else if (isPassiveOwnerPending(currentInvite)) {\n      currentInvite = null;\n    }\n\n    dispatchNotificationCount(result.unread_count);\n",
)
replace_once(
    'app/assets/js/games/game-invites-v110.js',
    "  } catch (error) {\n    toast(error.message || 'Не удалось отправить приглашение.');\n    if (isDirectInvitePendingSurfaceOpen(requestGeneration)) await openPlayerPicker(context);\n  }\n}\n\nasync function createLinkDraft",
    "  } catch (error) {\n    if (directInviteCancelIntents.has(requestGeneration)) {\n      directInviteCancelIntents.delete(requestGeneration);\n      currentInvite = null;\n      scheduleSync(0);\n      scheduleWatch(0);\n      return;\n    }\n    toast(error.message || 'Не удалось отправить приглашение.');\n    if (isDirectInvitePendingSurfaceOpen(requestGeneration)) await openPlayerPicker(context);\n  }\n}\n\nasync function settleQueuedDirectInviteCancel(invite, requestGeneration){\n  const token = String(invite?.token || '');\n  if (!token) return;\n  try {\n    const result = await inviteRequest('cancel', { token });\n    syncState(result);\n    const unreadCount = Number(result?.unread_count);\n    consumeInviteNotification(token, Number.isFinite(unreadCount) ? Math.max(0, unreadCount) : null);\n    if (Number.isFinite(unreadCount)) dispatchNotificationCount(Math.max(0, unreadCount));\n    currentInvite = null;\n    scheduleSync(0);\n    scheduleWatch(0);\n  } catch (error) {\n    currentInvite = invite;\n    showOwnerWaiting(invite, 'Не удалось отменить приглашение. Попробуйте ещё раз.');\n    toast(error.message || 'Не удалось отменить приглашение.');\n  } finally {\n    directInviteCancelIntents.delete(requestGeneration);\n  }\n}\n\nasync function createLinkDraft",
)
replace_once(
    'app/assets/js/games/game-invites-v110.js',
    "function showDirectInvitePending(context, opponentName, requestGeneration){\n  openSheet(`\n    <span data-invite-sheet data-direct-invite-pending=\"${Number(requestGeneration || 0)}\" hidden></span>\n    <div class=\"sheet-head\">\n      <div><h2>Приглашение отправлено</h2></div>\n      <button class=\"close\" data-close-sheet type=\"button\">×</button>\n    </div>\n    ${contextSummary(context)}\n    <button class=\"btn primary full\" data-direct-invite-cancel-reserved type=\"button\" aria-disabled=\"true\" disabled style=\"opacity:1\">Отменить приглашение</button>\n  `);\n}\n",
    "function showDirectInvitePending(context, opponentName, requestGeneration){\n  openSheet(`\n    <span data-invite-sheet data-direct-invite-pending=\"${Number(requestGeneration || 0)}\" hidden></span>\n    <div class=\"sheet-head\">\n      <div><h2>Приглашение отправлено</h2><p>Для ${escapeHtml(opponentName || 'игрока')}</p></div>\n      <button class=\"close\" data-close-sheet type=\"button\">×</button>\n    </div>\n    ${contextSummary(context)}\n    <button class=\"btn primary full\" data-direct-invite-cancel-reserved=\"${Number(requestGeneration || 0)}\" type=\"button\">Отменить приглашение</button>\n  `);\n  document.querySelector(`[data-direct-invite-cancel-reserved=\"${Number(requestGeneration || 0)}\"]`)?.addEventListener('click', () => {\n    requestPendingDirectInviteCancel(requestGeneration);\n  });\n}\n\nfunction requestPendingDirectInviteCancel(requestGeneration){\n  if (!isDirectInvitePendingSurfaceOpen(requestGeneration)) return;\n  directInviteCancelIntents.add(Number(requestGeneration || 0));\n  inviteUiTransitionGeneration += 1;\n  haptic('light');\n  currentInvite = null;\n  closeSheet();\n  showScreen('home');\n}\n",
)
replace_once(
    'app/assets/js/games/game-invites-v110.js',
    "  button.disabled = false;\n  button.removeAttribute('aria-disabled');\n  button.removeAttribute('style');\n  button.removeAttribute('data-direct-invite-cancel-reserved');\n",
    "  button.disabled = false;\n  button.removeAttribute('data-direct-invite-cancel-reserved');\n",
)

# 4. Accept first frame comes from the public notification snapshot; no fake client deadline.
replace_once(
    'app/assets/js/games/game-invites-v110.js',
    "  const originalText = button.textContent;\n  const rollbackInvite = cloneInvite(currentInvite);\n  const rollbackHtml = String(document.getElementById('sheet')?.innerHTML || '');\n  const terminalContext = terminalActionContext(button, action, token);\n  const optimisticOwnerCancel = action === 'cancel'\n    && Boolean(rollbackInvite?.is_owner)\n    && !terminalContext.notificationSurface;\n",
    "  const originalText = button.textContent;\n  const rollbackInvite = inviteForAction(token, button) || cloneInvite(currentInvite);\n  if (rollbackInvite?.token) currentInvite = cloneInvite(rollbackInvite);\n  const rollbackHtml = String(document.getElementById('sheet')?.innerHTML || '');\n  const terminalContext = terminalActionContext(button, action, token);\n  const optimisticParticipantCancel = action === 'cancel'\n    && !terminalContext.notificationSurface\n    && String(rollbackInvite?.token || '') === token\n    && (Boolean(rollbackInvite?.is_owner) || Boolean(rollbackInvite?.is_invitee));\n",
)
replace_once(
    'app/assets/js/games/game-invites-v110.js',
    "  if (action === 'accept') {\n    showInviteeWaiting({\n      ...currentInvite,\n      status:'accepted',\n      ready_deadline_at:currentInvite?.ready_deadline_at || new Date(Date.now() + 90000).toISOString(),\n    });\n  } else if (optimisticOwnerCancel) {\n",
    "  if (action === 'accept') {\n    showInviteeWaiting({\n      ...(rollbackInvite || {}),\n      token,\n      status:'accepted',\n      is_owner:false,\n      is_invitee:true,\n      ready_deadline_at:String(rollbackInvite?.ready_deadline_at || ''),\n    });\n  } else if (optimisticParticipantCancel) {\n",
)
replace_once(
    'app/assets/js/games/game-invites-v110.js',
    "      const selfCancelledOwner = action === 'cancel'\n        && Boolean(terminalInvite?.is_owner)\n        && !terminalContext.notificationSurface;\n",
    "      const selfCancelledParticipant = action === 'cancel'\n        && !terminalContext.notificationSurface\n        && String(terminalInvite?.token || '') === token\n        && (Boolean(terminalInvite?.is_owner) || Boolean(terminalInvite?.is_invitee));\n",
)
replace_once(
    'app/assets/js/games/game-invites-v110.js',
    "      } else if (selfCancelledOwner) {\n        consumeInviteNotification(token, unreadCount);\n        if (!optimisticOwnerCancel) {\n          closeSheet();\n          showScreen('home');\n        }\n",
    "      } else if (selfCancelledParticipant) {\n        consumeInviteNotification(token, unreadCount);\n        if (!optimisticParticipantCancel) {\n          closeSheet();\n          showScreen('home');\n        }\n",
)
replace_once(
    'app/assets/js/games/game-invites-v110.js',
    "function terminalActionContext(button, action, token){\n",
    "function inviteForAction(token, button){\n  const current = String(currentInvite?.token || '') === token ? cloneInvite(currentInvite) : null;\n  const raw = String(button?.dataset?.inviteSnapshot || '');\n  if (!raw) return current;\n  try {\n    const snapshot = JSON.parse(raw);\n    if (!snapshot || typeof snapshot !== 'object' || String(snapshot.token || '') !== token) return current;\n    return { ...(current || {}), ...snapshot, token };\n  } catch (error) {\n    return current;\n  }\n}\n\nfunction terminalActionContext(button, action, token){\n",
)
replace_once(
    'app/assets/js/games/game-invites-v110.js',
    "    ${inviteSummary(invite)}\n    <div class=\"small-note invite-status-note\">Ожидание до ${escapeHtml(formatTime(invite.ready_deadline_at))}.</div>\n    <button class=\"btn ghost full\" data-invite-action=\"cancel\" data-invite-token=\"${escapeHtml(invite.token || '')}\" type=\"button\">Отменить участие</button>\n",
    "    ${inviteSummary(invite)}\n    <div class=\"small-note invite-status-note\">${escapeHtml(inviteeWaitingNote(invite))}</div>\n    <button class=\"btn ghost full\" data-invite-action=\"cancel\" data-invite-token=\"${escapeHtml(invite.token || '')}\" type=\"button\">Отменить участие</button>\n",
)
replace_once(
    'app/assets/js/games/game-invites-v110.js',
    "  marker.dataset.inviteState = inviteSheetState(invite);\n  note.textContent = `Ожидание до ${formatTime(invite.ready_deadline_at)}.`;\n  return true;\n}\n",
    "  marker.dataset.inviteState = inviteSheetState(invite);\n  note.textContent = inviteeWaitingNote(invite);\n  return true;\n}\n\nfunction inviteeWaitingNote(invite){\n  const formatted = formatTime(invite?.ready_deadline_at);\n  return formatted === '—' ? 'Ожидаем запуск матча.' : `Ожидание до ${formatted}.`;\n}\n",
)

# 5. Fresh immutable graph publication.
replace_once('app/assets/js/main-v110-handoff-shell.js',
    "window.__MGW_BUILD__ = 'v110-mvp14-invite-popup-stability-v1136';",
    "window.__MGW_BUILD__ = 'v110-mvp14-invite-transition-ux-v1137';")
replace_once('app/assets/js/main-v110-handoff-shell.js',
    "./screens/notifications-screen-v110r12.js?v=1134&selfcopy=1",
    "./screens/notifications-screen-v110r12.js?v=1137&ux=1")
replace_once('app/assets/js/main-v110-handoff-shell.js',
    "./games/game-invites-v110.js?v=1136&pending=7&b=033cc11c8ba4",
    "./games/game-invites-v110.js?v=1137&ux=1")
replace_once('app/assets/js/main-v110.js',
    "window.__MGW_BUILD__ = 'v110-mvp14-invite-popup-stability-v1136';",
    "window.__MGW_BUILD__ = 'v110-mvp14-invite-transition-ux-v1137';")
replace_once('app/assets/js/main-v110.js',
    "./main-v110-handoff-shell.js?v=1136&pending=7&b=bcfe61af0e6c",
    "./main-v110-handoff-shell.js?v=1137&ux=1")
replace_once('app/v110.php',
    "./assets/js/main-v110.js?v=1136&pending=7&b=ab56fb53c460",
    "./assets/js/main-v110.js?v=1137&ux=1")
replace_once('app/v110.php',
    'data-hotfix-build="v110-mvp14-invite-popup-stability-v1136"',
    'data-hotfix-build="v110-mvp14-invite-transition-ux-v1137"')
replace_once('app/v110.php',
    "header('X-MGW-Notification-Graph: v1134');",
    "header('X-MGW-Notification-Graph: v1137');")
replace_once('app/v110.php',
    "header('X-MGW-Invite-Graph: v1136');",
    "header('X-MGW-Invite-Graph: v1137');")

# 6. Successor source contracts.
replace_once(
    'bot/tests/ProductionMvp14InterfaceInviteEntrySpeedV1135Test.php',
    "        && str_contains($client, 'data-direct-invite-cancel-reserved'),\n    'Direct invite must paint immediately and upgrade its reserved Cancel control in place after token creation.'\n",
    "        && str_contains($client, 'data-direct-invite-cancel-reserved')\n        && str_contains($client, 'directInviteCancelIntents')\n        && str_contains($client, 'settleQueuedDirectInviteCancel'),\n    'Direct invite must paint an immediately actionable Cancel control and serialize an early cancel intent through the same authoritative invite owner.'\n",
)
replace_once(
    'bot/tests/ProductionMvp14InterfaceInviteEntrySpeedV1135Test.php',
    "    str_contains($shell, './games/game-invites-v110.js?v=1136')\n        && str_contains($main, './main-v110-handoff-shell.js?v=1136')\n        && str_contains($entry, './assets/js/main-v110.js?v=1136')\n        && str_contains($entry, \"header('X-MGW-Invite-Graph: v1136');\"),\n    'The popup-stability owner must be published through one immutable v1136 graph.'\n",
    "    str_contains($shell, './games/game-invites-v110.js?v=1137')\n        && str_contains($shell, './screens/notifications-screen-v110r12.js?v=1137')\n        && str_contains($main, './main-v110-handoff-shell.js?v=1137')\n        && str_contains($entry, './assets/js/main-v110.js?v=1137')\n        && str_contains($entry, \"header('X-MGW-Invite-Graph: v1137');\")\n        && str_contains($entry, \"header('X-MGW-Notification-Graph: v1137');\"),\n    'The invite transition UX owner must be published through one immutable v1137 graph.'\n",
)
replace_once(
    'bot/tests/ProductionMvp14InterfaceInviteEntrySpeedV1135Test.php',
    'fwrite(STDOUT, "ProductionMvp14InterfaceInviteEntrySpeedV1135Test: {$assertions} assertions passed (v1136 successor contract)\\n");',
    'fwrite(STDOUT, "ProductionMvp14InterfaceInviteEntrySpeedV1135Test: {$assertions} assertions passed (v1137 successor contract)\\n");',
)
replace_once('bot/tests/ProductionMvp14D1PickerSelectiveJsonReadContractTest.php',
    'The v1136 picker may paint before the read completes',
    'The v1137 picker may paint before the read completes')
replace_once('bot/tests/ProductionMvp14D1PickerSelectiveJsonReadContractTest.php',
    "str_contains($shell, './games/game-invites-v110.js?v=1136')",
    "str_contains($shell, './games/game-invites-v110.js?v=1137')")
replace_once('bot/tests/ProductionMvp14D1PickerSelectiveJsonReadContractTest.php',
    'The accepted singular picker owner must be published through v1136.',
    'The accepted singular picker owner must be published through v1137.')

contract = Path('bot/tests/ProductionMvp14InviteTransitionUxV1137Test.php')
contract.write_text(r'''<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};

$service = $read('bot/services/GameInviteService.php');
$notifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');
$invites = $read('app/assets/js/games/game-invites-v110.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$main = $read('app/assets/js/main-v110.js');
$entry = $read('app/v110.php');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($service, '$inviteSnapshot = is_array($invite) ? $this->publicInvite($invite, $userId) : null;')
        && str_contains($service, "'invite_snapshot' => $inviteSnapshot"),
    'Live invite notifications must reuse publicInvite as the complete authoritative first-frame snapshot.'
);
$assert(
    str_contains($notifications, 'data-invite-snapshot=')
        && str_contains($notifications, 'inviteActionSnapshot(item)')
        && str_contains($notifications, 'invite_snapshot:value.invite_snapshot'),
    'Notification action buttons must carry the complete safe invite snapshot into the immediate transition.'
);
$assert(
    str_contains($invites, 'const directInviteCancelIntents = new Set();')
        && str_contains($invites, 'requestPendingDirectInviteCancel(requestGeneration)')
        && str_contains($invites, 'await settleQueuedDirectInviteCancel(currentInvite, requestGeneration);')
        && !str_contains($invites, 'aria-disabled="true" disabled style="opacity:1"'),
    'Direct invite Cancel must be actionable on the first frame and serialized after token creation when necessary.'
);
$assert(
    str_contains($invites, 'const rollbackInvite = inviteForAction(token, button) || cloneInvite(currentInvite);')
        && str_contains($invites, 'function inviteForAction(token, button)')
        && str_contains($invites, "ready_deadline_at:String(rollbackInvite?.ready_deadline_at || '')"),
    'Accept must build its first waiting frame from the notification/public snapshot without a fake deadline.'
);
$assert(
    str_contains($invites, "const optimisticParticipantCancel = action === 'cancel'")
        && str_contains($invites, "const selfCancelledParticipant = action === 'cancel'")
        && str_contains($invites, 'Boolean(terminalInvite?.is_invitee)'),
    'Self-cancel must return both inviter and invitee directly to ordinary activity without a local terminal confirmation sheet.'
);
$assert(
    str_contains($invites, "return formatted === '—' ? 'Ожидаем запуск матча.'")
        && !str_contains($invites, 'new Date(Date.now() + 90000).toISOString()'),
    'Optimistic Accept must not invent a client-side ready deadline while the authoritative response is pending.'
);
$assert(
    str_contains($shell, './games/game-invites-v110.js?v=1137')
        && str_contains($shell, './screens/notifications-screen-v110r12.js?v=1137')
        && str_contains($main, './main-v110-handoff-shell.js?v=1137')
        && str_contains($entry, './assets/js/main-v110.js?v=1137')
        && str_contains($entry, "header('X-MGW-Invite-Graph: v1137');")
        && str_contains($entry, "header('X-MGW-Notification-Graph: v1137');"),
    'The final UX behavior must be published through one immutable v1137 Telegram graph.'
);
$assert(
    !str_contains($invites, 'retry')
        && !str_contains($invites, 'setTimeout(async')
        && !str_contains($invites, 'setInterval(async'),
    'The UX fix must not add retry/sleep/polling owners.'
);

fwrite(STDOUT, "ProductionMvp14InviteTransitionUxV1137Test: {$assertions} assertions passed\n");
''', encoding='utf-8')

print('Invite transition UX final patch applied.')
