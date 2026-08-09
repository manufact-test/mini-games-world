package com.minigamesworld.app;

import java.net.URI;
import java.net.URISyntaxException;
import java.util.Locale;
import java.util.Set;

final class NavigationPolicy {
    private static final Set<String> EXTERNAL_SCHEMES = Set.of("https", "http", "mailto", "tel", "tg");
    private static final Set<String> BLOCKED_SCHEMES = Set.of("file", "content", "javascript", "data", "intent");

    private final URI baseUri;

    NavigationPolicy(String configuredBaseUrl) {
        this.baseUri = requireSafeHttpsBase(configuredBaseUrl);
    }

    static boolean isSafeHttpsBase(String candidate) {
        try {
            requireSafeHttpsBase(candidate);
            return true;
        } catch (IllegalArgumentException ignored) {
            return false;
        }
    }

    boolean isInternal(String candidate) {
        URI uri = parse(candidate);
        if (uri == null || !"https".equalsIgnoreCase(uri.getScheme())) {
            return false;
        }
        if (uri.getUserInfo() != null) {
            return false;
        }
        return sameOrigin(baseUri, uri);
    }

    boolean mayOpenExternally(String candidate) {
        URI uri = parse(candidate);
        if (uri == null || uri.getScheme() == null) {
            return false;
        }
        String scheme = uri.getScheme().toLowerCase(Locale.ROOT);
        if (BLOCKED_SCHEMES.contains(scheme)) {
            return false;
        }
        if (!EXTERNAL_SCHEMES.contains(scheme)) {
            return false;
        }
        if ("http".equals(scheme) || "https".equals(scheme)) {
            return uri.getHost() != null && !uri.getHost().isBlank() && uri.getUserInfo() == null;
        }
        return true;
    }

    String initialUrl(String incomingUrl) {
        if (incomingUrl != null && isInternal(incomingUrl)) {
            return incomingUrl;
        }
        return baseUri.toASCIIString();
    }

    private static URI requireSafeHttpsBase(String candidate) {
        URI uri = parse(candidate);
        if (uri == null
                || !"https".equalsIgnoreCase(uri.getScheme())
                || uri.getHost() == null
                || uri.getHost().isBlank()
                || uri.getUserInfo() != null) {
            throw new IllegalArgumentException("MGW_BASE_URL must be an absolute HTTPS URL without embedded credentials.");
        }
        return uri.normalize();
    }

    private static URI parse(String candidate) {
        if (candidate == null || candidate.isBlank()) {
            return null;
        }
        try {
            return new URI(candidate.trim());
        } catch (URISyntaxException ignored) {
            return null;
        }
    }

    private static boolean sameOrigin(URI left, URI right) {
        if (!left.getScheme().equalsIgnoreCase(right.getScheme())) {
            return false;
        }
        if (!left.getHost().equalsIgnoreCase(right.getHost())) {
            return false;
        }
        return effectivePort(left) == effectivePort(right);
    }

    private static int effectivePort(URI uri) {
        if (uri.getPort() >= 0) {
            return uri.getPort();
        }
        return "https".equalsIgnoreCase(uri.getScheme()) ? 443 : 80;
    }
}
