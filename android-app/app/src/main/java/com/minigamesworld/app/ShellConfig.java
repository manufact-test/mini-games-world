package com.minigamesworld.app;

import java.net.URI;
import java.net.URISyntaxException;

final class ShellConfig {
    private ShellConfig() {
    }

    static String configuredBaseUrl() {
        return BuildConfig.MGW_BASE_URL == null ? "" : BuildConfig.MGW_BASE_URL.trim();
    }

    static String androidAuthUrl(String configuredBaseUrl) {
        try {
            URI base = new URI(configuredBaseUrl == null ? "" : configuredBaseUrl.trim());
            if (!"https".equalsIgnoreCase(base.getScheme())
                    || base.getHost() == null
                    || base.getHost().isBlank()
                    || base.getUserInfo() != null) {
                return "";
            }
            return new URI(
                    "https",
                    null,
                    base.getHost(),
                    base.getPort(),
                    "/bot/android-auth.php",
                    null,
                    null
            ).toASCIIString();
        } catch (URISyntaxException ignored) {
            return "";
        }
    }
}
