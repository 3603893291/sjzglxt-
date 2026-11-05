package com.zhandui.app;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

/** Re-attach the ongoing notification after a reboot or an in-place upgrade. */
public class BootReceiver extends BroadcastReceiver {

    @Override
    public void onReceive(Context context, Intent intent) {
        NotifyService.startIfEnabled(context);
    }
}
