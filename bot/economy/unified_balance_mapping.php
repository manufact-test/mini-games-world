<?php
declare(strict_types=1);

/**
 * MVP-15.3 staging conversion decision approved by the product owner on
 * 2026-08-15. Match and Gold are test-only balances in the current staging
 * environment, so both legacy units migrate 1:1 into the single MGW coin.
 *
 * This file is product configuration, not a secret. Changing any rate requires
 * a new version and a fresh explicit product decision; never edit a live version
 * in place after migration has started.
 */
return [
    'approved' => true,
    'version' => 'mvp15.3-staging-1to1-v1',
    'target_asset' => 'mgw_coin',
    'approved_by' => 'product-owner',
    'approved_at_utc' => '2026-08-15T08:55:00Z',
    'rates' => [
        'match_coin' => ['numerator' => 1, 'denominator' => 1],
        'gold_coin' => ['numerator' => 1, 'denominator' => 1],
    ],
];
