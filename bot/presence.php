<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/services/PresenceService.php';
require_once __DIR__ . '/services/ReconnectLifecycleService.php';
require_once __DIR__ . '/services/TournamentReconnectTraceService.php';

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) api_error('Некорректный запрос.');

    $action = clean_string($payload['action'] ?? 'status', 24);
    if (!in_array($action, ['status', 'ping', 'background', 'leave'], true)) {
        throw new RuntimeException('Неизвестное действие присутствия.');
    }

    $sessionId = clean_string($payload['sessionId'] ?? '', 120);
    $presenceLeaseId = clean_string($payload['presenceLeaseId'] ?? '', 120);
    $auth = new AuthService($config);
    // Presence only needs the already verified provider user id to own a
    // document/session lease. Keep Telegram/staging/dev authentication intact,
    // but avoid redundant provider-neutral account/DB identity resolution on
    // this high-frequency path.
    $tgUser = $auth->getUserFromRequest($payload, false);
    $accountId = trim((string)($tgUser['id'] ?? ''));
    if ($accountId === '') throw new RuntimeException('Пользователь не найден.');
    if ($sessionId === '') throw new RuntimeException('Сессия устройства не найдена.');

    $presence = new PresenceService();
    $stats = new StatsService($presence);
    $reconnect = new ReconnectLifecycleService($config, $presence);
    $trace = new TournamentReconnectTraceService($config);
    $db = StorageFactory::createJson((string)($config['data_dir'] ?? (__DIR__ . '/data')));

    // Preserve the state that existed before a fresh ping. It lets a new
    // supported client recover a stale foreground session in the same request,
    // before bootstrap encounters the old device lock.
    $previousPresence = $presence->gameplaySnapshot($accountId);

    // Departure signals must be published before reconnect evaluation so the
    // second player leaving can be observed immediately. A returning foreground
    // heartbeat is deliberately different: do NOT overwrite stale disconnect
    // evidence yet. Telegram opens bootstrap and presence requests in parallel,
    // and publishing foreground first lets bootstrap miss the dual-away state
    // and settle an expired move as a normal timeout before reconnect acquires
    // the runtime lock.
    $isForegroundHeartbeat = in_array($action, ['ping', 'status'], true);
    if ($action === 'background') {
        $presence->background($accountId, $sessionId, $presenceLeaseId);
    } elseif ($action === 'leave') {
        $presence->leave($accountId, $sessionId, $presenceLeaseId);
    }

    // Normal four-second presence heartbeats stay read-only. We enter a storage
    // transaction only when reconnect state, expiry or settlement really needs
    // to change, avoiding a new high-frequency JSON write loop.
    $decision = $db->readOnly(static function (array $data) use (
        $reconnect,
        $trace,
        $accountId,
        $sessionId,
        $action,
        $previousPresence
    ): array {
        return [
            'requires_mutation'=>$reconnect->needsMutation(
                $data,
                $accountId,
                $sessionId,
                $action,
                $previousPresence
            ),
            'trace_context'=>$trace->tournamentContextForAccount($data, $accountId),
        ];
    });
    $requiresMutation = !empty($decision['requires_mutation']);
    $traceContext = is_array($decision['trace_context'] ?? null)
        ? $decision['trace_context']
        : null;
    $previousState = (string)($previousPresence['state'] ?? 'unknown');
    if ($traceContext !== null
        && ($requiresMutation
            || in_array($action, ['background', 'leave'], true)
            || $previousState !== 'foreground')) {
        $trace->record('presence.request', [
            'action'=>$action,
            'account_ref'=>$trace->ref($accountId),
            'session_ref'=>$trace->ref($sessionId),
            'lease_ref'=>$trace->ref($presenceLeaseId),
            'previous_presence'=>$trace->presenceContext($previousPresence),
            'requires_mutation'=>$requiresMutation,
            'runtime'=>$traceContext,
        ]);
    }

    if ($requiresMutation) {
        $result = $db->transaction(static function (array &$data) use (
            $reconnect,
            $trace,
            $stats,
            $presence,
            $accountId,
            $sessionId,
            $presenceLeaseId,
            $action,
            $isForegroundHeartbeat,
            $previousPresence
        ): array {
            $before = $trace->tournamentContextForAccount($data, $accountId);
            $reconnect->synchronize($data, $accountId, $sessionId, $action, $previousPresence);

            // Publish the returning foreground lease while app.lock is still
            // exclusively owned by this reconnect mutation. Any concurrent
            // bootstrap cleanup therefore observes either the old absence
            // evidence or the already-reconciled runtime, never the broken
            // intermediate state exposed by the former touch-before-lock order.
            if ($isForegroundHeartbeat) {
                $presence->touch($accountId, $sessionId, $presenceLeaseId);
            }

            $after = $trace->tournamentContextForAccount($data, $accountId);
            $trace->record('presence.reconnect_mutation', [
                'action'=>$action,
                'account_ref'=>$trace->ref($accountId),
                'session_ref'=>$trace->ref($sessionId),
                'lease_ref'=>$trace->ref($presenceLeaseId),
                'before'=>$before,
                'after'=>$after,
            ]);
            return ['stats' => $stats->build($data)];
        });
    } else {
        // A normal heartbeat has no reconnect mutation to protect. Publish it
        // only after the read-only reconnect decision so a returning stale
        // lease can never be masked before that decision is made.
        if ($isForegroundHeartbeat) {
            $presence->touch($accountId, $sessionId, $presenceLeaseId);
        }
        $result = $db->readOnly(static function (array $data) use ($stats): array {
            return ['stats' => $stats->build($data)];
        });
    }

    api_ok($result);
} catch (Throwable $e) {
    api_error($e->getMessage());
}
