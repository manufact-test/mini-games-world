package com.minigamesworld.app.platform;

/**
 * Optional platform-push registration seam.
 *
 * In-app MGW notifications remain a product capability even while this adapter
 * is disabled.
 */
public interface PushAdapter extends PlatformAdapter {
    enum RegistrationResult {
        UNAVAILABLE,
        REGISTERED
    }

    RegistrationResult ensureRegistered();
}
