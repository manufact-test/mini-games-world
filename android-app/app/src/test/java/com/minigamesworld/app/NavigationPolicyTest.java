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
    public void unsafeIncomingDeepLinkFallsBackToConfiguredBase() {
        assertEquals("https://example.com/game/1", policy.initialUrl("https://example.com/game/1"));
        assertEquals("https://example.com/app/", policy.initialUrl("https://evil.example/game/1"));
        assertEquals("https://example.com/app/", policy.initialUrl("javascript:alert(1)"));
    }
}
