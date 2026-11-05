package com.zhandui.app;

import android.app.Activity;
import android.content.ClipData;
import android.content.Intent;
import android.graphics.Color;
import android.graphics.Insets;
import android.graphics.drawable.ColorDrawable;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.view.View;
import android.view.WindowInsets;
import android.view.WindowInsetsController;
import android.webkit.CookieManager;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;

import java.util.regex.Matcher;
import java.util.regex.Pattern;

public class MainActivity extends Activity {

    public static final String EXTRA_FRAGMENT = "open_fragment";

    private static final int REQ_UPLOAD = 1001;
    // Matches the site's white app bar, which is what sits under the status strip.
    private static final int FALLBACK_CHROME = Color.parseColor("#ffffff");
    private static final Pattern RGBA = Pattern.compile(
            "rgba?\\(\\s*(\\d{1,3})\\s*,\\s*(\\d{1,3})\\s*,\\s*(\\d{1,3})");

    private WebView webView;
    private View root;
    private String loadedBase = "";
    private ValueCallback<Uri[]> pendingUpload;

    @Override
    protected void onCreate(Bundle saved) {
        super.onCreate(saved);
        setContentView(R.layout.activity_main);
        root = findViewById(R.id.shell_root);
        webView = findViewById(R.id.web);

        WebSettings s = webView.getSettings();
        s.setJavaScriptEnabled(true);
        // The site is a SPA that keeps unread cursors and DIY layout in localStorage.
        s.setDomStorageEnabled(true);
        s.setDatabaseEnabled(true);
        s.setMediaPlaybackRequiresUserGesture(false);
        s.setBuiltInZoomControls(true);
        s.setDisplayZoomControls(false);
        s.setTextZoom(Prefs.textZoom(this));
        s.setAllowFileAccess(false);
        // Uploads come back as content:// uris from the picker, not file paths.
        s.setAllowContentAccess(true);
        s.setSupportZoom(true);
        s.setUseWideViewPort(true);
        s.setLoadWithOverviewMode(true);
        s.setCacheMode(WebSettings.LOAD_DEFAULT);

        CookieManager.getInstance().setAcceptCookie(true);
        CookieManager.getInstance().setAcceptThirdPartyCookies(webView, true);

        fixStatusBarOverlap();
        webView.setBackgroundColor(FALLBACK_CHROME);

        webView.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest req) {
                return handleNavigation(req.getUrl());
            }

            @Override
            public void onPageFinished(WebView view, String url) {
                syncChromeColour();
            }
        });
        webView.setWebChromeClient(new WebChromeClient() {
            @Override
            public boolean onShowFileChooser(WebView view, ValueCallback<Uri[]> callback,
                                            FileChooserParams params) {
                pendingUpload = callback;
                try {
                    startActivityForResult(params.createIntent(), REQ_UPLOAD);
                } catch (Exception e) {
                    finishUpload(null);
                    return false;
                }
                return true;
            }
        });

        openSite();
    }

    /**
     * The one line that answers "the top of the page is cut off".
     *
     * v1.0.1 only dropped the action bar; the status bar stayed, and on Android 15 a
     * targetSdk 35 app is edge-to-edge whether it likes it or not.
     *
     * The inset goes on the container, never on the WebView itself: the site's top bar
     * is position:fixed, and chromium pins fixed elements to the WebView's own viewport,
     * so webView.setPadding() still lets that bar slide up under the status bar. Padding
     * the parent moves the whole WebView down, which is what actually clears the clock.
     *
     * Listening on the WebView is also not enough - some ROMs consume the insets at the
     * decor before a child ever sees them - so the listener hangs off the decor view and
     * the padding takes max(inset, status_bar_height) as a floor.
     */
    private void fixStatusBarOverlap() {
        final int fallback = statusBarHeightFallback();
        if (Build.VERSION.SDK_INT >= 30) {
            getWindow().setDecorFitsSystemWindows(false);
            getWindow().getDecorView().setOnApplyWindowInsetsListener(new View.OnApplyWindowInsetsListener() {
                @Override
                public WindowInsets onApplyWindowInsets(View decor, WindowInsets insets) {
                    Insets bars = insets.getInsets(WindowInsets.Type.systemBars()
                            | WindowInsets.Type.displayCutout());
                    // adjustResize already shrinks the window for the keyboard, and the
                    // keyboard covers the nav bar, so padding both would double-count.
                    boolean imeVisible = insets.isVisible(WindowInsets.Type.ime());
                    setShellInsets(bars.left, Math.max(bars.top, fallback),
                            bars.right, imeVisible ? 0 : bars.bottom);
                    return insets;
                }
            });
            getWindow().getDecorView().requestApplyInsets();
        } else {
            root.setFitsSystemWindows(true);
        }
        root.post(new Runnable() {
            @Override
            public void run() {
                if (root.getPaddingTop() < fallback) {
                    root.setPadding(root.getPaddingLeft(), fallback,
                            root.getPaddingRight(), root.getPaddingBottom());
                }
            }
        });
    }

    private void setShellInsets(int left, int top, int right, int bottom) {
        root.setPadding(left, top, right, bottom);
    }

    private int statusBarHeightFallback() {
        int id = getResources().getIdentifier("status_bar_height", "dimen", "android");
        return id > 0 ? getResources().getDimensionPixelSize(id) : 0;
    }

    private void openSite() {
        String base = Prefs.base(this);
        if (!(base.startsWith("http://") || base.startsWith("https://"))) {
            startActivity(new Intent(this, EntryActivity.class)
                    .putExtra(EntryActivity.EXTRA_SETTINGS, true));
            finish();
            return;
        }
        loadedBase = base;
        String fragment = getIntent() == null ? null : getIntent().getStringExtra(EXTRA_FRAGMENT);
        webView.loadUrl(fragment == null || fragment.isEmpty() ? base : base + fragment);
    }

    /** A notification tap lands here without tearing the page down and reloading it. */
    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        setIntent(intent);
        String fragment = intent.getStringExtra(EXTRA_FRAGMENT);
        if (fragment != null && !fragment.isEmpty()) {
            String safe = fragment.replace("\\", "\\\\").replace("'", "\\'").replace("\n", "");
            webView.evaluateJavascript("location.hash='" + safe + "';", null);
        }
    }

    @Override
    protected void onResume() {
        super.onResume();
        webView.onResume();
        if (Prefs.hasBase(this) && !Prefs.base(this).equals(loadedBase)) {
            openSite();
        }
        NotifyService.startIfEnabled(this);
    }

    @Override
    protected void onPause() {
        super.onPause();
        webView.onPause();
    }

    @Override
    public void onBackPressed() {
        if (webView.canGoBack()) {
            webView.goBack();
        } else {
            super.onBackPressed();
        }
    }

    /** Same host stays inside the app; anything else goes to the system browser. */
    private boolean handleNavigation(Uri url) {
        String scheme = url.getScheme();
        if (scheme == null) {
            return true;
        }
        if (!scheme.equals("http") && !scheme.equals("https")) {
            launchExternal(url);
            return true;
        }
        String own = Uri.parse(loadedBase).getHost();
        String host = url.getHost();
        if (own == null || host == null || own.equals(host) || host.endsWith("." + own)) {
            return false;
        }
        launchExternal(url);
        return true;
    }

    private void launchExternal(Uri url) {
        try {
            startActivity(new Intent(Intent.ACTION_VIEW, url));
        } catch (Exception ignored) {
        }
    }

    /**
     * Paint the reserved strip with the colour of whatever actually sits at the top of
     * the page (the site's white app bar, not the grey body behind it), so the status
     * bar reads as that bar extending upward instead of a mismatched band. Each team
     * site can theme itself, hence reading it from the live page.
     */
    private void syncChromeColour() {
        String js = "(function(){try{var el=document.elementFromPoint(Math.floor(window.innerWidth/2),4)||document.body;"
                + "var c='';while(el){c=getComputedStyle(el).backgroundColor;"
                + "if(c&&c!=='rgba(0, 0, 0, 0)'&&c!=='transparent')break;el=el.parentElement;}return c||'';}catch(e){return ''}})()";
        webView.evaluateJavascript(js, new ValueCallback<String>() {
            @Override
            public void onReceiveValue(String value) {
                int colour = parseColour(value);
                if (colour == 0) {
                    colour = FALLBACK_CHROME;
                }
                root.setBackground(new ColorDrawable(colour));
                webView.setBackgroundColor(colour);
                applyStatusIconAppearance(colour);
            }
        });
    }

    /**
     * A white top bar under edge-to-edge otherwise gets white status icons, and that
     * is precisely "the clock and my page have merged into one unreadable band".
     */
    private void applyStatusIconAppearance(int background) {
        boolean lightBackground = isLight(background);
        if (Build.VERSION.SDK_INT >= 30) {
            WindowInsetsController controller = getWindow().getInsetsController();
            if (controller != null) {
                controller.setSystemBarsAppearance(
                        lightBackground ? WindowInsetsController.APPEARANCE_LIGHT_STATUS_BARS : 0,
                        WindowInsetsController.APPEARANCE_LIGHT_STATUS_BARS);
            }
            return;
        }
        View decor = getWindow().getDecorView();
        int flags = decor.getSystemUiVisibility();
        if (lightBackground) {
            flags |= View.SYSTEM_UI_FLAG_LIGHT_STATUS_BAR;
        } else {
            flags &= ~View.SYSTEM_UI_FLAG_LIGHT_STATUS_BAR;
        }
        decor.setSystemUiVisibility(flags);
    }

    private static boolean isLight(int colour) {
        double luminance = 0.299 * Color.red(colour) + 0.587 * Color.green(colour)
                + 0.114 * Color.blue(colour);
        return luminance > 160;
    }

    private static int parseColour(String raw) {
        if (raw == null) {
            return 0;
        }
        Matcher m = RGBA.matcher(raw);
        if (!m.find()) {
            return 0;
        }
        try {
            return Color.rgb(Integer.parseInt(m.group(1)), Integer.parseInt(m.group(2)), Integer.parseInt(m.group(3)));
        } catch (Exception e) {
            return 0;
        }
    }

    /** Always answer the file chooser, even with null, or it never opens again. */
    private void finishUpload(Uri[] uris) {
        if (pendingUpload != null) {
            pendingUpload.onReceiveValue(uris);
            pendingUpload = null;
        }
    }

    @Override
    protected void onActivityResult(int req, int res, Intent data) {
        super.onActivityResult(req, res, data);
        if (req != REQ_UPLOAD) {
            return;
        }
        if (res != RESULT_OK || data == null) {
            finishUpload(null);
            return;
        }
        ClipData clip = data.getClipData();
        if (clip != null) {
            Uri[] uris = new Uri[clip.getItemCount()];
            for (int i = 0; i < clip.getItemCount(); i++) {
                uris[i] = clip.getItemAt(i).getUri();
            }
            finishUpload(uris);
            return;
        }
        finishUpload(data.getData() == null ? null : new Uri[]{data.getData()});
    }
}
