package com.minigamesworld.app.platform;

/**
 * Optional ad presentation seam. Reward settlement is deliberately not part
 * of this adapter so provider callbacks cannot mutate MGW economy directly.
 */
public interface AdsAdapter extends PlatformAdapter {
    enum ShowResult {
        UNAVAILABLE,
        SHOWN
    }

    ShowResult showRewarded(String placementId);
}
