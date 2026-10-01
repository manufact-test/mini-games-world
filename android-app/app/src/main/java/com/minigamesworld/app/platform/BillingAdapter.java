package com.minigamesworld.app.platform;

/**
 * Transport-only purchase launch seam.
 *
 * A provider implementation may start a purchase UI later, but it must never
 * credit MGW balance/inventory directly. Canonical settlement remains server-owned.
 */
public interface BillingAdapter extends PlatformAdapter {
    enum LaunchResult {
        UNAVAILABLE,
        STARTED
    }

    LaunchResult launchPurchase(String productId);
}
