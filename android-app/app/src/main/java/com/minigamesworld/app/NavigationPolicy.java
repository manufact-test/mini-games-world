package com.minigamesworld.app;

import java.net.URI;
import java.net.URISyntaxException;
import java.util.Locale;
import java.util.Set;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

final class NavigationPolicy {
    private static final Set<String> EXTERNAL_SCHEMES = Set.of("https", "http", "mailto", "tel", "tg");
    private static final Set<String> BLOCKED_SCHEMES = Set.of("file", "content", "javascript", "data", "intent");
    private static final Pattern REAUTH_CHALLENGE = Pattern.compile("^ar_[a-f0-9]{24}$");
    private static final Pattern ACCOUNT_DATA_REQUEST = Pattern.compile("^adr_[a-f0-9]{32}$");
    private static final Pattern INVITE_LANDING_PATH = Pattern.compile("^/invite/([a-f0-9]{24})/?$");

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

    String nativeReauthChallenge(String candidate) {
        URI uri = parse(candidate);
        if (uri == null
                || !"mgw".equalsIgnoreCase(uri.getScheme())
                || !"android-reauth".equalsIgnoreCase(uri.getHost())
                || uri.getUserInfo() != null
                || uri.getPort() >= 0
                || uri.getFragment() != null) {
            return null;
        }

        String path = uri.getPath();
        if (path != null && !path.isEmpty() && !"/".equals(path)) {
            return null;
        }

        String query = uri.getRawQuery();
        if (query == null || !query.startsWith("challenge=") || query.indexOf('&') >= 0) {
            return null;
        }
        String challenge = query.substring("challenge=".length());
        return REAUTH_CHALLENGE.matcher(challenge).matches() ? challenge : null;
    }

    String nativeAccountDownloadRequest(String candidate) {
        URI uri = parse(candidate);
        if (uri == null
                || !"mgw".equalsIgnoreCase(uri.getScheme())
                || !"android-account-download".equalsIgnoreCase(uri.getHost())
                || uri.getUserInfo() != null
                || uri.getPort() >= 0
                || uri.getFragment() != null) {
            return null;
        }

        String path = uri.getPath();
        if (path != null && !path.isEmpty() && !"/".equals(path)) {
            return null;
        }

        String query = uri.getRawQuery();
        if (query == null || !query.startsWith("request=") || query.indexOf('&') >= 0) {
            return null;
        }
        String requestId = query.substring("request=".length());
        return ACCOUNT_DATA_REQUEST.matcher(requestId).matches() ? requestId : null;
    }

    String inviteTokenFromLanding(String candidate) {
        URI uri = parse(candidate);
        if (uri == null
                || !"https".equalsIgnoreCase(uri.getScheme())
                || uri.getUserInfo() != null
                || uri.getRawQuery() != null
                || uri.getFragment() != null
                || !sameOrigin(baseUri, uri)) {
            return null;
        }

        String path = uri.getPath();
        Matcher matcher = INVITE_LANDING_PATH.matcher(path == null ? "" : path);
        return matcher.matches() ? matcher.group(1) : null;
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
