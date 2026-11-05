package com.zhandui.app;

import android.content.Context;
import android.content.SharedPreferences;

/**
 * Single store for everything the shell remembers.
 *
 * Polling cursors are bucketed by site base url: one phone can hold several team
 * sites, and a shared cursor would let one site swallow another's unread marks
 * (the web client already hit this, hence the same rule here).
 */
public final class Prefs {

    public static final String KEY_BASE = "base_url";
    public static final String KEY_TEXT_ZOOM = "text_zoom";
    public static final String KEY_NOTIFY_ON = "notify_on";
    public static final String KEY_POLL_SECONDS = "poll_seconds";

    public static SharedPreferences sp(Context c) {
        return c.getApplicationContext().getSharedPreferences("zhandui", Context.MODE_PRIVATE);
    }

    public static String base(Context c) {
        String b = sp(c).getString(KEY_BASE, "");
        return b == null ? "" : b;
    }

    public static void setBase(Context c, String url) {
        sp(c).edit().putString(KEY_BASE, url).apply();
    }

    public static boolean hasBase(Context c) {
        String b = base(c);
        return b.startsWith("http://") || b.startsWith("https://");
    }

    public static int textZoom(Context c) {
        int z = sp(c).getInt(KEY_TEXT_ZOOM, 100);
        return z < 50 || z > 250 ? 100 : z;
    }

    public static void setTextZoom(Context c, int zoom) {
        sp(c).edit().putInt(KEY_TEXT_ZOOM, zoom).apply();
    }

    public static boolean notifyOn(Context c) {
        return sp(c).getBoolean(KEY_NOTIFY_ON, true);
    }

    public static void setNotifyOn(Context c, boolean on) {
        sp(c).edit().putBoolean(KEY_NOTIFY_ON, on).apply();
    }

    /** Off means notifications still arrive but say only "N new messages". */
    public static boolean notifyShowBody(Context c) {
        return sp(c).getBoolean("notify_show_body", true);
    }

    public static void setNotifyShowBody(Context c, boolean show) {
        sp(c).edit().putBoolean("notify_show_body", show).apply();
    }

    /** Poll period in seconds; 0 means the user turned reminders off. */
    public static int pollSeconds(Context c) {
        if (!notifyOn(c)) {
            return 0;
        }
        try {
            return Integer.parseInt(sp(c).getString(KEY_POLL_SECONDS, "15"));
        } catch (NumberFormatException e) {
            return 15;
        }
    }

    public static void setPollSeconds(Context c, int seconds) {
        sp(c).edit().putString(KEY_POLL_SECONDS, String.valueOf(seconds)).apply();
    }

    public static long groupSeen(Context c) {
        return sp(c).getLong("seen_group|" + base(c), -1L);
    }

    public static void setGroupSeen(Context c, long id) {
        sp(c).edit().putLong("seen_group|" + base(c), id).apply();
    }

    /** When we last re-read the newest group id, to notice a rebuilt site. */
    public static long groupCheckedAt(Context c) {
        return sp(c).getLong("checked_group|" + base(c), 0L);
    }

    public static void setGroupCheckedAt(Context c, long at) {
        sp(c).edit().putLong("checked_group|" + base(c), at).apply();
    }

    /** Last unread count seen per DM peer, so only a rise triggers a notification. */
    public static int dmUnreadSeen(Context c, int peerId) {
        return sp(c).getInt("seen_dm|" + base(c) + "|" + peerId, -1);
    }

    public static void setDmUnreadSeen(Context c, int peerId, int unread) {
        sp(c).edit().putInt("seen_dm|" + base(c) + "|" + peerId, unread).apply();
    }

    public static void forgetDmUnread(Context c, int peerId) {
        sp(c).edit().remove("seen_dm|" + base(c) + "|" + peerId).apply();
    }

    public static boolean loginHintShown(Context c) {
        return sp(c).getBoolean("login_hint|" + base(c), false);
    }

    public static void setLoginHintShown(Context c, boolean shown) {
        sp(c).edit().putBoolean("login_hint|" + base(c), shown).apply();
    }

    /** Drop unread cursors for the site being left; pass the old base explicitly
     *  so the caller does not have to order this before setBase(). */
    public static void clearSiteState(Context c, String oldBase) {
        String prefix = "seen_group|" + oldBase;
        String prefix2 = "seen_dm|" + oldBase + "|";
        SharedPreferences.Editor ed = sp(c).edit();
        for (String key : sp(c).getAll().keySet()) {
            if (key.startsWith(prefix) || key.startsWith(prefix2)
                    || key.startsWith("login_hint|" + oldBase)
                    || key.startsWith("team_name|" + oldBase)
                    || key.startsWith("checked_group|" + oldBase)) {
                ed.remove(key);
            }
        }
        ed.apply();
    }
}
