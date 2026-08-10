package com.minigamesworld.app;

import android.annotation.SuppressLint;
import android.annotation.TargetApi;
import android.app.Activity;
import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.graphics.Color;
import android.net.Uri;
import android.net.http.SslError;
import android.os.Build;
import android.os.Bundle;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
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
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

public final class MainActivity extends Activity {
    private static final String STATE_WEBVIEW = "mgw_webview_state";

    private FrameLayout root;
    private WebView webView;
    private ProgressBar loading;
    private LinearLayout errorPanel;
    private TextView errorTitle;
    private TextView errorText;
    private NavigationPolicy navigationPolicy;
    private boolean mainFrameFailed;
    private Object backCallback;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        getWindow().setStatusBarColor(Color.BLACK);
        getWindow().setNavigationBarColor(Color.BLACK);

        buildShellUi();
        applySystemInsets();
        configureBackNavigation();

        String configuredBaseUrl = ShellConfig.configuredBaseUrl();
        if (!NavigationPolicy.isSafeHttpsBase(configuredBaseUrl)) {
            showConfigurationError();
            return;
        }

        navigationPolicy = new NavigationPolicy(configuredBaseUrl);
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

        loading = new ProgressBar(this);
        FrameLayout.LayoutParams loadingParams = new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT,
                ViewGroup.LayoutParams.WRAP_CONTENT,
                Gravity.CENTER
        );
        root.addView(loading, loadingParams);

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

        WebView.setWebContentsDebuggingEnabled(BuildConfig.DEBUG);
        target.setWebChromeClient(new WebChromeClient());
        target.setWebViewClient(new MgwWebViewClient());
    }

    private void loadInitialIntent(Intent intent) {
        String incoming = safeIntentUrl(intent);
        mainFrameFailed = false;
        showLoading(true);
        webView.loadUrl(navigationPolicy.initialUrl(incoming));
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
        if (current != null && navigationPolicy.isInternal(current)) {
            webView.reload();
        } else {
            webView.loadUrl(navigationPolicy.initialUrl(null));
        }
    }

    private void showLoading(boolean visible) {
        loading.setVisibility(visible ? View.VISIBLE : View.GONE);
        if (visible) {
            errorPanel.setVisibility(View.GONE);
        }
    }

    private void showNetworkError(int textResource) {
        mainFrameFailed = true;
        loading.setVisibility(View.GONE);
        errorTitle.setText(R.string.network_error_title);
        errorText.setText(textResource);
        errorPanel.setVisibility(View.VISIBLE);
    }

    private void showConfigurationError() {
        mainFrameFailed = true;
        loading.setVisibility(View.GONE);
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
        if (candidate != null && navigationPolicy.isInternal(candidate)) {
            return false;
        }
        if (candidate != null && navigationPolicy.mayOpenExternally(candidate)) {
            openExternal(uri);
        }
        return true;
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
