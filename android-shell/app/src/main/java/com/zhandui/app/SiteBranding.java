package com.zhandui.app;

import android.content.Context;
import android.graphics.Bitmap;
import android.graphics.Canvas;
import android.graphics.Color;
import android.graphics.Paint;
import android.graphics.RectF;
import android.graphics.Typeface;

import org.json.JSONObject;

/**
 * What the shell knows about the team it is pointed at, and the badge it draws for it.
 *
 * The site exposes no PNG emblem the app could load (the favicon is .ico, which
 * BitmapFactory cannot decode, and tenants.logo is not in any API response), so the
 * badge is generated locally from the team's first character - the same fallback the
 * public hall uses in view/hall.html when a team has no logo.
 */
public final class SiteBranding {

    private static final String KEY_NAME = "team_name|";
    private static final int BADGE = 128;
    private static final int ACCENT = Color.parseColor("#0066cc");

    private static Bitmap cachedBadge;
    private static String cachedBadgeKey = "";

    private SiteBranding() {
    }

    public static String teamName(Context c) {
        String n = Prefs.sp(c).getString(KEY_NAME + Prefs.base(c), "");
        return n == null ? "" : n;
    }

    /** Call from a worker thread; hits /api/auth/me with the WebView's cookies. */
    public static void refresh(Context c) {
        String base = Prefs.base(c);
        if (!base.startsWith("http")) {
            return;
        }
        try {
            String cookie = CookieJar.read(c, base);
            if (cookie == null || cookie.isEmpty()) {
                return;
            }
            SiteApi.Result res = SiteApi.get(base + "/api/auth/me", cookie);
            CookieJar.writeBack(c, base, res.setCookies);
            JSONObject data = res.json.optJSONObject("data");
            if (data == null) {
                return;
            }
            String name = data.optString("team_name", "");
            if (name.isEmpty()) {
                return;
            }
            Prefs.sp(c).edit().putString(KEY_NAME + base, name).apply();
        } catch (Exception e) {
            // No branding this round; the app falls back to its own name and icon.
        }
    }

    /** Rounded tile with the team's first character, or null to keep the default icon. */
    public static Bitmap badge(Context c) {
        String name = teamName(c);
        if (name.isEmpty()) {
            return null;
        }
        if (cachedBadge != null && name.equals(cachedBadgeKey)) {
            return cachedBadge;
        }
        Bitmap bmp = Bitmap.createBitmap(BADGE, BADGE, Bitmap.Config.ARGB_8888);
        Canvas canvas = new Canvas(bmp);
        Paint paint = new Paint(Paint.ANTI_ALIAS_FLAG);
        paint.setColor(ACCENT);
        canvas.drawRoundRect(new RectF(0, 0, BADGE, BADGE), 28, 28, paint);
        paint.setColor(Color.WHITE);
        paint.setTypeface(Typeface.DEFAULT_BOLD);
        paint.setTextAlign(Paint.Align.CENTER);
        paint.setTextSize(BADGE * 0.52f);
        String glyph = name.substring(0, 1);
        Paint.FontMetrics fm = paint.getFontMetrics();
        float baseline = BADGE / 2f - (fm.ascent + fm.descent) / 2f;
        canvas.drawText(glyph, BADGE / 2f, baseline, paint);
        cachedBadge = bmp;
        cachedBadgeKey = name;
        return bmp;
    }

    /** Title used wherever the app should speak as the team rather than as itself. */
    public static String title(Context c) {
        String name = teamName(c);
        return name.isEmpty() ? c.getString(R.string.app_name) : name;
    }
}
