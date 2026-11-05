package com.zhandui.app;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

/**
 * AlarmManager entry point. Waking through a broadcast is what keeps reminders alive
 * while the screen is off: the alarm is owned by the system, not by our process.
 */
public class TickReceiver extends BroadcastReceiver {

    static final String ACTION_TICK = "com.zhandui.app.TICK";

    @Override
    public void onReceive(Context context, Intent intent) {
        if (!Prefs.hasBase(context) || !Prefs.notifyOn(context)) {
            return;
        }
        try {
            // While the ongoing notification is up this merely delivers a start command.
            // If the ROM already killed the process, Android 12+ can refuse the restart;
            // there is nothing to do but wait for the user to open the app again.
            NotifyService.start(context);
        } catch (Exception ignored) {
        }
    }
}
