package com.minigamesworld.app;

import android.annotation.SuppressLint;
import android.annotation.TargetApi;
import android.Manifest;
import android.app.Activity;
import android.app.DownloadManager;
import android.app.KeyguardManager;
import android.content.ActivityNotFoundException;
import android.content.ClipData;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.content.res.ColorStateList;
import android.content.res.Configuration;
import android.graphics.Color;
import android.net.Uri;
import android.net.http.SslError;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.view.Gravity;
import android.view.View;
import android.view.WindowInsets;
import android.view.ViewGroup;
import android.webkit.CookieManager;
import android.webkit.RenderProcessGoneDetail;
import android.webkit.SslErrorHandler;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.webkit.URLUtil;
import android.webkit.ValueCallback;
import android.widget.Button;
import android.widget.FrameLayout;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

import java.io.BufferedReader;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.net.URL;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;

import javax.net.ssl.HttpsURLConnection;

import org.json.JSONObject;

import com.minigamesworld.app.platform.PlatformAdapters;

public final class MainActivity extends Activity {
    private static final String STATE_WEBVIEW = "mgw_webview_state";
    private static final int REQUEST_ANDROID_REAUTH = 26041;
    private static final int REQUEST_LEGACY_DOWNLOAD_STORAGE = 26042;
    private static final int REQUEST_WEB_FILE_CHOOSER = 26043;
    private static final int REQUEST_LEGACY_WEB_DOWNLOAD_STORAGE = 26044;
    private static final String SHELL_PREFERENCES = "mgw_android_shell";
    private static final String ASSET_CACHE_VERSION_KEY = "shared_asset_cache_version";
    private static final String[] WEB_UPLOAD_MIME_TYPES = new String[]{
            "image/jpeg",
            "image/png",
            "image/webp",
            "image/gif",
            "application/pdf",
            "text/plain"
    };

    // MVP-26.5: all external platform providers are deliberately disabled by default.
    // The shared MGW product must remain complete without Google/commercial services.
    private final PlatformAdapters platformAdapters = PlatformAdapters.disabled();

    private FrameLayout root;
    private WebView webView;
    private LinearLayout loadingPanel;
    private ProgressBar loading;
    private LinearLayout errorPanel;
    private TextView errorTitle;
    private TextView errorText;
    private NavigationPolicy navigationPolicy;
    private DeviceCredentialStore credentialStore;
    private String configuredBaseUrl;
    private boolean mainFrameFailed;
    private boolean reauthInProgress;
    private String pendingReauthChallenge;
    private String pendingDownloadRequestId;
    private String pendingLaunchInviteToken;
    private ValueCallback<Uri[]> fileChooserCallback;
    private String pendingWebDownloadUrl;
    private String pendingWebDownloadUserAgent;
    private String pendingWebDownloadContentDisposition;
    private String pendingWebDownloadMimeType;
    private boolean refreshSharedAssetsForVersion;
    private Object backCallback;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        getWindow().setStatusBarColor(Color.BLACK);
        getWindow().setNavigationBarColor(Color.BLACK);

        buildShellUi();
        applySystemInsets();
        configureBackNavigation();

        SharedPreferences shellPreferences = getSharedPreferences(SHELL_PREFERENCES, MODE_PRIVATE);
        refreshSharedAssetsForVersion = shellPreferences.getInt(ASSET_CACHE_VERSION_KEY, -1)
                != BuildConfig.VERSION_CODE;

        configuredBaseUrl = ShellConfig.configuredBaseUrl();
        if (!NavigationPolicy.isSafeHttpsBase(configuredBaseUrl)
                || ShellConfig.androidAuthUrl(configuredBaseUrl).isEmpty()
                || ShellConfig.androidReauthUrl(configuredBaseUrl).isEmpty()
                || ShellConfig.accountDataDownloadUrl(
                        configuredBaseUrl,
                        "adr_0123456789abcdef0123456789abcdef"
                ).isEmpty()) {
            showConfigurationError();
            return;
        }

        navigationPolicy = new NavigationPolicy(configuredBaseUrl);
        credentialStore = new DeviceCredentialStore(this);
        configureWebView(webView);

        Bundle webState = savedInstanceState == null ? null : savedInstanceState.getBundle(STATE_WEBVIEW);
        if (webState != null && webView.restoreState(webState) != null) {
            showLoading(false);
            return;
        }

        loadInitialIntent(getIntent());
    }

    private void buildShellUi() {
        root = new FrameLayout(this);
        root.setBackgroundColor(Color.BLACK);
        attachFreshWebView();

        // Native-to-web handoff only. The system splash stays visually neutral,
        // this panel restores the accepted Shield King raster, then the shared
        // MGW animated web preloader becomes the visible loading owner.
        loadingPanel = new LinearLayout(this);
        loadingPanel.setOrientation(LinearLayout.VERTICAL);
        loadingPanel.setGravity(Gravity.CENTER);
        loadingPanel.setPadding(dp(28), dp(28), dp(28), dp(28));
        loadingPanel.setBackgroundColor(getColor(R.color.mgw_splash_background));

        ImageView brandMark = new ImageView(this);
        brandMark.setImageResource(R.drawable.ic_mgw_launcher_art);
        brandMark.setScaleType(ImageView.ScaleType.CENTER_INSIDE);
        brandMark.setImportantForAccessibility(View.IMPORTANT_FOR_ACCESSIBILITY_NO);
        LinearLayout.LayoutParams markParams = new LinearLayout.LayoutParams(dp(156), dp(156));
        markParams.gravity = Gravity.CENTER_HORIZONTAL;
        loadingPanel.addView(brandMark, markParams);

        TextView loadingText = new TextView(this);
        loadingText.setText(R.string.loading);
        loadingText.setTextColor(getColor(R.color.mgw_brand_silver));
        loadingText.setAlpha(0.72f);
        loadingText.setTextSize(14f);
        loadingText.setGravity(Gravity.CENTER);
        loadingText.setAccessibilityLiveRegion(View.ACCESSIBILITY_LIVE_REGION_POLITE);
        LinearLayout.LayoutParams loadingTextParams = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT
        );
        loadingTextParams.topMargin = dp(12);
        loadingPanel.addView(loadingText, loadingTextParams);

        loading = new ProgressBar(this);
        loading.setIndeterminateTintList(ColorStateList.valueOf(getColor(R.color.mgw_brand_violet)));
        loading.setImportantForAccessibility(View.IMPORTANT_FOR_ACCESSIBILITY_NO);
        LinearLayout.LayoutParams loadingParams = new LinearLayout.LayoutParams(dp(32), dp(32));
        loadingParams.gravity = Gravity.CENTER_HORIZONTAL;
        loadingParams.topMargin = dp(18);
        loadingPanel.addView(loading, loadingParams);

        root.addView(loadingPanel, new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT
        ));

        errorPanel = new LinearLayout(this);
        errorPanel.setOrientation(LinearLayout.VERTICAL);
        errorPanel.setGravity(Gravity.CENTER);
        errorPanel.setPadding(dp(32), dp(32), dp(32), dp(32));
        errorPanel.setBackgroundColor(Color.BLACK);
        errorPanel.setVisibility(View.GONE);

        errorTitle = new TextView(this);
        errorTitle.setTextColor(Color.WHITE);
        errorTitle.setTextSize(20f);
        errorTitle.setGravity(Gravity.CENTER);
        errorTitle.setAccessibilityLiveRegion(View.ACCESSIBILITY_LIVE_REGION_ASSERTIVE);
        errorPanel.addView(errorTitle, new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT
        ));

        errorText = new TextView(this);
        errorText.setTextColor(0xFFCCCCCC);
        errorText.setTextSize(15f);
        errorText.setGravity(Gravity.CENTER);
        LinearLayout.LayoutParams textParams = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT
        );
        textParams.topMargin = dp(12);
        errorPanel.addView(errorText, textParams);

        Button retry = new Button(this);
        retry.setText(R.string.retry);
        retry.setOnClickListener(view -> retryCurrentPage());
        LinearLayout.LayoutParams buttonParams = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT,
                ViewGroup.LayoutParams.WRAP_CONTENT
        );
        buttonParams.topMargin = dp(20);
        errorPanel.addView(retry, buttonParams);

        root.addView(errorPanel, new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT
        ));

        setContentView(root);
    }

    private void attachFreshWebView() {
        WebView replacement = new WebView(this);
        replacement.setBackgroundColor(Color.BLACK);
        replacement.setImportantForAccessibility(View.IMPORTANT_FOR_ACCESSIBILITY_YES);
        root.addView(replacement, 0, new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT
        ));
        webView = replacement;
    }

    @SuppressWarnings("deprecation")
    private void applySystemInsets() {
        root.setOnApplyWindowInsetsListener((view, insets) -> {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
                Api30Insets.apply(view, insets);
            } else {
                view.setPadding(
                        insets.getSystemWindowInsetLeft(),
                        insets.getSystemWindowInsetTop(),
                        insets.getSystemWindowInsetRight(),
                        insets.getSystemWindowInsetBottom()
                );
            }
            return insets;
        });
        root.requestApplyInsets();
    }

    private void configureWebView(WebView target) {
        WebSettings settings = target.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        settings.setAllowFileAccess(false);
        settings.setAllowContentAccess(false);
        settings.setJavaScriptCanOpenWindowsAutomatically(false);
        settings.setSupportMultipleWindows(false);
        settings.setMediaPlaybackRequiresUserGesture(true);
        settings.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);
        settings.setBuiltInZoomControls(false);
        settings.setDisplayZoomControls(false);
        settings.setGeolocationEnabled(false);
        settings.setSafeBrowsingEnabled(true);
        if (refreshSharedAssetsForVersion) {
            // Shared Android parity patches intentionally keep Telegram asset
            // specifiers frozen. On the first launch of a new APK, force one
            // fresh shared-runtime load without touching cookies/session state.
            settings.setCacheMode(WebSettings.LOAD_NO_CACHE);
            target.clearCache(true);
        }
        String userAgent = settings.getUserAgentString();
        if (userAgent != null && !userAgent.contains("MiniGamesWorldAndroid/")) {
            settings.setUserAgentString(userAgent + " MiniGamesWorldAndroid/" + BuildConfig.VERSION_CODE);
        }

        CookieManager cookieManager = CookieManager.getInstance();
        cookieManager.setAcceptCookie(true);
        cookieManager.setAcceptThirdPartyCookies(target, false);

        WebView.setWebContentsDebuggingEnabled(BuildConfig.DEBUG);
        target.setWebChromeClient(new MgwWebChromeClient());
        target.setWebViewClient(new MgwWebViewClient());
        target.setDownloadListener(this::beginWebDownload);
    }

    private void loadInitialIntent(Intent intent) {
        // Every cold launch establishes the canonical server-owned Android
        // session first. A validated public /invite/<token> App Link is carried
        // through that same POST and only becomes a product route after auth.
        beginAndroidAuthentication(inviteTokenFromIntent(intent));
    }

    private void beginAndroidAuthentication() {
        beginAndroidAuthentication(pendingLaunchInviteToken);
    }

    private void beginAndroidAuthentication(String inviteToken) {
        if (navigationPolicy == null || credentialStore == null) {
            showConfigurationError();
            return;
        }
        String authUrl = ShellConfig.androidAuthUrl(configuredBaseUrl);
        if (authUrl.isEmpty() || !navigationPolicy.isInternal(authUrl)) {
            showConfigurationError();
            return;
        }

        final String credential;
        try {
            credential = credentialStore.getOrCreate();
        } catch (RuntimeException error) {
            showNetworkError(R.string.security_error_text);
            return;
        }

        String normalizedInvite = inviteToken == null ? "" : inviteToken.trim().toLowerCase(java.util.Locale.ROOT);
        if (!normalizedInvite.isEmpty()
                && !normalizedInvite.matches("^[a-f0-9]{24}$")) {
            normalizedInvite = "";
        }
        pendingLaunchInviteToken = normalizedInvite.isEmpty() ? null : normalizedInvite;

        // Credential and invite token formats are strict Base64URL / lowercase
        // hex, so both values are application/x-www-form-urlencoded safe.
        String form = "credential=" + credential;
        if (pendingLaunchInviteToken != null) {
            form += "&invite=" + pendingLaunchInviteToken;
        }
        mainFrameFailed = false;
        showLoading(true);
        webView.postUrl(authUrl, form.getBytes(StandardCharsets.UTF_8));
    }

    private String inviteTokenFromIntent(Intent intent) {
        if (intent == null || intent.getData() == null || navigationPolicy == null) {
            return null;
        }
        return navigationPolicy.inviteTokenFromLanding(intent.getData().toString());
    }

    private String safeIntentUrl(Intent intent) {
        if (intent == null || intent.getData() == null) {
            return null;
        }
        String candidate = intent.getData().toString();
        return navigationPolicy != null && navigationPolicy.isInternal(candidate) ? candidate : null;
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        setIntent(intent);
        if (navigationPolicy == null) {
            return;
        }
        String inviteToken = inviteTokenFromIntent(intent);
        if (inviteToken != null) {
            ensureWebView();
            beginAndroidAuthentication(inviteToken);
            return;
        }

        String candidate = safeIntentUrl(intent);
        if (candidate != null) {
            ensureWebView();
            mainFrameFailed = false;
            showLoading(true);
            webView.loadUrl(candidate);
        }
    }

    private void ensureWebView() {
        if (webView != null) {
            return;
        }
        attachFreshWebView();
        configureWebView(webView);
    }

    private void retryCurrentPage() {
        if (navigationPolicy == null) {
            showConfigurationError();
            return;
        }
        ensureWebView();
        mainFrameFailed = false;
        errorPanel.setVisibility(View.GONE);
        showLoading(true);
        String current = webView.getUrl();
        if (current == null || current.contains("/bot/android-auth.php")) {
            beginAndroidAuthentication();
        } else if (navigationPolicy.isInternal(current)) {
            webView.reload();
        } else {
            beginAndroidAuthentication();
        }
    }

    private void showLoading(boolean visible) {
        loadingPanel.setVisibility(visible ? View.VISIBLE : View.GONE);
        if (visible) {
            errorPanel.setVisibility(View.GONE);
        }
    }

    private void showNetworkError(int textResource) {
        mainFrameFailed = true;
        showLoading(false);
        errorTitle.setText(R.string.network_error_title);
        errorText.setText(textResource);
        errorPanel.setVisibility(View.VISIBLE);
        announceCurrentError();
    }

    private void showConfigurationError() {
        mainFrameFailed = true;
        showLoading(false);
        errorTitle.setText(R.string.configuration_error_title);
        errorText.setText(R.string.configuration_error_text);
        errorPanel.setVisibility(View.VISIBLE);
        announceCurrentError();
    }

    private void announceCurrentError() {
        if (errorPanel == null || errorTitle == null || errorText == null) return;
        errorPanel.post(() -> errorPanel.announceForAccessibility(
                errorTitle.getText() + ". " + errorText.getText()
        ));
    }

    private void openExternal(Uri uri) {
        try {
            startActivity(new Intent(Intent.ACTION_VIEW, uri));
        } catch (ActivityNotFoundException ignored) {
            Toast.makeText(this, R.string.external_link_error, Toast.LENGTH_SHORT).show();
        }
    }

    private boolean handleTopLevelNavigation(Uri uri) {
        String candidate = uri == null ? null : uri.toString();
        String reauthChallenge = candidate == null ? null : navigationPolicy.nativeReauthChallenge(candidate);
        if (reauthChallenge != null) {
            beginNativeReauth(reauthChallenge);
            return true;
        }
        String downloadRequest = candidate == null ? null : navigationPolicy.nativeAccountDownloadRequest(candidate);
        if (downloadRequest != null) {
            beginNativeAccountDownload(downloadRequest);
            return true;
        }
        if (candidate != null && navigationPolicy.isInternal(candidate)) {
            return false;
        }
        if (candidate != null && navigationPolicy.mayOpenExternally(candidate)) {
            openExternal(uri);
        }
        return true;
    }

    @SuppressWarnings("deprecation")
    private void beginNativeReauth(String challengeId) {
        if (reauthInProgress || navigationPolicy == null || credentialStore == null) {
            dispatchNativeReauthEvent("mgw:android-reauth-failed");
            return;
        }

        String current = webView == null ? null : webView.getUrl();
        if (current == null || !navigationPolicy.isInternal(current)) {
            dispatchNativeReauthEvent("mgw:android-reauth-failed");
            return;
        }

        KeyguardManager keyguard = (KeyguardManager) getSystemService(KEYGUARD_SERVICE);
        if (keyguard == null || !keyguard.isDeviceSecure()) {
            Toast.makeText(this, R.string.reauth_device_lock_required, Toast.LENGTH_LONG).show();
            dispatchNativeReauthEvent("mgw:android-reauth-failed");
            return;
        }

        Intent intent = keyguard.createConfirmDeviceCredentialIntent(
                getString(R.string.reauth_prompt_title),
                getString(R.string.reauth_prompt_text)
        );
        if (intent == null) {
            Toast.makeText(this, R.string.reauth_failed, Toast.LENGTH_SHORT).show();
            dispatchNativeReauthEvent("mgw:android-reauth-failed");
            return;
        }

        pendingReauthChallenge = challengeId;
        reauthInProgress = true;
        startActivityForResult(intent, REQUEST_ANDROID_REAUTH);
    }

    @SuppressWarnings("deprecation")
    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        if (requestCode == REQUEST_ANDROID_REAUTH) {
            if (!reauthInProgress || pendingReauthChallenge == null) {
                dispatchNativeReauthEvent("mgw:android-reauth-failed");
            } else if (resultCode == RESULT_OK) {
                confirmNativeReauth(pendingReauthChallenge);
            } else {
                finishNativeReauth("mgw:android-reauth-cancelled", false);
            }
            super.onActivityResult(requestCode, resultCode, data);
            return;
        }

        if (requestCode == REQUEST_WEB_FILE_CHOOSER) {
            ValueCallback<Uri[]> callback = fileChooserCallback;
            fileChooserCallback = null;
            if (callback == null) {
                return;
            }

            if (resultCode != RESULT_OK || data == null) {
                callback.onReceiveValue(null);
                return;
            }

            ClipData clipData = data.getClipData();
            if (clipData != null && clipData.getItemCount() > 0) {
                Uri[] values = new Uri[clipData.getItemCount()];
                for (int index = 0; index < clipData.getItemCount(); index++) {
                    values[index] = clipData.getItemAt(index).getUri();
                }
                callback.onReceiveValue(values);
                return;
            }

            Uri selected = data.getData();
            callback.onReceiveValue(selected == null ? null : new Uri[]{selected});
            return;
        }

        super.onActivityResult(requestCode, resultCode, data);
    }

    private void confirmNativeReauth(String challengeId) {
        new Thread(() -> {
            boolean confirmed = false;
            HttpsURLConnection connection = null;
            try {
                String endpoint = ShellConfig.androidReauthUrl(configuredBaseUrl);
                if (navigationPolicy == null || endpoint.isEmpty() || !navigationPolicy.isInternal(endpoint)) {
                    throw new IllegalStateException("Android reauth endpoint is unavailable.");
                }

                String credential = credentialStore.getOrCreate();
                String form = "action=confirm_native"
                        + "&challenge_id=" + URLEncoder.encode(challengeId, StandardCharsets.UTF_8.name())
                        + "&credential=" + URLEncoder.encode(credential, StandardCharsets.UTF_8.name());
                byte[] body = form.getBytes(StandardCharsets.UTF_8);

                connection = (HttpsURLConnection) new URL(endpoint).openConnection();
                connection.setRequestMethod("POST");
                connection.setConnectTimeout(12000);
                connection.setReadTimeout(12000);
                connection.setInstanceFollowRedirects(false);
                connection.setDoOutput(true);
                connection.setRequestProperty("Content-Type", "application/x-www-form-urlencoded; charset=utf-8");
                connection.setRequestProperty("Accept", "application/json");
                connection.setFixedLengthStreamingMode(body.length);
                try (java.io.OutputStream output = connection.getOutputStream()) {
                    output.write(body);
                }

                int status = connection.getResponseCode();
                String responseBody = readResponseBody(connection, status);
                if (status == 200) {
                    JSONObject payload = new JSONObject(responseBody);
                    confirmed = payload.optBoolean("ok", false)
                            && "confirmed".equals(payload.optJSONObject("reauth") == null
                            ? ""
                            : payload.optJSONObject("reauth").optString("status", ""));
                }
            } catch (Exception ignored) {
                confirmed = false;
            } finally {
                if (connection != null) {
                    connection.disconnect();
                }
            }

            boolean finalConfirmed = confirmed;
            runOnUiThread(() -> {
                if (finalConfirmed) {
                    finishNativeReauth("mgw:android-reauth-success", false);
                } else {
                    finishNativeReauth("mgw:android-reauth-failed", true);
                }
            });
        }, "mgw-android-reauth").start();
    }

    private static String readResponseBody(HttpsURLConnection connection, int status) throws Exception {
        InputStream stream = status >= 400 ? connection.getErrorStream() : connection.getInputStream();
        if (stream == null) return "";
        try (BufferedReader reader = new BufferedReader(new InputStreamReader(stream, StandardCharsets.UTF_8))) {
            StringBuilder result = new StringBuilder();
            String line;
            while ((line = reader.readLine()) != null) {
                result.append(line);
            }
            return result.toString();
        }
    }

    private void finishNativeReauth(String eventName, boolean showFailureToast) {
        reauthInProgress = false;
        pendingReauthChallenge = null;
        if (showFailureToast) {
            Toast.makeText(this, R.string.reauth_failed, Toast.LENGTH_SHORT).show();
        }
        dispatchNativeReauthEvent(eventName);
    }

    private void dispatchNativeReauthEvent(String eventName) {
        if (webView == null || navigationPolicy == null) return;
        String current = webView.getUrl();
        if (current == null || !navigationPolicy.isInternal(current)) return;

        final String script;
        if ("mgw:android-reauth-success".equals(eventName)) {
            script = "window.dispatchEvent(new Event('mgw:android-reauth-success'));";
        } else if ("mgw:android-reauth-cancelled".equals(eventName)) {
            script = "window.dispatchEvent(new Event('mgw:android-reauth-cancelled'));";
        } else {
            script = "window.dispatchEvent(new Event('mgw:android-reauth-failed'));";
        }
        webView.evaluateJavascript(script, null);
    }

    private void beginNativeAccountDownload(String requestId) {
        if (navigationPolicy == null || webView == null) {
            dispatchNativeDownloadEvent(false);
            return;
        }

        String current = webView.getUrl();
        if (current == null || !navigationPolicy.isInternal(current)) {
            dispatchNativeDownloadEvent(false);
            return;
        }

        if (Build.VERSION.SDK_INT <= Build.VERSION_CODES.P
                && checkSelfPermission(Manifest.permission.WRITE_EXTERNAL_STORAGE)
                != PackageManager.PERMISSION_GRANTED) {
            pendingDownloadRequestId = requestId;
            requestPermissions(
                    new String[]{Manifest.permission.WRITE_EXTERNAL_STORAGE},
                    REQUEST_LEGACY_DOWNLOAD_STORAGE
            );
            return;
        }

        enqueueNativeAccountDownload(requestId);
    }

    private void enqueueNativeAccountDownload(String requestId) {
        String endpoint = ShellConfig.accountDataDownloadUrl(configuredBaseUrl, requestId);
        if (navigationPolicy == null || endpoint.isEmpty() || !navigationPolicy.isInternal(endpoint)) {
            dispatchNativeDownloadEvent(false);
            return;
        }

        String cookie = CookieManager.getInstance().getCookie(endpoint);
        if (cookie == null || !cookie.contains("mgw_android_auth=")) {
            Toast.makeText(this, R.string.download_failed, Toast.LENGTH_SHORT).show();
            dispatchNativeDownloadEvent(false);
            return;
        }

        try {
            DownloadManager manager = (DownloadManager)getSystemService(DOWNLOAD_SERVICE);
            if (manager == null) {
                throw new IllegalStateException("DownloadManager unavailable");
            }

            DownloadManager.Request request = new DownloadManager.Request(Uri.parse(endpoint));
            request.setMimeType("application/zip");
            request.setTitle(getString(R.string.download_title));
            request.setDescription(getString(R.string.download_description));
            request.setNotificationVisibility(
                    DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED
            );
            request.setAllowedOverMetered(true);
            request.setAllowedOverRoaming(true);
            request.addRequestHeader("Cookie", cookie);
            String userAgent = webView.getSettings().getUserAgentString();
            if (userAgent != null && !userAgent.isBlank()) {
                request.addRequestHeader("User-Agent", userAgent);
            }

            String suffix = requestId.length() >= 12 ? requestId.substring(4, 12) : "archive";
            String attempt = Long.toString(System.currentTimeMillis());
            if (attempt.length() > 6) {
                attempt = attempt.substring(attempt.length() - 6);
            }
            request.setDestinationInExternalPublicDir(
                    Environment.DIRECTORY_DOWNLOADS,
                    "MiniGamesWorld-data-" + suffix + "-" + attempt + ".zip"
            );

            manager.enqueue(request);
            Toast.makeText(this, R.string.download_started, Toast.LENGTH_SHORT).show();
            dispatchNativeDownloadEvent(true);
        } catch (RuntimeException error) {
            Toast.makeText(this, R.string.download_failed, Toast.LENGTH_SHORT).show();
            dispatchNativeDownloadEvent(false);
        }
    }

    @Override
    public void onRequestPermissionsResult(
            int requestCode,
            String[] permissions,
            int[] grantResults
    ) {
        if (requestCode == REQUEST_LEGACY_DOWNLOAD_STORAGE) {
            String requestId = pendingDownloadRequestId;
            pendingDownloadRequestId = null;
            if (requestId != null
                    && grantResults.length > 0
                    && grantResults[0] == PackageManager.PERMISSION_GRANTED) {
                enqueueNativeAccountDownload(requestId);
            } else {
                Toast.makeText(this, R.string.download_permission_required, Toast.LENGTH_LONG).show();
                dispatchNativeDownloadEvent(false);
            }
            return;
        }

        if (requestCode == REQUEST_LEGACY_WEB_DOWNLOAD_STORAGE) {
            String url = pendingWebDownloadUrl;
            String userAgent = pendingWebDownloadUserAgent;
            String contentDisposition = pendingWebDownloadContentDisposition;
            String mimeType = pendingWebDownloadMimeType;
            clearPendingWebDownload();
            if (url != null
                    && grantResults.length > 0
                    && grantResults[0] == PackageManager.PERMISSION_GRANTED) {
                enqueueWebDownload(url, userAgent, contentDisposition, mimeType);
            } else {
                Toast.makeText(this, R.string.download_permission_required, Toast.LENGTH_LONG).show();
            }
            return;
        }
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
    }

    private void beginWebDownload(
            String url,
            String userAgent,
            String contentDisposition,
            String mimeType,
            long contentLength
    ) {
        if (navigationPolicy == null || url == null || !navigationPolicy.isInternal(url)) {
            Toast.makeText(this, R.string.download_failed, Toast.LENGTH_SHORT).show();
            return;
        }

        if (Build.VERSION.SDK_INT <= Build.VERSION_CODES.P
                && checkSelfPermission(Manifest.permission.WRITE_EXTERNAL_STORAGE)
                != PackageManager.PERMISSION_GRANTED) {
            pendingWebDownloadUrl = url;
            pendingWebDownloadUserAgent = userAgent;
            pendingWebDownloadContentDisposition = contentDisposition;
            pendingWebDownloadMimeType = mimeType;
            requestPermissions(
                    new String[]{Manifest.permission.WRITE_EXTERNAL_STORAGE},
                    REQUEST_LEGACY_WEB_DOWNLOAD_STORAGE
            );
            return;
        }

        enqueueWebDownload(url, userAgent, contentDisposition, mimeType);
    }

    private void enqueueWebDownload(
            String url,
            String userAgent,
            String contentDisposition,
            String mimeType
    ) {
        if (navigationPolicy == null || url == null || !navigationPolicy.isInternal(url)) {
            Toast.makeText(this, R.string.download_failed, Toast.LENGTH_SHORT).show();
            return;
        }

        String cookie = CookieManager.getInstance().getCookie(url);
        if (cookie == null || !cookie.contains("mgw_android_auth=")) {
            Toast.makeText(this, R.string.download_failed, Toast.LENGTH_SHORT).show();
            return;
        }

        try {
            DownloadManager manager = (DownloadManager)getSystemService(DOWNLOAD_SERVICE);
            if (manager == null) {
                throw new IllegalStateException("DownloadManager unavailable");
            }

            String normalizedMime = mimeType == null || mimeType.isBlank()
                    ? "application/octet-stream"
                    : mimeType;
            String fileName = URLUtil.guessFileName(url, contentDisposition, normalizedMime)
                    .replaceAll("[\\\\/:*?\"<>|\\p{Cntrl}]+", "_");
            if (fileName.isBlank()) {
                fileName = "MiniGamesWorld-attachment";
            }

            DownloadManager.Request request = new DownloadManager.Request(Uri.parse(url));
            request.setMimeType(normalizedMime);
            request.setTitle(fileName);
            request.setDescription(getString(R.string.download_description));
            request.setNotificationVisibility(
                    DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED
            );
            request.setAllowedOverMetered(true);
            request.setAllowedOverRoaming(true);
            request.addRequestHeader("Cookie", cookie);
            if (userAgent != null && !userAgent.isBlank()) {
                request.addRequestHeader("User-Agent", userAgent);
            }
            request.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, fileName);
            manager.enqueue(request);
            Toast.makeText(this, R.string.download_started, Toast.LENGTH_SHORT).show();
        } catch (RuntimeException error) {
            Toast.makeText(this, R.string.download_failed, Toast.LENGTH_SHORT).show();
        }
    }

    private void clearPendingWebDownload() {
        pendingWebDownloadUrl = null;
        pendingWebDownloadUserAgent = null;
        pendingWebDownloadContentDisposition = null;
        pendingWebDownloadMimeType = null;
    }

    private void dispatchNativeDownloadEvent(boolean success) {
        if (webView == null || navigationPolicy == null) return;
        String current = webView.getUrl();
        if (current == null || !navigationPolicy.isInternal(current)) return;

        String eventName = success
                ? "mgw:android-download-enqueued"
                : "mgw:android-download-failed";
        webView.evaluateJavascript(
                "window.dispatchEvent(new Event('" + eventName + "'));",
                null
        );
    }

    private void configureBackNavigation() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            backCallback = Api33Back.register(this);
        }
    }

    private void handleBack() {
        if (webView != null && navigationPolicy != null) {
            String current = webView.getUrl();
            if (current != null && navigationPolicy.isInternal(current)) {
                String script = "(function(){"
                        + "var e=new Event('mgw:android-back-request',{cancelable:true});"
                        + "document.dispatchEvent(e);"
                        + "return e.defaultPrevented;"
                        + "})()";
                webView.evaluateJavascript(script, result -> {
                    if ("true".equalsIgnoreCase(String.valueOf(result))) {
                        return;
                    }
                    handleBackFallback();
                });
                return;
            }
        }
        handleBackFallback();
    }

    private void handleBackFallback() {
        if (webView != null && webView.canGoBack()) {
            webView.goBack();
        } else {
            moveTaskToBack(true);
        }
    }

    @SuppressLint("GestureBackNavigation")
    @SuppressWarnings("deprecation")
    @Override
    public void onBackPressed() {
        // API 33+ is owned by OnBackInvokedDispatcher below. This legacy callback
        // remains only for API 26-32 devices where predictive-back dispatch does
        // not exist.
        handleBack();
    }

    @Override
    protected void onPause() {
        if (webView != null) {
            webView.onPause();
        }
        super.onPause();
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (webView != null) {
            webView.onResume();
        }
    }

    @Override
    public void onConfigurationChanged(Configuration newConfig) {
        super.onConfigurationChanged(newConfig);

        // Ordinary rotation/window configuration changes are owned by this
        // Activity so the accepted WebView, Android session and current route
        // remain alive. Never re-authenticate or replace the WebView here.
        if (root != null) {
            root.requestApplyInsets();
            root.requestLayout();
        }
        if (webView != null) {
            webView.requestLayout();
        }
    }

    @Override
    protected void onSaveInstanceState(Bundle outState) {
        if (webView != null) {
            Bundle webState = new Bundle();
            webView.saveState(webState);
            outState.putBundle(STATE_WEBVIEW, webState);
        }
        super.onSaveInstanceState(outState);
    }

    @Override
    protected void onDestroy() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU && backCallback != null) {
            Api33Back.unregister(this, backCallback);
            backCallback = null;
        }
        if (fileChooserCallback != null) {
            fileChooserCallback.onReceiveValue(null);
            fileChooserCallback = null;
        }
        clearPendingWebDownload();
        destroyWebView();
        super.onDestroy();
    }

    private void destroyWebView() {
        if (webView == null) {
            return;
        }
        WebView doomed = webView;
        webView = null;
        doomed.stopLoading();
        doomed.setWebChromeClient(null);
        doomed.setWebViewClient(null);
        root.removeView(doomed);
        doomed.destroy();
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }

    private final class MgwWebChromeClient extends WebChromeClient {
        @Override
        public boolean onShowFileChooser(
                WebView view,
                ValueCallback<Uri[]> filePathCallback,
                FileChooserParams fileChooserParams
        ) {
            if (filePathCallback == null) {
                return false;
            }
            if (fileChooserCallback != null) {
                fileChooserCallback.onReceiveValue(null);
            }
            fileChooserCallback = filePathCallback;

            Intent chooser = new Intent(Intent.ACTION_OPEN_DOCUMENT);
            chooser.addCategory(Intent.CATEGORY_OPENABLE);
            chooser.setType("*/*");
            chooser.putExtra(Intent.EXTRA_MIME_TYPES, WEB_UPLOAD_MIME_TYPES);
            chooser.putExtra(
                    Intent.EXTRA_ALLOW_MULTIPLE,
                    fileChooserParams != null
                            && fileChooserParams.getMode() == FileChooserParams.MODE_OPEN_MULTIPLE
            );

            try {
                startActivityForResult(chooser, REQUEST_WEB_FILE_CHOOSER);
                return true;
            } catch (ActivityNotFoundException error) {
                ValueCallback<Uri[]> callback = fileChooserCallback;
                fileChooserCallback = null;
                if (callback != null) callback.onReceiveValue(null);
                Toast.makeText(MainActivity.this, R.string.external_link_error, Toast.LENGTH_SHORT).show();
                return true;
            }
        }
    }

    private final class MgwWebViewClient extends WebViewClient {
        @Override
        public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
            if (!request.isForMainFrame()) {
                return false;
            }
            return handleTopLevelNavigation(request.getUrl());
        }

        @Override
        public void onPageStarted(WebView view, String url, android.graphics.Bitmap favicon) {
            mainFrameFailed = false;
            showLoading(true);
        }

        @Override
        public void onPageFinished(WebView view, String url) {
            if (!mainFrameFailed) {
                showLoading(false);
                if (url != null && !url.contains("/bot/android-auth.php")) {
                    pendingLaunchInviteToken = null;
                }
                if (refreshSharedAssetsForVersion
                        && url != null
                        && navigationPolicy != null
                        && navigationPolicy.isInternal(url)
                        && url.contains("/app/")) {
                    view.getSettings().setCacheMode(WebSettings.LOAD_DEFAULT);
                    getSharedPreferences(SHELL_PREFERENCES, MODE_PRIVATE)
                            .edit()
                            .putInt(ASSET_CACHE_VERSION_KEY, BuildConfig.VERSION_CODE)
                            .apply();
                    refreshSharedAssetsForVersion = false;
                }
            }
        }

        @Override
        public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
            if (request.isForMainFrame()) {
                showNetworkError(R.string.network_error_text);
            }
        }

        @Override
        public void onReceivedHttpError(WebView view, WebResourceRequest request, WebResourceResponse errorResponse) {
            if (request.isForMainFrame() && errorResponse.getStatusCode() >= 400) {
                showNetworkError(R.string.network_error_text);
            }
        }

        @Override
        public void onReceivedSslError(WebView view, SslErrorHandler handler, SslError error) {
            handler.cancel();
            showNetworkError(R.string.security_error_text);
        }

        @Override
        public boolean onRenderProcessGone(WebView view, RenderProcessGoneDetail detail) {
            if (view == webView) {
                destroyWebView();
            }
            showNetworkError(R.string.network_error_text);
            return true;
        }
    }

    @TargetApi(Build.VERSION_CODES.R)
    private static final class Api30Insets {
        private Api30Insets() {
        }

        static void apply(View view, WindowInsets insets) {
            android.graphics.Insets safe = insets.getInsets(
                    WindowInsets.Type.systemBars() | WindowInsets.Type.displayCutout()
            );
            view.setPadding(safe.left, safe.top, safe.right, safe.bottom);
        }
    }

    @TargetApi(Build.VERSION_CODES.TIRAMISU)
    private static final class Api33Back {
        private Api33Back() {
        }

        static Object register(MainActivity activity) {
            android.window.OnBackInvokedCallback callback = activity::handleBack;
            activity.getOnBackInvokedDispatcher().registerOnBackInvokedCallback(
                    android.window.OnBackInvokedDispatcher.PRIORITY_DEFAULT,
                    callback
            );
            return callback;
        }

        static void unregister(MainActivity activity, Object callback) {
            activity.getOnBackInvokedDispatcher().unregisterOnBackInvokedCallback(
                    (android.window.OnBackInvokedCallback) callback
            );
        }
    }
}
