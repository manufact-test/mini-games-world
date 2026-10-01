package com.minigamesworld.app.platform;

import java.util.Map;
import java.util.Optional;

/**
 * Android platform adapter registry.
 *
 * MVP-26.5 intentionally ships a disabled registry. Provider SDKs are not
 * dependencies of the Android app, and the complete MGW product must continue
 * to work with every adapter disabled.
 */
public final class PlatformAdapters {
    private final BillingAdapter billing;
    private final AdsAdapter ads;
    private final PushAdapter push;
    private final IntegrityAdapter integrity;
    private final AnalyticsAdapter analytics;
    private final DeepLinkAdapter deepLink;

    private PlatformAdapters(
            BillingAdapter billing,
            AdsAdapter ads,
            PushAdapter push,
            IntegrityAdapter integrity,
            AnalyticsAdapter analytics,
            DeepLinkAdapter deepLink
    ) {
        this.billing = billing;
        this.ads = ads;
        this.push = push;
        this.integrity = integrity;
        this.analytics = analytics;
        this.deepLink = deepLink;
    }

    public static PlatformAdapters disabled() {
        return DisabledHolder.INSTANCE;
    }

    public BillingAdapter billing() {
        return billing;
    }

    public AdsAdapter ads() {
        return ads;
    }

    public PushAdapter push() {
        return push;
    }

    public IntegrityAdapter integrity() {
        return integrity;
    }

    public AnalyticsAdapter analytics() {
        return analytics;
    }

    public DeepLinkAdapter deepLink() {
        return deepLink;
    }

    public boolean allDisabled() {
        return billing.mode() == PlatformAdapter.Mode.DISABLED
                && ads.mode() == PlatformAdapter.Mode.DISABLED
                && push.mode() == PlatformAdapter.Mode.DISABLED
                && integrity.mode() == PlatformAdapter.Mode.DISABLED
                && analytics.mode() == PlatformAdapter.Mode.DISABLED
                && deepLink.mode() == PlatformAdapter.Mode.DISABLED;
    }

    private static final class DisabledHolder {
        private static final PlatformAdapters INSTANCE = new PlatformAdapters(
                new DisabledBillingAdapter(),
                new DisabledAdsAdapter(),
                new DisabledPushAdapter(),
                new DisabledIntegrityAdapter(),
                new DisabledAnalyticsAdapter(),
                new DisabledDeepLinkAdapter()
        );
    }

    private abstract static class DisabledAdapter implements PlatformAdapter {
        private final String name;

        private DisabledAdapter(String name) {
            this.name = name;
        }

        @Override
        public final String name() {
            return name;
        }

        @Override
        public final Mode mode() {
            return Mode.DISABLED;
        }
    }

    private static final class DisabledBillingAdapter extends DisabledAdapter implements BillingAdapter {
        private DisabledBillingAdapter() {
            super("billing");
        }

        @Override
        public LaunchResult launchPurchase(String productId) {
            return LaunchResult.UNAVAILABLE;
        }
    }

    private static final class DisabledAdsAdapter extends DisabledAdapter implements AdsAdapter {
        private DisabledAdsAdapter() {
            super("ads");
        }

        @Override
        public ShowResult showRewarded(String placementId) {
            return ShowResult.UNAVAILABLE;
        }
    }

    private static final class DisabledPushAdapter extends DisabledAdapter implements PushAdapter {
        private DisabledPushAdapter() {
            super("push");
        }

        @Override
        public RegistrationResult ensureRegistered() {
            return RegistrationResult.UNAVAILABLE;
        }
    }

    private static final class DisabledIntegrityAdapter extends DisabledAdapter implements IntegrityAdapter {
        private DisabledIntegrityAdapter() {
            super("integrity");
        }

        @Override
        public Optional<String> requestToken(String nonce) {
            return Optional.empty();
        }
    }

    private static final class DisabledAnalyticsAdapter extends DisabledAdapter implements AnalyticsAdapter {
        private DisabledAnalyticsAdapter() {
            super("analytics");
        }

        @Override
        public void track(String eventName, Map<String, String> properties) {
            // Deliberate no-op. Never buffer provider-bound telemetry while disabled.
        }
    }

    private static final class DisabledDeepLinkAdapter extends DisabledAdapter implements DeepLinkAdapter {
        private DisabledDeepLinkAdapter() {
            super("deep_link");
        }

        @Override
        public Optional<String> resolveProviderLink(String rawLink) {
            return Optional.empty();
        }
    }
}
