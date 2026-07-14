<?php
declare(strict_types=1);

require_once __DIR__ . '/invites/GameInviteCreationTrait.php';
require_once __DIR__ . '/invites/GameInviteActionTrait.php';
require_once __DIR__ . '/invites/GameInviteStorageTrait.php';
require_once __DIR__ . '/invites/GameInviteValidationTrait.php';

final class GameInviteService
{
    use GameInviteCreationTrait;
    use GameInviteActionTrait;
    use GameInviteStorageTrait;
    use GameInviteValidationTrait;

    private const INVITE_TTL_SEC = 900;
    private const READY_TTL_SEC = 90;
    private const RETENTION_SEC = 604800;

    public function __construct(
        private array $config,
        private GameCatalogService $catalog,
        private ChessRuntimeService $games
    ) {}
}
