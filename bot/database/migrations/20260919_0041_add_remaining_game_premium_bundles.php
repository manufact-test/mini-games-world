<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    private const BUNDLES = [
        'chess-premium-bundle' => [
            'game'=>'chess','sort'=>1040,'members'=>[
                'game-chess-board-neon','game-chess-pieces-neon',
                'game-chess-effect-move','game-chess-effect-capture','game-chess-effect-check',
            ],
        ],
        'reversi-premium-bundle' => [
            'game'=>'reversi','sort'=>1060,'members'=>[
                'game-reversi-field-neon','game-reversi-pieces-neon',
                'game-reversi-effect-placement','game-reversi-effect-line','game-reversi-effect-mass-flip',
            ],
        ],
        'go-premium-bundle' => [
            'game'=>'go','sort'=>1070,'members'=>[
                'game-go-board-neon','game-go-stones-neon',
                'game-go-effect-placement','game-go-effect-group-capture','game-go-effect-territory-finish',
            ],
        ],
        'domino-premium-bundle' => [
            'game'=>'domino','sort'=>1080,'members'=>[
                'game-domino-table-neon','game-domino-tiles-neon',
                'game-domino-effect-precision-drop','game-domino-effect-stock-pulse','game-domino-effect-chain-finale',
            ],
        ],
        'four-in-a-row-premium-bundle' => [
            'game'=>'four_in_a_row','sort'=>1090,'members'=>[
                'game-four-field-neon','game-four-discs-neon',
                'game-four-effect-drop','game-four-effect-four','game-four-effect-victory-wave',
            ],
        ],
        'battleship-premium-bundle' => [
            'game'=>'battleship','sort'=>1100,'members'=>[
                'game-battleship-map-neon','game-battleship-fleet-neon',
                'game-battleship-effect-shot','game-battleship-effect-hit','game-battleship-effect-destroy',
            ],
        ],
    ];

    public function version(): string { return '20260919_0041_add_remaining_game_premium_bundles'; }
    public function description(): string { return 'Add canonical five-item premium bundles for the six remaining games.'; }
    public function transactional(): bool { return false; }

    public function up(DatabaseConnectionInterface $database): void
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $prefix = $database->driver() === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        foreach (self::BUNDLES as $offerId => $definition) {
            $members = json_encode($definition['members'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $database->execute(
                $prefix . ' INTO mgw_product_offers (
                    offer_id, offer_type, item_id, category, subcategory, price_coins,
                    partial_unit_price_coins, members_json, offer_status, sort_order,
                    created_at_utc, updated_at_utc
                 ) VALUES (
                    :offer_id, \'bundle\', NULL, \'games\', :subcategory, 34000,
                    NULL, :members_json, \'active\', :sort_order, :created_at, :updated_at
                 )',
                [
                    'offer_id'=>$offerId,
                    'subcategory'=>$definition['game'],
                    'members_json'=>$members,
                    'sort_order'=>$definition['sort'],
                    'created_at'=>$now,
                    'updated_at'=>$now,
                ]
            );
            $database->execute(
                "UPDATE mgw_product_offers
                 SET offer_type='bundle', item_id=NULL, category='games', subcategory=:subcategory,
                     price_coins=34000, partial_unit_price_coins=NULL, members_json=:members_json,
                     offer_status='active', sort_order=:sort_order, updated_at_utc=:updated_at
                 WHERE offer_id=:offer_id",
                [
                    'subcategory'=>$definition['game'],
                    'members_json'=>$members,
                    'sort_order'=>$definition['sort'],
                    'updated_at'=>$now,
                    'offer_id'=>$offerId,
                ]
            );
        }
    }
};
