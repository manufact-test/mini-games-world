package com.minigamesworld.app.platform;

import java.util.Optional;

/**
 * Optional device/app-integrity proof seam. Disabled integrity must never block
 * ordinary MGW product use before the later provider-integration roadmap.
 */
public interface IntegrityAdapter extends PlatformAdapter {
    Optional<String> requestToken(String nonce);
}
