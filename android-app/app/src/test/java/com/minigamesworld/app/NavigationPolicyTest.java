package com.minigamesworld.app;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import org.junit.Test;

public final class NavigationPolicyTest {
    private final NavigationPolicy policy = new NavigationPolicy("https://example.com/app/");

    @Test
    public void baseUrlMustBeAbsoluteHttpsWithoutCredentials() {
        assertTrue(NavigationPolicy.isSafeHttpsBase("https://example.com/app/"));
        assertFalse(NavigationPolicy.isSafeHttpsBase("http://example.com/app/"));
        assertFalse(NavigationPolicy.isSafeHttpsBase("https://user:pass@example.com/app/"));
        assertFalse(NavigationPolicy.isSafeHttpsBase("javascript:alert(1)"));
        assertFalse(NavigationPolicy.isSafeHttpsBase(""));
    }

    @Test
    public void sameOriginHttpsRemainsInsideContainer() {
        assertTrue(policy.isInternal("https://example.com/other/path?x=1"));
        assertFalse(policy.isInternal("https://other.example.com/app/"));
        assertFalse(policy.isInternal("http://example.com/app/"));
        assertFalse(policy.isInternal("file:///etc/passwd"));
    }

    @Test
    public void privilegedOrScriptSchemesNeverOpenExternally() {
        assertFalse(policy.mayOpenExternally("file:///tmp/a"));
        assertFalse(policy.mayOpenExternally("content://provider/a"));
        assertFalse(policy.mayOpenExternally("javascript:alert(1)"));
        assertFalse(policy.mayOpenExternally("data:text/html,hello"));
        assertFalse(policy.mayOpenExternally("intent://example/#Intent;scheme=https;end"));
    }

    @Test
    public void ordinaryExternalTargetsMayLeaveTheContainer() {
        assertTrue(policy.mayOpenExternally("https://openai.com/"));
        assertTrue(policy.mayOpenExternally("mailto:test@example.com"));
        assertTrue(policy.mayOpenExternally("tel:+123456789"));
        assertTrue(policy.mayOpenExternally("tg://resolve?domain=example"));
    }

    @Test
    public void telegramShareUsesDirectNativeDeepLinkWithoutBrowserHop() {
        assertEquals(
                "tg://msg_url?url=https%3A%2F%2Ft.me%2Fmgw_bot%3Fstart%3Dinvite_abc&text=hello",
                policy.nativeTelegramShareDeepLink(
                        "https://t.me/share/url?url=https%3A%2F%2Ft.me%2Fmgw_bot%3Fstart%3Dinvite_abc&text=hello"
                )
        );
        assertEquals(null, policy.nativeTelegramShareDeepLink("https://t.me/example"));
        assertEquals(null, policy.nativeTelegramShareDeepLink("https://evil.example/share/url?url=x"));
        assertEquals(null, policy.nativeTelegramShareDeepLink("tg://msg_url?url=x"));
    }

    @Test
    public void nativeReauthRouteIsExactAndDoesNotBecomeAnExternalScheme() {
        assertEquals(
                "ar_0123456789abcdef01234567",
                policy.nativeReauthChallenge("mgw://android-reauth?challenge=ar_0123456789abcdef01234567")
        );
        assertEquals(null, policy.nativeReauthChallenge("mgw://android-reauth?challenge=bad"));
        assertEquals(null, policy.nativeReauthChallenge("mgw://android-reauth/other?challenge=ar_0123456789abcdef01234567"));
        assertEquals(null, policy.nativeReauthChallenge("mgw://other?challenge=ar_0123456789abcdef01234567"));
        assertEquals(null, policy.nativeReauthChallenge("mgw://android-reauth?challenge=ar_0123456789abcdef01234567&next=https://evil.example"));
        assertFalse(policy.mayOpenExternally("mgw://android-reauth?challenge=ar_0123456789abcdef01234567"));
    }

    @Test
    public void nativeAccountDownloadRouteIsExactAndDoesNotBecomeExternal() {
        assertEquals(
                "adr_0123456789abcdef0123456789abcdef",
                policy.nativeAccountDownloadRequest("mgw://android-account-download?request=adr_0123456789abcdef0123456789abcdef")
        );
        assertEquals(null, policy.nativeAccountDownloadRequest("mgw://android-account-download?request=bad"));
        assertEquals(null, policy.nativeAccountDownloadRequest("mgw://android-account-download/other?request=adr_0123456789abcdef0123456789abcdef"));
        assertEquals(null, policy.nativeAccountDownloadRequest("mgw://other?request=adr_0123456789abcdef0123456789abcdef"));
        assertEquals(null, policy.nativeAccountDownloadRequest("mgw://android-account-download?request=adr_0123456789abcdef0123456789abcdef&next=https://evil.example"));
        assertFalse(policy.mayOpenExternally("mgw://android-account-download?request=adr_0123456789abcdef0123456789abcdef"));
    }

    @Test
    public void publicInviteLandingIsExactSameOriginDeepLink() {
        assertEquals(
                "0123456789abcdef01234567",
                policy.inviteTokenFromLanding("https://example.com/invite/0123456789abcdef01234567")
        );
        assertEquals(
                "0123456789abcdef01234567",
                policy.inviteTokenFromLanding("https://example.com/invite/0123456789abcdef01234567/")
        );
        assertEquals(null, policy.inviteTokenFromLanding("https://other.example.com/invite/0123456789abcdef01234567"));
        assertEquals(null, policy.inviteTokenFromLanding("http://example.com/invite/0123456789abcdef01234567"));
        assertEquals(null, policy.inviteTokenFromLanding("https://example.com/invite/bad"));
        assertEquals(null, policy.inviteTokenFromLanding("https://example.com/invite/0123456789abcdef01234567?next=https://evil.example"));
        assertEquals(null, policy.inviteTokenFromLanding("https://example.com/app/v110.php?invite=0123456789abcdef01234567"));
    }

    @Test
    public void unsafeIncomingDeepLinkFallsBackToConfiguredBase() {
        assertEquals("https://example.com/game/1", policy.initialUrl("https://example.com/game/1"));
        assertEquals("https://example.com/app/", policy.initialUrl("https://evil.example/game/1"));
        assertEquals("https://example.com/app/", policy.initialUrl("javascript:alert(1)"));
    }
}
