package com.zhandui.app;

import android.content.Context;
import android.os.Handler;
import android.os.Looper;
import android.webkit.CookieManager;

import java.util.List;
import java.util.concurrent.Callable;
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicReference;

/**
 * Cookie handoff between the WebView and native requests.
 *
 * CookieManager is a main-thread API, so anything polling off the main thread has to
 * hop. The write-back matters: the site rotates the session cookie and renews
 * remember_me on responses the native side receives, and if those never reach the
 * jar the two halves drift apart and the user gets logged out mid-chat.
 */
public final class CookieJar {

    private static final Handler main = new Handler(Looper.getMainLooper());

    private CookieJar() {
    }

    public static String read(final Context c, final String url) {
        return onMain(new Callable<String>() {
            @Override
            public String call() {
                String v = CookieManager.getInstance().getCookie(url);
                return v == null ? "" : v;
            }
        });
    }

    public static void writeBack(Context c, final String url, List<String> cookies) {
        if (cookies == null || cookies.isEmpty()) {
            return;
        }
        final String[] jar = cookies.toArray(new String[0]);
        main.post(new Runnable() {
            @Override
            public void run() {
                CookieManager cm = CookieManager.getInstance();
                for (String raw : jar) {
                    cm.setCookie(url, raw);
                }
                cm.flush();
            }
        });
    }

    private static <T> T onMain(Callable<T> job) {
        final AtomicReference<T> box = new AtomicReference<>();
        final CountDownLatch latch = new CountDownLatch(1);
        main.post(new Runnable() {
            @Override
            public void run() {
                try {
                    box.set(job.call());
                } catch (Exception ignored) {
                }
                latch.countDown();
            }
        });
        try {
            latch.await(3, TimeUnit.SECONDS);
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
        }
        return box.get();
    }
}
