package com.minigamesworld.app;

final class ShellConfig {
    private ShellConfig() {
    }

    static String configuredBaseUrl() {
        return BuildConfig.MGW_BASE_URL == null ? "" : BuildConfig.MGW_BASE_URL.trim();
    }
}
