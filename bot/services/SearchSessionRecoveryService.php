<?php
declare(strict_types=1);

final class SearchSessionRecoveryService
{
    public static function repairOrphanedSearch(array $data, array &$user): bool
    {
        if ((string)($user['status'] ?? 'idle') !== 'searching') {
            return false;
        }

        $userId = (string)($user['id'] ?? '');
        if ($userId === '') {
            return false;
        }

        foreach ($data['queue'] ?? [] as $item) {
            if (is_array($item) && (string)($item['user_id'] ?? '') === $userId) {
                return false;
            }
        }

        $user['status'] = 'idle';
        $user['current_game_id'] = null;
        return true;
    }
}
