package com.zhandui.app;

import android.app.AlarmManager;
import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.os.Handler;
import android.os.IBinder;
import android.os.Looper;
import android.os.PowerManager;
import android.os.SystemClock;
import android.graphics.Bitmap;

import org.json.JSONArray;
import org.json.JSONObject;

import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.Locale;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

/**
 * A persistent foreground service that polls the team site for new chat and posts
 * notifications. No push channel exists on the server, so this is polling with the
 * notification raised locally; the ongoing notification is what keeps the process
 * alive on aggressive Chinese ROMs.
 */
public class NotifyService extends Service {

    public static final String ACTION_CHECK = "com.zhandui.app.CHECK_NOW";
    private static final int ID_ONGOING = 1;
    private static final int ID_DM_BASE = 2000;
    private static final int ID_GROUP = 3001;
    private static final String CH_ONGOING = "ongoing";
    private static final String CH_MESSAGE = "message";
    private static final int MAX_DM_ALERTS_PER_TICK = 3;
    private static final int MAX_BACKOFF_SECONDS = 120;
    private static final int ALARM_BACKSTOP_SECONDS = 300;
    private static final long GROUP_RECHECK_MS = 10 * 60 * 1000L;

    private final Handler main = new Handler(Looper.getMainLooper());
    private ExecutorService worker;
    private NotificationManager nm;
    private PowerManager.WakeLock wake;
    private volatile boolean running;
    private volatile long lastOkAt;
    private int failures;

    public static void start(Context c) {
        Intent i = new Intent(c, NotifyService.class);
        if (Build.VERSION.SDK_INT >= 26) {
            c.startForegroundService(i);
        } else {
            c.startService(i);
        }
    }

    public static void startIfEnabled(Context c) {
        if (Prefs.hasBase(c) && Prefs.notifyOn(c)) {
            start(c);
        } else {
            c.stopService(new Intent(c, NotifyService.class));
        }
    }

    /**
     * The alarm is a backstop now, not the driver. What we actually observed was the
     * ongoing notification staying up while the unread count froze - a live process
     * that simply never woke again. A broadcast-driven alarm is precisely the first
     * thing Doze and Chinese ROM power managers defer; a foreground service that owns
     * its own loop and holds a wake lock per round is not.
     */
    static void scheduleNextAlarm(Context c, int seconds) {
        AlarmManager am = (AlarmManager) c.getSystemService(Context.ALARM_SERVICE);
        if (am == null) {
            return;
        }
        long at = SystemClock.elapsedRealtime() + seconds * 1000L;
        PendingIntent pi = tickIntent(c);
        if (Build.VERSION.SDK_INT >= 23) {
            am.setAndAllowWhileIdle(AlarmManager.ELAPSED_REALTIME_WAKEUP, at, pi);
        } else {
            am.set(AlarmManager.ELAPSED_REALTIME_WAKEUP, at, pi);
        }
    }

    static PendingIntent tickIntent(Context c) {
        Intent i = new Intent(c, TickReceiver.class).setAction(TickReceiver.ACTION_TICK);
        return PendingIntent.getBroadcast(c, 6001, i, flagImmutable());
    }

    @Override
    public void onCreate() {
        super.onCreate();
        nm = (NotificationManager) getSystemService(Context.NOTIFICATION_SERVICE);
        createChannels();
        worker = Executors.newSingleThreadExecutor();
        enterForeground();
        PollJobService.schedule(this);
        scheduleNextAlarm(this, ALARM_BACKSTOP_SECONDS);
        startLoop();
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        if (!Prefs.hasBase(this) || !Prefs.notifyOn(this)) {
            PollJobService.cancel(this);
            running = false;
            stopSelf();
            return START_NOT_STICKY;
        }
        enterForeground();
        // Covers the alarm and the job both landing on a dead loop.
        startLoop();
        return START_STICKY;
    }

    private void enterForeground() {
        try {
            startForeground(ID_ONGOING, buildOngoing(getString(R.string.ongoing_running), null));
        } catch (Exception e) {
            // Android 12+ can refuse a background foreground-start; the backstop alarm
            // and the job both retry, and opening the app always works.
        }
    }

    /**
     * Poll loop. The interval is re-read every round so a settings change takes effect
     * without restarting the service.
     *
     * The wake lock is held across the sleep too, not just the request. Measured on a
     * dark screen: releasing it between rounds let the CPU drop, which stretched a
     * 15-second interval to roughly 27 seconds. Holding it keeps the interval honest,
     * at a battery cost the user accepted along with the ongoing notification - the
     * 5-minute setting is there for anyone who would rather save the power.
     */
    private void startLoop() {
        if (running) {
            return;
        }
        running = true;
        worker.execute(new Runnable() {
            @Override
            public void run() {
                try {
                    while (running) {
                        int period = Prefs.pollSeconds(NotifyService.this);
                        if (period <= 0) {
                            break;
                        }
                        acquireWake(period);
                        try {
                            tick();
                        } catch (Throwable t) {
                            // One bad round must never take the loop down with it.
                        }
                        int wait = failures > 0
                                ? Math.min(period * (failures + 1), MAX_BACKOFF_SECONDS) : period;
                        try {
                            Thread.sleep(wait * 1000L);
                        } catch (InterruptedException e) {
                            break;
                        }
                    }
                } finally {
                    running = false;
                    releaseWake();
                }
                if (Prefs.pollSeconds(NotifyService.this) <= 0) {
                    main.post(new Runnable() {
                        @Override
                        public void run() {
                            stopSelf();
                        }
                    });
                }
            }
        });
    }

    private void acquireWake(int periodSeconds) {
        PowerManager pm = (PowerManager) getSystemService(Context.POWER_SERVICE);
        if (pm == null) {
            return;
        }
        if (wake == null) {
            wake = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "zhandui:poll");
            wake.setReferenceCounted(false);
        }
        try {
            // Must outlast a round plus the sleep after it: two endpoints in series can
            // each wait 45s, and the interval itself is spent asleep.
            wake.acquire((periodSeconds * 2 + 100) * 1000L);
        } catch (Exception ignored) {
        }
    }

    private void releaseWake() {
        try {
            if (wake != null && wake.isHeld()) {
                wake.release();
            }
        } catch (Exception ignored) {
        }
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }

    @Override
    public void onDestroy() {
        running = false;
        releaseWake();
        if (worker != null) {
            worker.shutdownNow();
        }
        super.onDestroy();
    }

    private void tick() {
        String base = Prefs.base(this);
        if (!base.startsWith("http")) {
            return;
        }
        if (!canNotify()) {
            updateOngoing(getString(R.string.ongoing_no_permission), null);
        }
        String cookie = CookieJar.read(this, base);

        if (cookie == null || cookie.trim().isEmpty()) {
            // The SPA renders its own login form at the site root, so no fragment here.
            updateOngoing(getString(R.string.ongoing_need_login), null);
            return;
        }
        if (SiteBranding.teamName(this).isEmpty()) {
            // First logged-in round: learn the team's name, then re-title the ongoing row.
            SiteBranding.refresh(this);
            updateOngoing(getString(R.string.ongoing_running), null);
        }

        int dmUnread = -1;
        int groupUnread = -1;
        try {
            dmUnread = pollDm(base, cookie);
            groupUnread = pollGroup(base, cookie);
            failures = 0;
            lastOkAt = System.currentTimeMillis();
            StringBuilder text = new StringBuilder();
            text.append(getString(R.string.ongoing_running));
            if (dmUnread > 0) {
                text.append(" · ").append(getString(R.string.ongoing_dm, dmUnread));
            }
            if (groupUnread > 0) {
                text.append(" · ").append(getString(R.string.ongoing_group, groupUnread));
            }
            updateOngoing(stamped(text.toString()), null);
        } catch (SiteApi.Unauthorized e) {
            failures = 0;
            updateOngoing(getString(R.string.ongoing_need_login), null);
        } catch (Exception e) {
            failures++;
            updateOngoing(getString(R.string.ongoing_offline), null);
        }
    }

    /**
     * Private messages: the server already answers with a per-conversation unread
     * count and a snippet, and that endpoint is read-only.
     *
     * Deliberately never touches /api/chat/dm/thread, because that call advances the
     * server-side read cursor and would clear the unread badge on the web without
     * anyone actually reading anything.
     */
    private int pollDm(String base, String cookie) throws Exception {
        SiteApi.Result res = SiteApi.get(base + "/api/chat/dm/conversations", cookie);
        CookieJar.writeBack(this, base, res.setCookies);
        JSONObject data = res.json.optJSONObject("data");
        if (data == null) {
            return 0;
        }
        JSONArray list = data.optJSONArray("list");
        int total = 0;
        int alerts = 0;
        if (list != null) {
            for (int i = 0; i < list.length(); i++) {
                JSONObject row = list.optJSONObject(i);
                if (row == null) {
                    continue;
                }
                int peer = row.optInt("peer_id", 0);
                int unread = row.optInt("unread", 0);
                if (peer <= 0) {
                    continue;
                }
                total += unread;
                int prev = Prefs.dmUnreadSeen(this, peer);
                if (prev < 0) {
                    // First time on this device: take a baseline, do not ring for old mail.
                    Prefs.setDmUnreadSeen(this, peer, unread);
                    continue;
                }
                if (unread > prev && alerts < MAX_DM_ALERTS_PER_TICK) {
                    String nick = pickFirst(row.optString("peer_nick"), row.optString("peer_name"));
                    String snip = row.optString("last_snip", "");
                    String body = Prefs.notifyShowBody(this) && !snip.isEmpty()
                            ? snip : getString(R.string.notif_dm_unread, unread);
                    postMessage(ID_DM_BASE + peer, nick, body,
                            "#/chat?dm=" + peer + "&name=" + Uri.encode(nick));
                    alerts++;
                }
                Prefs.setDmUnreadSeen(this, peer, unread);
            }
        }
        return total;
    }

    /**
     * Team group chat: there is no server-side read cursor for it, so the app keeps
     * its own "last id seen" per site and asks for anything newer.
     */
    private int pollGroup(String base, String cookie) throws Exception {
        long seen = Prefs.groupSeen(this);
        long after = seen < 0 ? 0 : seen;
        SiteApi.Result res = SiteApi.get(base + "/api/chat/messages?after_id=" + after, cookie);
        CookieJar.writeBack(this, base, res.setCookies);
        JSONObject data = res.json.optJSONObject("data");
        if (data == null) {
            return 0;
        }
        JSONArray list = data.optJSONArray("list");
        if (list == null) {
            return 0;
        }
        long maxId = seen;
        int fresh = 0;
        String lastLine = "";
        long meId = data.optLong("me_id", 0);
        for (int i = 0; i < list.length(); i++) {
            JSONObject row = list.optJSONObject(i);
            if (row == null) {
                continue;
            }
            long id = row.optLong("id", 0);
            if (id > maxId) {
                maxId = id;
            }
            if (seen < 0 || row.optLong("user_id", 0) == meId) {
                continue;
            }
            fresh++;
            lastLine = pickFirst(row.optString("nickname"), "?") + ": " + row.optString("content", "");
        }
        if (seen < 0) {
            // Baseline only, same rule the web badge uses.
            Prefs.setGroupSeen(this, Math.max(maxId, 0));
            return 0;
        }
        Prefs.setGroupSeen(this, Math.max(maxId, seen));
        if (fresh > 0) {
            String count = fresh >= 100 ? "99+" : String.valueOf(fresh);
            String body;
            if (!Prefs.notifyShowBody(this) || lastLine.isEmpty()) {
                body = getString(R.string.notif_group_multi, count);
            } else if (fresh > 1) {
                body = getString(R.string.notif_group_multi, count) + " · " + lastLine;
            } else {
                body = lastLine;
            }
            postMessage(ID_GROUP, getString(R.string.notif_group_title), body, "#/chat");
        } else {
            recheckGroupFloor(base, cookie, seen);
        }
        return fresh;
    }

    /**
     * A rebuilt or emptied site restarts message ids below our stored cursor, which
     * would silently kill group reminders forever. Every so often re-read the newest
     * id and pull the cursor back when the site has fallen behind us.
     */
    private void recheckGroupFloor(String base, String cookie, long seen) throws Exception {
        long now = System.currentTimeMillis();
        if (now - Prefs.groupCheckedAt(this) < GROUP_RECHECK_MS) {
            return;
        }
        Prefs.setGroupCheckedAt(this, now);
        SiteApi.Result latest = SiteApi.get(base + "/api/chat/messages?after_id=0", cookie);
        CookieJar.writeBack(this, base, latest.setCookies);
        JSONObject data = latest.json.optJSONObject("data");
        JSONArray rows = data == null ? null : data.optJSONArray("list");
        if (rows == null || rows.length() == 0) {
            return;
        }
        long newest = rows.optJSONObject(rows.length() - 1).optLong("id", 0);
        if (newest > 0 && newest < seen) {
            Prefs.setGroupSeen(this, newest);
        }
    }

    private void postMessage(int id, String title, String text, String fragment) {
        Intent tap = new Intent(this, MainActivity.class);
        tap.putExtra(MainActivity.EXTRA_FRAGMENT, fragment);
        tap.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP);
        Notification.Builder builder = new Notification.Builder(this, CH_MESSAGE)
                .setSmallIcon(R.drawable.ic_stat_bubble)
                .setContentTitle(title)
                .setContentText(text)
                .setStyle(new Notification.BigTextStyle().bigText(text))
                .setContentIntent(pending(id, tap))
                .setAutoCancel(true)
                .setWhen(System.currentTimeMillis());
        Bitmap emblem = SiteBranding.badge(this);
        if (emblem != null) {
            builder.setLargeIcon(emblem);
        }
        Notification n = builder.build();
        try {
            nm.notify(id, n);
        } catch (Exception ignored) {
        }
    }

    /**
     * The ongoing row doubles as the diagnostic: if this clock stops moving, polling
     * stopped. That is how "only works when I open the app" gets told apart from a
     * frozen process or a dead alarm chain without needing adb.
     */
    private String stamped(String text) {
        if (lastOkAt <= 0) {
            return text;
        }
        return text + " · " + new SimpleDateFormat("HH:mm", Locale.US).format(new Date(lastOkAt));
    }

    private void updateOngoing(String text, String fragment) {
        Notification n = buildOngoing(text, fragment);
        try {
            nm.notify(ID_ONGOING, n);
        } catch (Exception ignored) {
        }
    }

    private Notification buildOngoing(String text, String fragment) {
        Intent tap = new Intent(this, MainActivity.class);
        if (fragment != null) {
            tap.putExtra(MainActivity.EXTRA_FRAGMENT, fragment);
        }
        tap.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP);

        Intent settings = new Intent(this, EntryActivity.class);
        settings.putExtra(EntryActivity.EXTRA_SETTINGS, true);
        settings.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK);

        Intent check = new Intent(this, NotifyService.class).setAction(ACTION_CHECK);

        Notification.Action checkAction = new Notification.Action.Builder(
                null, getString(R.string.ongoing_action_check),
                PendingIntent.getService(this, 4001, check, flagImmutable())).build();
        Notification.Action settingsAction = new Notification.Action.Builder(
                null, getString(R.string.ongoing_action_settings),
                PendingIntent.getActivity(this, 4002, settings, flagImmutable())).build();

        Notification.Builder builder = new Notification.Builder(this, CH_ONGOING)
                .setSmallIcon(R.drawable.ic_stat_bubble)
                .setContentTitle(SiteBranding.title(this))
                .setContentText(text)
                .setContentIntent(pending(ID_ONGOING, tap))
                .setOngoing(true)
                .addAction(checkAction)
                .addAction(settingsAction);
        Bitmap emblem = SiteBranding.badge(this);
        if (emblem != null) {
            builder.setLargeIcon(emblem);
        }
        return builder.build();
    }

    private PendingIntent pending(int code, Intent intent) {
        return PendingIntent.getActivity(this, code, intent, flagImmutable());
    }

    private static int flagImmutable() {
        return Build.VERSION.SDK_INT >= 23
                ? PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE
                : PendingIntent.FLAG_UPDATE_CURRENT;
    }

    private void createChannels() {
        if (Build.VERSION.SDK_INT < 26) {
            return;
        }
        NotificationChannel ongoing = new NotificationChannel(
                CH_ONGOING, getString(R.string.channel_ongoing), NotificationManager.IMPORTANCE_LOW);
        ongoing.setShowBadge(false);
        ongoing.setDescription(getString(R.string.channel_ongoing_desc));
        nm.createNotificationChannel(ongoing);

        NotificationChannel message = new NotificationChannel(
                CH_MESSAGE, getString(R.string.channel_message), NotificationManager.IMPORTANCE_HIGH);
        message.setDescription(getString(R.string.channel_message_desc));
        nm.createNotificationChannel(message);
    }

    private boolean canNotify() {
        if (Build.VERSION.SDK_INT < 33) {
            return true;
        }
        return checkSelfPermission(android.Manifest.permission.POST_NOTIFICATIONS)
                == PackageManager.PERMISSION_GRANTED;
    }

    private static String pickFirst(String a, String b) {
        return a != null && !a.isEmpty() ? a : (b == null ? "" : b);
    }
}
