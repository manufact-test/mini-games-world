package com.minigamesworld.app.platform;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import java.util.Collections;

import org.junit.Test;

public final class PlatformAdaptersTest {
    @Test
    public void defaultRegistryKeepsEveryProviderDisabled() {
        PlatformAdapters adapters = PlatformAdapters.disabled();

        assertTrue(adapters.allDisabled());
        assertFalse(adapters.billing().isEnabled());
        assertFalse(adapters.ads().isEnabled());
        assertFalse(adapters.push().isEnabled());
        assertFalse(adapters.integrity().isEnabled());
        assertFalse(adapters.analytics().isEnabled());
        assertFalse(adapters.deepLink().isEnabled());
    }

    @Test
    public void disabledBillingAndAdsCannotStartProviderFlows() {
        PlatformAdapters adapters = PlatformAdapters.disabled();

        assertEquals(BillingAdapter.LaunchResult.UNAVAILABLE, adapters.billing().launchPurchase("sku"));
        assertEquals(AdsAdapter.ShowResult.UNAVAILABLE, adapters.ads().showRewarded("reward"));
    }

    @Test
    public void disabledPushAndIntegrityStayUnavailable() {
        PlatformAdapters adapters = PlatformAdapters.disabled();

        assertEquals(PushAdapter.RegistrationResult.UNAVAILABLE, adapters.push().ensureRegistered());
        assertTrue(adapters.integrity().requestToken("nonce").isEmpty());
    }

    @Test
    public void disabledAnalyticsAndProviderDeepLinksAreSafeNoOps() {
        PlatformAdapters adapters = PlatformAdapters.disabled();

        adapters.analytics().track("cold_start", Collections.singletonMap("surface", "android"));
        assertTrue(adapters.deepLink().resolveProviderLink("https://example.invalid/path").isEmpty());
    }
}
