package com.minigamesworld.app;

import android.annotation.SuppressLint;
import android.annotation.TargetApi;
import android.app.Activity;
import android.app.KeyguardManager;
import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.content.res.ColorStateList;
import android.content.res.Configuration;
import android.graphics.Color;
import android.net.Uri;
import android.net.http.SslError;
import android.os.Build;
import android.os.Bundle;
import android.view.Gravity;
import android.view.View;
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
import android.widget.Button;
import android.widget.FrameLayout;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

import org.json.JSONObject;

import java.io.InputStream;
import java.io.InputStreamReader;
import java.net.URL;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

import javax.net.ssl.HttpsURLConnection;

public final class MainActivity extends Activity {
    private static final String STATE_WEBVIEW = "mgw_webview_state";
    private static final String STATE_NATIVE_REAUTH_REQUEST = "mgw_native_reauth_request";
    private static final int REQUEST_CONFIRM_DEVICE_CREDENTIAL = 26041;

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
    private Object backCallback;
    private String pendingNativeReauthRequestId;
    private final ExecutorService nativeReauthExecutor = Executors.newSingleThreadExecutor();

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        getWindow().setStatusBarColor(Color.BLACK);
        getWindow().setNavigationBarColor(Color.BLACK);

        buildShellUi();
        applySystemInsets();
        configureBackNavigation();

        configuredBaseUrl = ShellConfig.configuredBaseUrl();
        if (!NavigationPolicy.isSafeHttpsBase(configuredBaseUrl)
                || ShellConfig.androidAuthUrl(configuredBaseUrl).isEmpty()
                || ShellConfig.androidReauthUrl(configuredBaseUrl).isEmpty()) {
            showConfigurationError();
            return;
        }

        navigationPolicy = new NavigationPolicy(configuredBaseUrl);
        credentialStore = new DeviceCredentialStore(this);
        pendingNativeReauthRequestId = savedInstanceState == null
                ? null
                : savedInstanceState.getString(STATE_NATIVE_REAUTH_REQUEST);
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
        LinearLayout.LayoutParams markParams = new LinearLayout.LayoutParams(dp(156), dp(156));
        markParams.gravity = Gravity.CENTER_HORIZONTAL;
        loadingPanel.addView(brandMark, markParams);

        TextView loadingText = new TextView(this);
        loadingText.setText(R.string.loading);
        loadingText.setTextColor(getColor(R.color.mgw_brand_silver));
        loadingText.setAlpha(0.72f);
        loadingText.setTextSize(14f);
        loadingText.setGravity(Gravity.CENTER);
        LinearLayout.LayoutParams loadingTextParams = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT
        );
        loadingTextParams.topMargin = dp(12);
        loadingPanel.addView(loadingText, loadingTextParams);

        loading = new ProgressBar(this);
        loading.setIndeterminateTintList(ColorStateList.valueOf(getColor(R.color.mgw_brand_violet)));
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
        root.addView(replacement, 0, new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT
        ));
        webView = replacement;
    }

    @SuppressWarnings("deprecation")
    private void applySystemInsets() {
        root.setOnApplyWindowInsetsListener((view, insets) -> {
            view.setPadding(
                    insets.getSystemWindowInsetLeft(),
                    insets.getSystemWindowInsetTop(),
                    insets.getSystemWindowInsetRight(),
                    insets.getSystemWindowInsetBottom()
            );
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

        CookieManager cookieManager = CookieManager.getInstance();
        cookieManager.setAcceptCookie(true);
        cookieManager.setAcceptThirdPartyCookies(target, false);

        WebView.setWebContentsDebuggingEnabled(BuildConfig.DEBUG);
        target.setWebChromeClient(new WebChromeClient());
        target.setWebViewClient(new MgwWebViewClient());
    }

    private void loadInitialIntent(Intent intent) {
        // MVP-26.2 always establishes the server-owned Android session before
        // entering the web product. App/deep-link routing is added after this
        // identity boundary is accepted.
        beginAndroidAuthentication();
    }

    private void beginAndroidAuthentication() {
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

        // Credential format is strict Base64URL without padding, therefore all
        // bytes are already application/x-www-form-urlencoded safe on API 26+.
        String form = "credential=" + credential;
        mainFrameFailed = false;
        showLoading(true);
        webView.postUrl(authUrl, form.getBytes(StandardCharsets.UTF_8));
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
    }

    private void showConfigurationError() {
        mainFrameFailed = true;
        showLoading(false);
        errorTitle.setText(R.string.configuration_error_title);
        errorText.setText(R.string.configuration_error_text);
        errorPanel.setVisibility(View.VISIBLE);
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
        String reauthRequestId = navigationPolicy == null
                ? null
                : navigationPolicy.nativeReauthRequestId(candidate);
        if (reauthRequestId != null) {
            beginNativeReauth(reauthRequestId);
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
    private void beginNativeReauth(String requestId) {
        if (navigationPolicy == null
                || webView == null
                || !navigationPolicy.isInternal(webView.getUrl())) {
            emitNativeReauthResult(requestId, false, "invalid_context");
            return;
        }
        if (pendingNativeReauthRequestId != null) {
            emitNativeReauthResult(requestId, false, "busy");
            return;
        }

        KeyguardManager keyguard = (KeyguardManager) getSystemService(KEYGUARD_SERVICE);
        if (keyguard == null || !keyguard.isDeviceSecure()) {
            emitNativeReauthResult(requestId, false, "device_lock_required");
            return;
        }

        Intent confirmation = keyguard.createConfirmDeviceCredentialIntent(
                getString(R.string.reauth_title),
                getString(R.string.reauth_text)
        );
        if (confirmation == null) {
            emitNativeReauthResult(requestId, false, "device_lock_required");
            return;
        }

        pendingNativeReauthRequestId = requestId;
        try {
            startActivityForResult(confirmation, REQUEST_CONFIRM_DEVICE_CREDENTIAL);
        } catch (ActivityNotFoundException error) {
            pendingNativeReauthRequestId = null;
            emitNativeReauthResult(requestId, false, "device_lock_unavailable");
        }
    }

    @SuppressWarnings("deprecation")
    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode != REQUEST_CONFIRM_DEVICE_CREDENTIAL) return;

        String requestId = pendingNativeReauthRequestId;
        if (requestId == null) return;
        if (resultCode != RESULT_OK) {
            pendingNativeReauthRequestId = null;
            emitNativeReauthResult(requestId, false, "cancelled");
            return;
        }
        performNativeReauth(requestId);
    }

    private void performNativeReauth(String requestId) {
        if (credentialStore == null || navigationPolicy == null) {
            completeNativeReauth(requestId, false, "configuration");
            return;
        }

        final String credential;
        final String reauthUrl = ShellConfig.androidReauthUrl(configuredBaseUrl);
        try {
            credential = credentialStore.getOrCreate();
        } catch (RuntimeException error) {
            completeNativeReauth(requestId, false, "credential_unavailable");
            return;
        }
        if (!DeviceCredentialStore.isCredentialFormatValid(credential)
                || reauthUrl.isEmpty()
                || !navigationPolicy.isInternal(reauthUrl)) {
            completeNativeReauth(requestId, false, "configuration");
            return;
        }

        final String cookieHeader = CookieManager.getInstance().getCookie(reauthUrl);
        if (cookieHeader == null || cookieHeader.isBlank()) {
            completeNativeReauth(requestId, false, "session_required");
            return;
        }

        nativeReauthExecutor.execute(() -> {
            HttpsURLConnection connection = null;
            String reason = "network";
            boolean ok = false;
            try {
                connection = (HttpsURLConnection) new URL(reauthUrl).openConnection();
                connection.setConnectTimeout(10_000);
                connection.setReadTimeout(15_000);
                connection.setRequestMethod("POST");
                connection.setInstanceFollowRedirects(false);
                connection.setDoOutput(true);
                connection.setRequestProperty("Content-Type", "application/x-www-form-urlencoded; charset=UTF-8");
                connection.setRequestProperty("Accept", "application/json");
                connection.setRequestProperty("Cookie", cookieHeader);

                byte[] body = ("credential=" + URLEncoder.encode(credential, StandardCharsets.UTF_8))
                        .getBytes(StandardCharsets.UTF_8);
                connection.setFixedLengthStreamingMode(body.length);
                connection.getOutputStream().write(body);

                int status = connection.getResponseCode();
                String raw = readBoundedResponse(
                        status >= 200 && status < 400
                                ? connection.getInputStream()
                                : connection.getErrorStream()
                );
                JSONObject payload = raw.isEmpty() ? new JSONObject() : new JSONObject(raw);
                ok = status == 200 && payload.optBoolean("ok", false);
                reason = ok ? "" : payload.optString("code", status == 429 ? "rate_limited" : "rejected");
            } catch (Exception ignored) {
                reason = "network";
            } finally {
                if (connection != null) connection.disconnect();
            }

            final boolean finalOk = ok;
            final String finalReason = reason;
            runOnUiThread(() -> completeNativeReauth(requestId, finalOk, finalReason));
        });
    }

    private static String readBoundedResponse(InputStream stream) throws Exception {
        if (stream == null) return "";
        StringBuilder output = new StringBuilder();
        try (InputStreamReader reader = new InputStreamReader(stream, StandardCharsets.UTF_8)) {
            char[] buffer = new char[1024];
            int total = 0;
            while (true) {
                int count = reader.read(buffer);
                if (count < 0) break;
                total += count;
                if (total > 16_384) throw new IllegalStateException("Android reauth response is too large.");
                output.append(buffer, 0, count);
            }
        }
        return output.toString();
    }

    private void completeNativeReauth(String requestId, boolean ok, String reason) {
        if (requestId.equals(pendingNativeReauthRequestId)) {
            pendingNativeReauthRequestId = null;
        }
        emitNativeReauthResult(requestId, ok, reason);
    }

    private void emitNativeReauthResult(String requestId, boolean ok, String reason) {
        if (webView == null
                || navigationPolicy == null
                || !navigationPolicy.isInternal(webView.getUrl())) {
            return;
        }
        try {
            JSONObject detail = new JSONObject();
            detail.put("requestId", requestId);
            detail.put("ok", ok);
            detail.put("reason", reason == null ? "" : reason);
            String script = "window.dispatchEvent(new CustomEvent('mgw:native-reauth-result',{detail:"
                    + detail
                    + "}));";
            webView.evaluateJavascript(script, null);
        } catch (Exception ignored) {
            // The JS caller owns a bounded timeout; native never exposes credentials
            // or retries an unacknowledged sensitive action on its own.
        }
    }

    private void configureBackNavigation() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            backCallback = Api33Back.register(this);
        }
    }

    private void handleBack() {
        if (webView != null && webView.canGoBack()) {
            webView.goBack();
        } else {
            finish();
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
        if (pendingNativeReauthRequestId != null) {
            outState.putString(STATE_NATIVE_REAUTH_REQUEST, pendingNativeReauthRequestId);
        }
        super.onSaveInstanceState(outState);
    }

    @Override
    protected void onDestroy() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU && backCallback != null) {
            Api33Back.unregister(this, backCallback);
            backCallback = null;
        }
        nativeReauthExecutor.shutdownNow();
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
