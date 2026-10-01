package com.minigamesworld.app.platform;

import java.util.Map;

/**
 * Optional telemetry seam. Analytics is observational only and must not own
 * product decisions or canonical state.
 */
public interface AnalyticsAdapter extends PlatformAdapter {
    void track(String eventName, Map<String, String> properties);
}
