package `in`.fomaxo.pos

import android.app.PendingIntent
import android.appwidget.AppWidgetManager
import android.appwidget.AppWidgetProvider
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.widget.RemoteViews
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.text.NumberFormat
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import kotlin.concurrent.thread

/** Big widget: today's total, sales count, cash / UPI / card, New sale and Sales buttons. */
class BigSalesWidget : AppWidgetProvider() {
    override fun onUpdate(context: Context, manager: AppWidgetManager, ids: IntArray) = Widgets.refreshAsync(this, context)
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action == Widgets.ACTION_REFRESH) Widgets.refreshAsync(this, context) else super.onReceive(context, intent)
    }
}

/** Small widget: today's total and number of sales. */
class TodayWidget : AppWidgetProvider() {
    override fun onUpdate(context: Context, manager: AppWidgetManager, ids: IntArray) = Widgets.refreshAsync(this, context)
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action == Widgets.ACTION_REFRESH) Widgets.refreshAsync(this, context) else super.onReceive(context, intent)
    }
}

/** Small widget: one big New sale button. */
class NewSaleWidget : AppWidgetProvider() {
    override fun onUpdate(context: Context, manager: AppWidgetManager, ids: IntArray) {
        Widgets.drawNewSale(context)
    }
}

object Widgets {
    const val ACTION_REFRESH = "in.fomaxo.pos.REFRESH"
    private const val PREFS = "fomaxo_widget"

    fun saveLogin(ctx: Context, token: String, role: String) {
        val p = prefs(ctx)
        if (p.getString("token", "") == token && p.getString("role", "") == role) return
        p.edit().putString("token", token).putString("role", role)
            .remove("total").remove("count").remove("cash").remove("upi").remove("card").remove("updated").remove("day")
            .apply()
    }

    /** Fetch today's numbers in the background, then redraw every widget. */
    fun refreshAll(ctx: Context) {
        val app = ctx.applicationContext
        thread { fetch(app); drawAll(app) }
    }

    fun refreshAsync(receiver: android.content.BroadcastReceiver, ctx: Context) {
        val app = ctx.applicationContext
        drawAll(app) // show what we have at once
        val pending = receiver.goAsync()
        thread {
            try { fetch(app); drawAll(app) } finally { pending.finish() }
        }
    }

    private fun prefs(ctx: Context) = ctx.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    private fun today(): String {
        val f = SimpleDateFormat("yyyy-MM-dd", Locale.US)
        f.timeZone = java.util.TimeZone.getTimeZone("Asia/Kolkata")
        return f.format(Date())
    }

    /** Sums today's paid sales from the till's own sales list (staff see their own, managers all stores). */
    private fun fetch(ctx: Context) {
        val p = prefs(ctx)
        val token = p.getString("token", "") ?: ""
        if (token.isEmpty()) return
        val day = today()
        var conn: HttpURLConnection? = null
        try {
            conn = URL("${MainActivity.BASE}api.php?a=sales&from=$day&to=$day").openConnection() as HttpURLConnection
            conn.connectTimeout = 10000
            conn.readTimeout = 15000
            conn.setRequestProperty("Accept", "application/json")
            conn.setRequestProperty("X-Auth-Token", token)
            val code = conn.responseCode
            if (code == 401) {
                p.edit().putString("token", "").apply()
                return
            }
            if (code != 200) {
                p.edit().putBoolean("stale", true).apply()
                return
            }
            val body = conn.inputStream.bufferedReader().use { it.readText() }
            val sales = JSONObject(body).getJSONArray("sales")
            var total = 0L; var count = 0; var cash = 0L; var upi = 0L; var card = 0L
            for (i in 0 until sales.length()) {
                val s = sales.getJSONObject(i)
                if (s.optString("status") != "paid") continue
                val t = s.optLong("total")
                total += t; count++
                when (s.optString("method")) {
                    "cash" -> cash += t
                    "upi" -> upi += t
                    else -> card += t
                }
            }
            p.edit().putLong("total", total).putInt("count", count).putLong("cash", cash).putLong("upi", upi).putLong("card", card)
                .putString("day", day).putLong("updated", System.currentTimeMillis()).putBoolean("stale", false).apply()
        } catch (e: Exception) {
            p.edit().putBoolean("stale", true).apply() // offline: keep the last numbers
        } finally {
            conn?.disconnect()
        }
    }

    /** Amounts are in paise. */
    private fun inr(paise: Long): String {
        val f = NumberFormat.getNumberInstance(Locale("en", "IN"))
        f.maximumFractionDigits = if (paise % 100 == 0L) 0 else 2
        f.minimumFractionDigits = f.maximumFractionDigits
        return "₹" + f.format(paise / 100.0)
    }

    private fun openApp(ctx: Context, go: String, code: Int): PendingIntent {
        val i = Intent(ctx, MainActivity::class.java)
            .putExtra(MainActivity.EXTRA_GO, go)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
        return PendingIntent.getActivity(ctx, code, i, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
    }

    private fun refreshIntent(ctx: Context, cls: Class<*>, code: Int): PendingIntent {
        val i = Intent(ctx, cls).setAction(ACTION_REFRESH)
        return PendingIntent.getBroadcast(ctx, code, i, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
    }

    private class Numbers(
        val loggedIn: Boolean, val hasData: Boolean, val total: Long, val count: Int,
        val cash: Long, val upi: Long, val card: Long, val updated: Long, val stale: Boolean, val manager: Boolean
    )

    private fun numbers(ctx: Context): Numbers {
        val p = prefs(ctx)
        val loggedIn = !p.getString("token", "").isNullOrEmpty()
        val sameDay = p.getString("day", "") == today()
        val has = p.contains("total")
        // A new day starts at ₹0 until the first update.
        return Numbers(
            loggedIn, has,
            if (sameDay) p.getLong("total", 0) else 0, if (sameDay) p.getInt("count", 0) else 0,
            if (sameDay) p.getLong("cash", 0) else 0, if (sameDay) p.getLong("upi", 0) else 0, if (sameDay) p.getLong("card", 0) else 0,
            p.getLong("updated", 0), p.getBoolean("stale", false), p.getString("role", "") == "manager"
        )
    }

    private fun salesWord(n: Int) = if (n == 1) "1 sale" else "$n sales"

    private fun time(ms: Long) = SimpleDateFormat("h:mm a", Locale.US).format(Date(ms)).lowercase(Locale.US)

    fun drawAll(ctx: Context) {
        val m = AppWidgetManager.getInstance(ctx)
        val n = numbers(ctx)

        // Big widget
        val bigIds = m.getAppWidgetIds(ComponentName(ctx, BigSalesWidget::class.java))
        if (bigIds.isNotEmpty()) {
            val v = RemoteViews(ctx.packageName, R.layout.widget_big)
            if (!n.loggedIn) {
                v.setTextViewText(R.id.label, "Today's sales")
                v.setTextViewText(R.id.total, "₹ —")
                v.setTextViewText(R.id.detail, "Open the app and log in")
                v.setTextViewText(R.id.updated, "")
            } else {
                v.setTextViewText(R.id.label, if (n.manager) "Today's sales · all stores" else "Your sales today")
                v.setTextViewText(R.id.total, if (n.hasData) inr(n.total) else "₹ —")
                val parts = mutableListOf(salesWord(n.count))
                if (n.cash > 0) parts.add("Cash " + inr(n.cash))
                if (n.upi > 0) parts.add("UPI " + inr(n.upi))
                if (n.card > 0) parts.add("Card " + inr(n.card))
                v.setTextViewText(R.id.detail, if (n.hasData) parts.joinToString("  ·  ") else "Updating…")
                v.setTextViewText(
                    R.id.updated,
                    if (n.updated == 0L) "" else (if (n.stale) "offline · " else "updated ") + time(n.updated)
                )
            }
            v.setOnClickPendingIntent(R.id.btn_new_sale, openApp(ctx, "sell", 1))
            v.setOnClickPendingIntent(R.id.btn_sales, openApp(ctx, "sales", 2))
            v.setOnClickPendingIntent(R.id.numbers, if (n.loggedIn) refreshIntent(ctx, BigSalesWidget::class.java, 3) else openApp(ctx, "sell", 4))
            v.setOnClickPendingIntent(R.id.header, openApp(ctx, if (n.manager) "reports" else "sales", 5))
            m.updateAppWidget(bigIds, v)
        }

        // Small "Today" widget
        val todayIds = m.getAppWidgetIds(ComponentName(ctx, TodayWidget::class.java))
        if (todayIds.isNotEmpty()) {
            val v = RemoteViews(ctx.packageName, R.layout.widget_today)
            if (!n.loggedIn) {
                v.setTextViewText(R.id.total, "₹ —")
                v.setTextViewText(R.id.detail, "Open the app and log in")
                v.setOnClickPendingIntent(R.id.root, openApp(ctx, "sell", 6))
            } else {
                v.setTextViewText(R.id.label, if (n.manager) "Today" else "Your sales today")
                v.setTextViewText(R.id.total, if (n.hasData) inr(n.total) else "₹ —")
                v.setTextViewText(R.id.detail, if (n.hasData) salesWord(n.count) + (if (n.stale) " · offline" else "") else "Updating…")
                v.setOnClickPendingIntent(R.id.root, openApp(ctx, "sales", 7))
            }
            m.updateAppWidget(todayIds, v)
        }

        drawNewSale(ctx)
    }

    fun drawNewSale(ctx: Context) {
        val m = AppWidgetManager.getInstance(ctx)
        val ids = m.getAppWidgetIds(ComponentName(ctx, NewSaleWidget::class.java))
        if (ids.isEmpty()) return
        val v = RemoteViews(ctx.packageName, R.layout.widget_new_sale)
        v.setOnClickPendingIntent(R.id.btn_new_sale, openApp(ctx, "sell", 8))
        v.setOnClickPendingIntent(R.id.root, openApp(ctx, "sell", 8))
        m.updateAppWidget(ids, v)
    }
}
