package com.zhandui.app;

import android.app.job.JobInfo;
import android.app.job.JobParameters;
import android.app.job.JobScheduler;
import android.app.job.JobService;
import android.content.ComponentName;
import android.content.Context;

/**
 * Watchdog. Alarms are the fast path, but a single swallowed round used to end the
 * chain, and Chinese ROMs freeze pending alarms for apps they dislike. Jobs are owned
 * by the system, survive a killed process, and a job callback is one of the few places
 * Android 12+ still allows starting a foreground service from the background - so this
 * re-links everything within a quarter of an hour even if every alarm went missing.
 */
public class PollJobService extends JobService {

    private static final int JOB_ID = 7701;
    private static final long INTERVAL_MS = 15 * 60 * 1000L;

    public static void schedule(Context c) {
        JobScheduler js = (JobScheduler) c.getSystemService(Context.JOB_SCHEDULER_SERVICE);
        if (js == null) {
            return;
        }
        js.schedule(new JobInfo.Builder(JOB_ID, new ComponentName(c, PollJobService.class))
                .setPeriodic(INTERVAL_MS)
                .setPersisted(true)
                .build());
    }

    public static void cancel(Context c) {
        JobScheduler js = (JobScheduler) c.getSystemService(Context.JOB_SCHEDULER_SERVICE);
        if (js != null) {
            js.cancel(JOB_ID);
        }
    }

    @Override
    public boolean onStartJob(JobParameters params) {
        NotifyService.startIfEnabled(this);
        return false;
    }

    @Override
    public boolean onStopJob(JobParameters params) {
        return true;
    }
}
