<?php
declare(strict_types=1);

namespace Mgw\CleanRuntime\Server\Session;

use Mgw\CleanRuntime\Server\Context\RuntimeRequestContext;
use Mgw\CleanRuntime\Server\RuntimeConfig;

final readonly class RuntimeSessionService
{
    public function __construct(private RuntimeConfig $config) {}

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $launch
     * @return array<string,mixed>
     */
    public function bootstrap(array &$state, RuntimeRequestContext $context, array $launch, int $nowEpoch): array
    {
        $now = gmdate('c', $nowEpoch);
        $installation = $this->touchInstallation($state, $context, $launch, $now);
        $account = $this->touchAccount($state, $context, $now);
        [$account, $session] = $this->touchSession($state, $account, $context, $nowEpoch, $now);
        $presence = $this->touchPresence($state, $context, $nowEpoch, $now);
        $state['accounts'][$context->accountId()] = $account;

        return $this->projection($installation, $account, $session, $presence);
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    public function heartbeat(array &$state, RuntimeRequestContext $context, int $nowEpoch): array
    {
        $accountId = $context->accountId();
        $account = is_array($state['accounts'][$accountId] ?? null) ? $state['accounts'][$accountId] : null;
        $session = is_array($state['sessions'][$context->sessionId] ?? null) ? $state['sessions'][$context->sessionId] : null;
        $installation = is_array($state['installations'][$context->installationId] ?? null)
            ? $state['installations'][$context->installationId]
            : null;

        if ($account === null || $session === null || $installation === null) {
            throw new \RuntimeException('Clean staging session is not initialized.');
        }
        if ((string)($session['account_id'] ?? '') !== $accountId
            || (string)($session['installation_id'] ?? '') !== $context->installationId) {
            throw new \RuntimeException('Clean staging session ownership mismatch.');
        }

        $now = gmdate('c', $nowEpoch);
        $account = array_replace($account, $context->identity, ['updated_at' => $now]);
        [$account, $session] = $this->touchSession($state, $account, $context, $nowEpoch, $now);
        $presence = $this->touchPresence($state, $context, $nowEpoch, $now);
        $state['accounts'][$accountId] = $account;

        return $this->projection($installation, $account, $session, $presence);
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    public function assertCanMutate(array &$state, RuntimeRequestContext $context, int $nowEpoch): array
    {
        $accountId = $context->accountId();
        $account = is_array($state['accounts'][$accountId] ?? null) ? $state['accounts'][$accountId] : null;
        $session = is_array($state['sessions'][$context->sessionId] ?? null) ? $state['sessions'][$context->sessionId] : null;
        if ($account === null || $session === null) {
            throw new \RuntimeException('Clean staging session is not initialized.');
        }
        if ((string)($session['account_id'] ?? '') !== $accountId
            || (string)($session['installation_id'] ?? '') !== $context->installationId) {
            throw new \RuntimeException('Clean staging session ownership mismatch.');
        }

        $activeId = trim((string)($account['active_session_id'] ?? ''));
        $activeAt = strtotime((string)($account['active_session_at'] ?? '')) ?: 0;
        $expired = $activeAt <= 0 || $nowEpoch - $activeAt > $this->config->sessionTimeoutSec;
        $status = (string)($account['status'] ?? 'idle');

        if ($activeId !== '' && $activeId !== $context->sessionId && !$expired
            && in_array($status, ['searching', 'playing'], true)) {
            throw new \RuntimeException(
                $status === 'playing'
                    ? 'У вас уже идёт активная игра на другом устройстве.'
                    : 'Вы уже ищете матч на другом устройстве.'
            );
        }

        $now = gmdate('c', $nowEpoch);
        $account['active_session_id'] = $context->sessionId;
        $account['active_session_at'] = $now;
        $account['updated_at'] = $now;
        $state['accounts'][$accountId] = $account;
        $session['last_seen_at'] = $now;
        $session['locked'] = false;
        $session['active_session_id'] = $context->sessionId;
        $state['sessions'][$context->sessionId] = $session;
        $this->touchPresence($state, $context, $nowEpoch, $now);
        return $account;
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    public function currentProjection(array $state, RuntimeRequestContext $context, int $nowEpoch): array
    {
        $accountId = $context->accountId();
        $account = is_array($state['accounts'][$accountId] ?? null) ? $state['accounts'][$accountId] : null;
        $session = is_array($state['sessions'][$context->sessionId] ?? null) ? $state['sessions'][$context->sessionId] : null;
        $installation = is_array($state['installations'][$context->installationId] ?? null)
            ? $state['installations'][$context->installationId]
            : null;
        $presence = is_array($state['presence'][$context->sessionId] ?? null)
            ? $state['presence'][$context->sessionId]
            : null;
        if ($account === null || $session === null || $installation === null || $presence === null) {
            throw new \RuntimeException('Clean staging session projection is unavailable.');
        }

        $expiresAt = strtotime((string)($presence['expires_at'] ?? '')) ?: 0;
        if ($expiresAt > 0 && $expiresAt < $nowEpoch) {
            $presence['state'] = 'offline';
        }
        return $this->projection($installation, $account, $session, $presence);
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $launch */
    private function touchInstallation(array &$state, RuntimeRequestContext $context, array $launch, string $now): array
    {
        $existing = is_array($state['installations'][$context->installationId] ?? null)
            ? $state['installations'][$context->installationId]
            : [];
        $record = [
            'id' => $context->installationId,
            'first_seen_at' => (string)($existing['first_seen_at'] ?? $now),
            'last_seen_at' => $now,
            'launch_count' => max(0, (int)($existing['launch_count'] ?? 0)) + 1,
            'last_launch' => [
                'runtime' => (string)($launch['runtime'] ?? ''),
                'path' => (string)($launch['path'] ?? ''),
                'source' => (string)($launch['source'] ?? 'standard'),
                'invite_present' => (bool)($launch['invite_present'] ?? false),
                'telegram_available' => (bool)($launch['telegram_available'] ?? false),
            ],
        ];
        $state['installations'][$context->installationId] = $record;
        return $record;
    }

    /** @param array<string,mixed> $state */
    private function touchAccount(array &$state, RuntimeRequestContext $context, string $now): array
    {
        $accountId = $context->accountId();
        $existing = is_array($state['accounts'][$accountId] ?? null) ? $state['accounts'][$accountId] : [];
        return array_replace($existing, $context->identity, [
            'status' => (string)($existing['status'] ?? 'idle'),
            'active_session_id' => $existing['active_session_id'] ?? null,
            'active_session_at' => $existing['active_session_at'] ?? null,
            'current_game_id' => $existing['current_game_id'] ?? null,
            'last_result_game_id' => $existing['last_result_game_id'] ?? null,
            'balance_match' => max(0, (int)($existing['balance_match'] ?? $this->config->initialMatchBalance)),
            'created_at' => (string)($existing['created_at'] ?? $now),
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $account
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function touchSession(
        array &$state,
        array $account,
        RuntimeRequestContext $context,
        int $nowEpoch,
        string $now,
    ): array {
        $accountId = $context->accountId();
        $status = (string)($account['status'] ?? 'idle');
        $activeId = trim((string)($account['active_session_id'] ?? ''));
        $activeAt = strtotime((string)($account['active_session_at'] ?? '')) ?: 0;
        $expired = $activeAt <= 0 || $nowEpoch - $activeAt > $this->config->sessionTimeoutSec;
        $locked = in_array($status, ['searching', 'playing'], true)
            && $activeId !== ''
            && $activeId !== $context->sessionId
            && !$expired;

        if (!$locked) {
            $account['active_session_id'] = $context->sessionId;
            $account['active_session_at'] = $now;
            $activeId = $context->sessionId;
        }

        $existing = is_array($state['sessions'][$context->sessionId] ?? null)
            ? $state['sessions'][$context->sessionId]
            : [];
        if ($existing !== [] && (string)($existing['account_id'] ?? '') !== $accountId) {
            throw new \RuntimeException('Clean staging session identifier collision.');
        }

        $session = [
            'id' => $context->sessionId,
            'account_id' => $accountId,
            'installation_id' => $context->installationId,
            'first_seen_at' => (string)($existing['first_seen_at'] ?? $now),
            'last_seen_at' => $now,
            'locked' => $locked,
            'active_session_id' => $activeId !== '' ? $activeId : null,
        ];
        $state['sessions'][$context->sessionId] = $session;
        return [$account, $session];
    }

    /** @param array<string,mixed> $state */
    private function touchPresence(array &$state, RuntimeRequestContext $context, int $nowEpoch, string $now): array
    {
        $record = [
            'account_id' => $context->accountId(),
            'session_id' => $context->sessionId,
            'state' => 'online',
            'visibility' => (string)$context->presence['visibility'],
            'platform' => (string)$context->presence['platform'],
            'timezone_offset' => (int)$context->presence['timezone_offset'],
            'last_seen_at' => $now,
            'expires_at' => gmdate('c', $nowEpoch + $this->config->presenceTtlSec),
        ];
        $state['presence'][$context->sessionId] = $record;
        return $record;
    }

    /** @return array<string,mixed> */
    private function projection(array $installation, array $account, array $session, array $presence): array
    {
        return [
            'installation' => [
                'id' => $installation['id'],
                'first_seen_at' => $installation['first_seen_at'],
                'last_seen_at' => $installation['last_seen_at'],
                'launch_count' => $installation['launch_count'],
            ],
            'account' => [
                'id' => $account['id'],
                'auth_method' => $account['auth_method'],
                'telegram_id' => $account['telegram_id'],
                'first_name' => $account['first_name'],
                'last_name' => $account['last_name'],
                'username' => $account['username'],
                'language_code' => $account['language_code'],
                'status' => $account['status'],
            ],
            'session' => [
                'id' => $session['id'],
                'active_session_id' => $session['active_session_id'],
                'locked' => $session['locked'],
                'timeout_sec' => $this->config->sessionTimeoutSec,
            ],
            'presence' => [
                'state' => $presence['state'],
                'visibility' => $presence['visibility'],
                'last_seen_at' => $presence['last_seen_at'],
                'expires_at' => $presence['expires_at'],
            ],
            'balances' => [
                'match' => (int)$account['balance_match'],
            ],
        ];
    }
}
