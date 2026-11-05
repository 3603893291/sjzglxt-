package com.zhandui.app;

import org.json.JSONException;
import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.IOException;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.Charset;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;

/**
 * Plain GET against the team site, reusing whatever session cookies the WebView holds.
 *
 * Kept free of android.webkit on purpose: CookieManager must be touched on the main
 * thread, so the service reads/writes cookies there and hands a header string in here.
 */
public final class SiteApi {

    public static final class Unauthorized extends IOException {
        Unauthorized() {
            super("not logged in");
        }
    }

    /** Non-zero api code from the server, i.e. a real rejection rather than a network fault. */
    public static final class ApiException extends IOException {
        public final int code;

        ApiException(String msg, int code) {
            super(msg);
            this.code = code;
        }
    }

    public static final class Result {
        public final JSONObject json;
        public final List<String> setCookies;

        Result(JSONObject json, List<String> setCookies) {
            this.json = json;
            this.setCookies = setCookies;
        }
    }

    private SiteApi() {
    }

    public static Result get(String fullUrl, String cookieHeader) throws IOException, Unauthorized, ApiException {
        HttpURLConnection conn = null;
        try {
            conn = (HttpURLConnection) new URL(fullUrl).openConnection();
            conn.setConnectTimeout(10000);
            conn.setReadTimeout(45000);
            conn.setRequestMethod("GET");
            conn.setRequestProperty("Accept", "application/json");
            conn.setRequestProperty("X-Requested-With", "XMLHttpRequest");
            if (cookieHeader != null && !cookieHeader.isEmpty()) {
                conn.setRequestProperty("Cookie", cookieHeader);
            }
            conn.setInstanceFollowRedirects(false);

            int status = conn.getResponseCode();
            List<String> cookies = new ArrayList<>();
            Map<String, List<String>> head = conn.getHeaderFields();
            if (head != null) {
                for (Map.Entry<String, List<String>> e : head.entrySet()) {
                    if (e.getKey() != null && e.getKey().equalsIgnoreCase("Set-Cookie") && e.getValue() != null) {
                        cookies.addAll(e.getValue());
                    }
                }
            }

            String body = read(status >= 400 ? conn.getErrorStream() : conn.getInputStream());

            if (status == 401 || status == 403) {
                throw new Unauthorized();
            }
            if (status >= 500) {
                throw new IOException("server " + status);
            }
            if (status < 200 || status >= 300) {
                throw new IOException("http " + status);
            }

            JSONObject json;
            try {
                json = new JSONObject(body);
            } catch (JSONException e) {
                // Not JSON: usually an nginx/html error page or the site moved behind a login form.
                throw new IOException("bad response");
            }
            int code = json.optInt("code", 0);
            if (code != 0) {
                if (code == 401) {
                    throw new Unauthorized();
                }
                throw new ApiException(json.optString("msg", "failed"), code);
            }
            return new Result(json, cookies);
        } finally {
            if (conn != null) {
                conn.disconnect();
            }
        }
    }

    private static String read(InputStream in) throws IOException {
        if (in == null) {
            return "";
        }
        StringBuilder sb = new StringBuilder();
        try (BufferedReader r = new BufferedReader(new InputStreamReader(in, Charset.forName("UTF-8")))) {
            char[] buf = new char[4096];
            int n;
            while ((n = r.read(buf)) > 0) {
                sb.append(buf, 0, n);
            }
        }
        return sb.toString();
    }
}
