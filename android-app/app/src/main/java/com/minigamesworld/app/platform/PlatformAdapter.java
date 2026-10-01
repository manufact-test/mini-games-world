package com.minigamesworld.app.platform;

/**
 * Provider-neutral Android platform boundary.
 *
 * External provider availability is never allowed to become an ownership
 * signal for MGW gameplay, economy, account or inventory state.
 */
public interface PlatformAdapter {
    enum Mode {
        DISABLED,
        MOCK,
        PROVIDER
    }

    String name();

    Mode mode();

    default boolean isEnabled() {
        return mode() != Mode.DISABLED;
    }
}
