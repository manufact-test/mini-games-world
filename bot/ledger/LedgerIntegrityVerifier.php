<?php
declare(strict_types=1);

final class LedgerIntegrityVerifier
{
    public function __construct(private DatabaseConnectionInterface $database) {}

    public function verifyAccountAsset(string $accountRef, string $assetCode): array
    {
        $accountRef = $this->text($accountRef, 255);
        $assetCode = strtolower($this->text($assetCode, 32));
        if ($accountRef === '' || $assetCode === '') {
            throw new InvalidArgumentException('account_ref and asset_code are required.');
        }

        $balances = $this->database->fetchAll(
            'SELECT account_ref, asset_code, available_amount, reserved_amount, version
             FROM mgw_balances WHERE account_ref = :account_ref AND asset_code = :asset_code',
            ['account_ref' => $accountRef, 'asset_code' => $assetCode]
        );
        $entries = $this->database->fetchAll(
            'SELECT ledger_sequence, entry_id, idempotency_key, account_ref, mgw_id, legacy_user_id,
                    asset_code, available_delta, reserved_delta, available_before, available_after,
                    reserved_before, reserved_after, category, source_type, source_ref, reservation_id,
                    metadata_json, previous_entry_sha256, entry_sha256, created_at_utc
             FROM mgw_ledger_entries
             WHERE account_ref = :account_ref AND asset_code = :asset_code
             ORDER BY ledger_sequence',
            ['account_ref' => $accountRef, 'asset_code' => $assetCode]
        );

        $errors = [];
        if ($balances === []) {
            $errors[] = ['code' => 'balance_missing', 'message' => 'Balance row is missing.'];
        }

        $previousHash = null;
        $previousAvailableAfter = null;
        $previousReservedAfter = null;
        $lastSequence = 0;

        foreach ($entries as $index => $entry) {
            $sequence = (int)($entry['ledger_sequence'] ?? 0);
            $label = ['entry_id' => (string)($entry['entry_id'] ?? ''), 'ledger_sequence' => $sequence];

            if ($sequence <= $lastSequence) {
                $errors[] = $label + ['code' => 'sequence_order', 'message' => 'Ledger sequence is not strictly increasing.'];
            }
            $lastSequence = $sequence;

            if ((string)($entry['account_ref'] ?? '') !== $accountRef
                || (string)($entry['asset_code'] ?? '') !== $assetCode) {
                $errors[] = $label + ['code' => 'scope_mismatch', 'message' => 'Ledger entry scope does not match the requested chain.'];
            }

            $storedPrevious = $this->nullable($entry['previous_entry_sha256'] ?? null);
            if ($storedPrevious !== $previousHash) {
                $errors[] = $label + ['code' => 'previous_hash_mismatch', 'message' => 'Previous ledger hash does not match.'];
            }

            $storedHash = strtolower((string)($entry['entry_sha256'] ?? ''));
            $computedHash = LedgerIntegrity::entryHash($entry);
            if (preg_match('/^[a-f0-9]{64}$/', $storedHash) !== 1 || !hash_equals($storedHash, $computedHash)) {
                $errors[] = $label + ['code' => 'entry_hash_mismatch', 'message' => 'Ledger entry integrity hash is invalid.'];
            }

            $availableDelta = (int)$entry['available_delta'];
            $reservedDelta = (int)$entry['reserved_delta'];
            $availableBefore = (int)$entry['available_before'];
            $availableAfter = (int)$entry['available_after'];
            $reservedBefore = (int)$entry['reserved_before'];
            $reservedAfter = (int)$entry['reserved_after'];

            if ($availableAfter !== $availableBefore + $availableDelta
                || $reservedAfter !== $reservedBefore + $reservedDelta) {
                $errors[] = $label + ['code' => 'arithmetic_mismatch', 'message' => 'Ledger arithmetic does not reconcile.'];
            }
            if ($availableBefore < 0 || $availableAfter < 0 || $reservedBefore < 0 || $reservedAfter < 0) {
                $errors[] = $label + ['code' => 'negative_amount', 'message' => 'Ledger contains a negative balance state.'];
            }
            if ($index > 0 && ($availableBefore !== $previousAvailableAfter || $reservedBefore !== $previousReservedAfter)) {
                $errors[] = $label + ['code' => 'balance_continuity', 'message' => 'Ledger balance continuity is broken.'];
            }

            $previousHash = $storedHash;
            $previousAvailableAfter = $availableAfter;
            $previousReservedAfter = $reservedAfter;
        }

        $balance = $balances === [] ? null : [
            'account_ref' => (string)$balances[0]['account_ref'],
            'asset_code' => (string)$balances[0]['asset_code'],
            'available_amount' => (int)$balances[0]['available_amount'],
            'reserved_amount' => (int)$balances[0]['reserved_amount'],
            'version' => (int)$balances[0]['version'],
        ];
        if ($balance !== null && $entries !== []) {
            if ($balance['available_amount'] !== $previousAvailableAfter
                || $balance['reserved_amount'] !== $previousReservedAfter) {
                $errors[] = ['code' => 'balance_head_mismatch', 'message' => 'Current balance does not match the ledger head.'];
            }
        }
        if ($balance !== null && $entries === []
            && ($balance['available_amount'] !== 0 || $balance['reserved_amount'] !== 0)) {
            $errors[] = ['code' => 'missing_ledger_history', 'message' => 'Non-zero balance has no ledger history.'];
        }

        return [
            'ok' => $errors === [],
            'account_ref' => $accountRef,
            'asset_code' => $assetCode,
            'entry_count' => count($entries),
            'last_entry_sha256' => $previousHash,
            'balance' => $balance,
            'errors' => $errors,
        ];
    }

    public function verifyReservation(string $reservationId): array
    {
        $reservationId = $this->text($reservationId, 96);
        if ($reservationId === '') throw new InvalidArgumentException('reservation_id is required.');

        $reservations = $this->database->fetchAll(
            'SELECT reservation_id, account_ref, asset_code, amount, status
             FROM mgw_reservations WHERE reservation_id = :reservation_id',
            ['reservation_id' => $reservationId]
        );
        $events = $this->database->fetchAll(
            'SELECT event_sequence, event_id, reservation_id, event_key, event_type,
                    available_delta, reserved_delta, metadata_json, event_sha256, created_at_utc
             FROM mgw_reservation_events
             WHERE reservation_id = :reservation_id ORDER BY event_sequence',
            ['reservation_id' => $reservationId]
        );

        $errors = [];
        if ($reservations === []) {
            $errors[] = ['code' => 'reservation_missing', 'message' => 'Reservation row is missing.'];
        }
        if ($reservations !== [] && $events === []) {
            $errors[] = ['code' => 'reservation_events_missing', 'message' => 'Reservation has no audit events.'];
        }

        $lastSequence = 0;
        foreach ($events as $event) {
            $sequence = (int)($event['event_sequence'] ?? 0);
            $label = ['event_id' => (string)($event['event_id'] ?? ''), 'event_sequence' => $sequence];
            if ($sequence <= $lastSequence) {
                $errors[] = $label + ['code' => 'event_sequence_order', 'message' => 'Reservation event sequence is not strictly increasing.'];
            }
            $lastSequence = $sequence;
            if ((string)($event['reservation_id'] ?? '') !== $reservationId) {
                $errors[] = $label + ['code' => 'reservation_scope_mismatch', 'message' => 'Reservation event points to another reservation.'];
            }

            $storedHash = strtolower((string)($event['event_sha256'] ?? ''));
            $computedHash = LedgerIntegrity::reservationEventHash($event);
            if (preg_match('/^[a-f0-9]{64}$/', $storedHash) !== 1 || !hash_equals($storedHash, $computedHash)) {
                $errors[] = $label + ['code' => 'event_hash_mismatch', 'message' => 'Reservation event integrity hash is invalid.'];
            }
        }

        return [
            'ok' => $errors === [],
            'reservation_id' => $reservationId,
            'reservation' => $reservations === [] ? null : [
                'account_ref' => (string)$reservations[0]['account_ref'],
                'asset_code' => (string)$reservations[0]['asset_code'],
                'amount' => (int)$reservations[0]['amount'],
                'status' => (string)$reservations[0]['status'],
            ],
            'event_count' => count($events),
            'errors' => $errors,
        ];
    }

    private function text(string $value, int $maxLength): string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '');
        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : strtolower($value);
    }
}
