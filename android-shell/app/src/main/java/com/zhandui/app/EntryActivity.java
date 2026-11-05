package com.zhandui.app;

import android.app.Activity;
import android.content.Intent;
import android.graphics.Bitmap;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.PowerManager;
import android.provider.Settings;
import android.text.InputType;
import android.view.View;
import android.webkit.CookieManager;
import android.widget.ArrayAdapter;
import android.widget.Button;
import android.widget.EditText;
import android.widget.ImageView;
import android.widget.SeekBar;
import android.widget.Spinner;
import android.widget.Switch;
import android.widget.TextView;

public class EntryActivity extends Activity {

    public static final String EXTRA_SETTINGS = "open_settings";
    private static final int REQ_NOTIF = 1;
    private static final int[] RATE_VALUES = {15, 60, 300};

    private EditText urlInput;
    private SeekBar zoomBar;
    private TextView zoomLabel;
    private Switch notifySwitch;
    private Switch bodySwitch;
    private Spinner rateSpinner;
    private TextView errorText;

    @Override
    protected void onCreate(Bundle saved) {
        super.onCreate(saved);

        boolean openAsSettings = getIntent() != null
                && getIntent().getBooleanExtra(EXTRA_SETTINGS, false);
        if (!openAsSettings && Prefs.hasBase(this)) {
            goToSite();
            finish();
            return;
        }

        setContentView(R.layout.activity_entry);
        TextView heading = findViewById(R.id.entry_heading);
        TextView subtitle = findViewById(R.id.entry_subtitle);
        String team = SiteBranding.teamName(this);
        ImageView badge = findViewById(R.id.brand_badge);
        Bitmap emblem = SiteBranding.badge(this);
        if (emblem != null) {
            badge.setImageBitmap(emblem);
            badge.setVisibility(View.VISIBLE);
        }
        if (openAsSettings && !team.isEmpty()) {
            heading.setText(getString(R.string.entry_title_settings_team, team));
        } else {
            heading.setText(openAsSettings ? R.string.entry_title_settings : R.string.entry_title_first);
        }
        if (!team.isEmpty()) {
            subtitle.setText(getString(R.string.entry_subtitle_team, team, Prefs.base(this)));
        }

        urlInput = findViewById(R.id.url_input);
        urlInput.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_URI
                | InputType.TYPE_TEXT_FLAG_NO_SUGGESTIONS);
        urlInput.setText(Prefs.base(this));
        zoomBar = findViewById(R.id.zoom_bar);
        zoomLabel = findViewById(R.id.zoom_value);
        notifySwitch = findViewById(R.id.notify_switch);
        bodySwitch = findViewById(R.id.body_switch);
        rateSpinner = findViewById(R.id.rate_spinner);
        errorText = findViewById(R.id.error_text);

        zoomBar.setMax(200 - 50);
        zoomBar.setProgress(Prefs.textZoom(this) - 50);
        zoomBar.setOnSeekBarChangeListener(new SeekBar.OnSeekBarChangeListener() {
            @Override
            public void onProgressChanged(SeekBar bar, int progress, boolean fromUser) {
                zoomLabel.setText(getString(R.string.entry_zoom_value, progress + 50));
            }

            @Override
            public void onStartTrackingTouch(SeekBar bar) {
            }

            @Override
            public void onStopTrackingTouch(SeekBar bar) {
            }
        });
        zoomLabel.setText(getString(R.string.entry_zoom_value, Prefs.textZoom(this)));

        notifySwitch.setChecked(Prefs.notifyOn(this));
        bodySwitch.setChecked(Prefs.notifyShowBody(this));
        ArrayAdapter<CharSequence> rateAdapter = ArrayAdapter.createFromResource(
                this, R.array.poll_rate_labels, android.R.layout.simple_spinner_item);
        rateAdapter.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item);
        rateSpinner.setAdapter(rateAdapter);
        selectRate(Prefs.notifyOn(this) ? Prefs.pollSeconds(this) : 15);

        findViewById(R.id.enter_button).setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                saveAndEnter();
            }
        });
        findViewById(R.id.battery_button).setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                openAppDetails();
            }
        });
    }

    /**
     * The standard "ignore battery optimizations" dialog does not cover what
     * ColorOS / OriginOS do on top of it, so send them to the app's own page.
     */
    private void openAppDetails() {
        try {
            startActivity(new Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
                    Uri.parse("package:" + getPackageName())));
        } catch (Exception e) {
            askBatteryExemption();
        }
    }

    private void selectRate(int seconds) {
        for (int i = 0; i < RATE_VALUES.length; i++) {
            if (RATE_VALUES[i] == seconds) {
                rateSpinner.setSelection(i);
                return;
            }
        }
        rateSpinner.setSelection(0);
    }

    private void saveAndEnter() {
        String normalised = normaliseUrl(urlInput.getText().toString());
        if (normalised == null) {
            errorText.setText(R.string.entry_error_url);
            errorText.setVisibility(View.VISIBLE);
            return;
        }
        errorText.setVisibility(View.GONE);

        String previous = Prefs.base(this);
        if (!previous.equals(normalised)) {
            // Different site: cursors and cookies belong to the old one.
            Prefs.clearSiteState(this, previous);
            CookieManager.getInstance().removeAllCookies(null);
            CookieManager.getInstance().flush();
        }

        Prefs.setBase(this, normalised);
        Prefs.setTextZoom(this, zoomBar.getProgress() + 50);
        Prefs.setNotifyOn(this, notifySwitch.isChecked());
        Prefs.setNotifyShowBody(this, bodySwitch.isChecked());
        Prefs.setPollSeconds(this, RATE_VALUES[Math.max(0, rateSpinner.getSelectedItemPosition())]);
        if (!notifySwitch.isChecked()) {
            stopService(new Intent(this, NotifyService.class));
        }

        askNotificationPermissionFirst();
    }

    /** Android 13 asks before any notification, including the ongoing one. */
    private void askNotificationPermissionFirst() {
        if (Build.VERSION.SDK_INT >= 33
                && checkSelfPermission(android.Manifest.permission.POST_NOTIFICATIONS)
                != android.content.pm.PackageManager.PERMISSION_GRANTED) {
            requestPermissions(new String[]{android.Manifest.permission.POST_NOTIFICATIONS}, REQ_NOTIF);
            return;
        }
        finishSetup();
    }

    @Override
    public void onRequestPermissionsResult(int req, String[] perms, int[] results) {
        super.onRequestPermissionsResult(req, perms, results);
        finishSetup();
    }

    private void finishSetup() {
        if (Prefs.notifyOn(this)) {
            NotifyService.start(this);
            askBatteryExemption();
        }
        goToSite();
    }

    /**
     * Without this the polling dies a few minutes after the screen goes off on most
     * Chinese ROMs, which reads to the user as "the reminders stopped working".
     */
    private void askBatteryExemption() {
        if (Build.VERSION.SDK_INT < 23) {
            return;
        }
        PowerManager pm = (PowerManager) getSystemService(POWER_SERVICE);
        if (pm == null || pm.isIgnoringBatteryOptimizations(getPackageName())) {
            return;
        }
        try {
            startActivity(new Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS,
                    Uri.parse("package:" + getPackageName())));
        } catch (Exception e) {
            try {
                startActivity(new Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS));
            } catch (Exception ignored) {
            }
        }
    }

    private void goToSite() {
        startActivity(new Intent(this, MainActivity.class));
    }

    /** Accept whatever a captain pastes: with or without scheme, with or without a slash. */
    static String normaliseUrl(String raw) {
        if (raw == null) {
            return null;
        }
        String s = raw.trim();
        if (s.isEmpty()) {
            return null;
        }
        if (!s.startsWith("http://") && !s.startsWith("https://")) {
            s = "https://" + s;
        }
        while (s.endsWith("/")) {
            s = s.substring(0, s.length() - 1);
        }
        String host = Uri.parse(s).getHost();
        if (host == null || !host.contains(".") || host.startsWith(".")) {
            return null;
        }
        String scheme = Uri.parse(s).getScheme();
        if (!"http".equals(scheme) && !"https".equals(scheme)) {
            return null;
        }
        return s;
    }
}
