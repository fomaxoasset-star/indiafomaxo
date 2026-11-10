package `in`.fomaxo.pos

import android.annotation.SuppressLint
import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.print.PrintAttributes
import android.print.PrintManager
import android.view.ViewGroup
import android.webkit.JavascriptInterface
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import android.webkit.WebChromeClient
import android.widget.FrameLayout
import android.widget.Toast
import androidx.activity.ComponentActivity
import androidx.activity.OnBackPressedCallback
import org.json.JSONObject

/**
 * The FOMAXO till (pos.fomaxo.in) in its own Android app window.
 * The web app does all the work; this adds printing, links that open outside,
 * and the home-screen widgets.
 */
class MainActivity : ComponentActivity() {

    companion object {
        const val BASE = "https://pos.fomaxo.in/"
        const val HOST = "pos.fomaxo.in"
        const val EXTRA_GO = "go"
    }

    private lateinit var web: WebView
    private var pageReady = false

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        web = WebView(this)
        web.setBackgroundColor(0xFF0A0A0B.toInt())
        setContentView(FrameLayout(this).apply {
            addView(web, FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT))
        })

        web.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            databaseEnabled = true
            mediaPlaybackRequiresUserGesture = false
            setSupportMultipleWindows(false)
            userAgentString = "$userAgentString FomaxoPOS-Android/${BuildConfigCompat.versionName(this@MainActivity)}"
        }
        web.addJavascriptInterface(Bridge(), "FomaxoAndroid")
        web.webChromeClient = WebChromeClient()
        web.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
                val uri = request.url
                if ((uri.scheme == "https" || uri.scheme == "http") && uri.host == HOST) return false
                openOutside(uri.toString())
                return true
            }

            override fun onPageFinished(view: WebView, url: String) {
                if (!url.startsWith(BASE)) return
                pageReady = true
                // window.print and window.open do nothing inside a WebView: send them to Android.
                view.evaluateJavascript(
                    """
                    (function () {
                      if (window.__fxAndroid) return; window.__fxAndroid = true;
                      window.print = function () { FomaxoAndroid.print(); };
                      window.open = function (u) { if (u) FomaxoAndroid.openUrl(new URL(u, location.href).href); return null; };
                    })();
                    """.trimIndent(), null
                )
                syncWidget()
            }

            override fun onReceivedError(view: WebView, request: WebResourceRequest, error: WebResourceError) {
                // Only when the till page itself cannot load (first open with no internet).
                if (request.isForMainFrame) {
                    pageReady = false
                    view.loadDataWithBaseURL(
                        null,
                        """
                        <html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head>
                        <body style="margin:0;background:#0A0A0B;color:#F4F1EA;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;text-align:center">
                        <div style="padding:24px"><div style="color:#E6C06A;font-size:22px;font-weight:bold;letter-spacing:4px">FOMAXO</div>
                        <p style="color:#A8A29A">No internet connection.<br>Connect to Wi-Fi or mobile data.</p>
                        <a href="$BASE" style="display:inline-block;margin-top:8px;padding:14px 28px;border-radius:14px;background:#D9B25E;color:#16120A;font-weight:bold;text-decoration:none">Try again</a></div>
                        </body></html>
                        """.trimIndent(),
                        "text/html", "utf-8", null
                    )
                }
            }
        }

        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                if (web.canGoBack()) web.goBack() else moveTaskToBack(true)
            }
        })

        if (savedInstanceState != null) {
            web.restoreState(savedInstanceState)
        } else {
            val go = intent.getStringExtra(EXTRA_GO)
            web.loadUrl(if (go != null) "$BASE?go=$go" else BASE)
        }
    }

    // A widget button was tapped while the app was already open: switch screens without reloading.
    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        val go = intent.getStringExtra(EXTRA_GO) ?: return
        if (go !in listOf("sell", "sales", "reports")) return
        if (pageReady) {
            web.evaluateJavascript("window.fxGo ? window.fxGo('$go') : (location.href = '$BASE?go=$go');", null)
        } else {
            web.loadUrl("$BASE?go=$go")
        }
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        web.saveState(outState)
    }

    override fun onResume() {
        super.onResume()
        web.onResume()
    }

    override fun onPause() {
        // Leaving the app: bring the widget up to date.
        syncWidget()
        web.onPause()
        super.onPause()
    }

    /** Reads the login from the page (it lives in localStorage) and refreshes the widgets with it. */
    private fun syncWidget() {
        if (!pageReady) return
        web.evaluateJavascript(
            "(function(){try{return JSON.stringify({t:localStorage.getItem('fx_token'),u:localStorage.getItem('fx_user')});}catch(e){return null;}})()"
        ) { raw ->
            try {
                if (raw == null || raw == "null") return@evaluateJavascript
                val outer = JSONObject(JSONObject("{\"v\":$raw}").getString("v"))
                val token = parseStored(outer.optString("t", ""))
                val user = parseStored(outer.optString("u", ""))
                val role = try { if (user.isEmpty()) "" else JSONObject(user).optString("role", "") } catch (e: Exception) { "" }
                Widgets.saveLogin(this, token, role)
                Widgets.refreshAll(this)
            } catch (e: Exception) {
                // Page not ready yet; next time.
            }
        }
    }

    /** localStorage values are saved with JSON.stringify, so a token is stored as "\"abc\"". */
    private fun parseStored(v: String): String {
        if (v.isEmpty() || v == "null") return ""
        return try {
            val parsed = JSONObject("{\"v\":$v}").get("v")
            if (parsed is String) parsed else v
        } catch (e: Exception) { v }
    }

    private fun openOutside(url: String) {
        try {
            startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
        } catch (e: ActivityNotFoundException) {
            Toast.makeText(this, "No app on this phone can open this link.", Toast.LENGTH_LONG).show()
        }
    }

    private fun printPage() {
        val pm = getSystemService(Context.PRINT_SERVICE) as PrintManager
        val attrs = PrintAttributes.Builder().build()
        pm.print("FOMAXO receipt", web.createPrintDocumentAdapter("FOMAXO receipt"), attrs)
    }

    /** Called from the web app as window.FomaxoAndroid. */
    inner class Bridge {
        @JavascriptInterface
        fun print() { runOnUiThread { printPage() } }

        @JavascriptInterface
        fun openUrl(url: String) { runOnUiThread { openOutside(url) } }

        /** After a sale, a login or a logout. */
        @JavascriptInterface
        fun refresh() { runOnUiThread { syncWidget() } }

        @JavascriptInterface
        fun version(): String = BuildConfigCompat.versionName(this@MainActivity)
    }
}

object BuildConfigCompat {
    fun versionName(ctx: Context): String = try {
        ctx.packageManager.getPackageInfo(ctx.packageName, 0).versionName ?: "1"
    } catch (e: Exception) { "1" }
}
