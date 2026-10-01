package com.minigamesworld.app.platform;

import java.util.Optional;

/**
 * Optional provider-specific attribution/deep-link seam.
 *
 * Existing same-origin MGW navigation stays owned by NavigationPolicy and is
 * intentionally not delegated to this disabled provider adapter.
 */
public interface DeepLinkAdapter extends PlatformAdapter {
    Optional<String> resolveProviderLink(String rawLink);
}
