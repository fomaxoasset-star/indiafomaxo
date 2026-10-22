# FOMAXO India Store System — pos.fomaxo.in

The store app for FOMAXO staff in India, the same app as pos.fomaxo.com made for India. It runs on the Hostinger plan and opens in any phone, tablet or laptop browser. This `pos-app` branch deploys to **pos.fomaxo.in** on every push (Hostinger → pos.fomaxo.in → Git).

- **Sell:** tap products, add a customer, coupon or discount, take payment, give a receipt
- **Payments:**
  - **Cash** with change worked out
  - **UPI QR · Razorpay:** a UPI QR for the exact amount; the customer scans it with PhonePe, Google Pay, Paytm or any UPI app and the sale completes by itself. The receipt notes which app paid (PhonePe, Google Pay, Paytm…) and the Razorpay payment id.
  - **UPI to store:** payment straight to the store's own UPI ID (no Razorpay fee). With the UPI ID set in Settings the till shows a QR with the amount filled in; staff pick the app and press Payment received.
  - **Card machine**
- **Money:** ₹ with Indian digit grouping (₹1,30,849.00). **GST** 18% included once a GSTIN is set, shown as CGST 9% + SGST 9%; receipts become Tax invoices
- **Stock:** in stock / running low / out of stock, stock goes down with every sale, restock and stock-count tools, full history per product, several stores
- **Products from fomaxo.in:** every product and size (perfumes, car perfumes, personal care) comes in from `fomaxo.in/api/pos-products.php` with its price and photo, and stays in sync every 10 minutes (stock counts are never changed by this)
- **Customers:** Indian mobile numbers (+91); customers who ordered on fomaxo.in are recognised; FOMAXO Elite tiers in ₹
- **Coupons:** the store's own codes, and (Settings) the codes made on fomaxo.in/admin
- **Team, staff logins, activity log, offline sales** — as on pos.fomaxo.com

## Set up (one time)

1. Open **https://pos.fomaxo.in/install.php**.
2. Choose **Built-in database** (nothing to set up) or **MySQL database** (make one first in hPanel → Databases). Add the store address and phone. The settings are saved as `fomaxo-pos-in-config.php` next to `public_html`, never in GitHub.
3. Create your manager login. Products come in from fomaxo.in.
4. Log in → menu → **Settings · payments & GST**:
   - **Razorpay:** with no keys of its own the app uses the fomaxo.in shop's Razorpay keys (`fomaxo.in/fomaxo-private/razorpay-config.php`). Switch **Razorpay QR at the till** to **On**. Test keys (`rzp_test_…`) take no real money. If UPI QR codes are not enabled on the Razorpay account, the till shows a Razorpay payment link QR instead (UPI, cards, netbanking).
   - **Store UPI ID** (e.g. `fomaxo@ybl`) for UPI to store.
   - **GSTIN** when registered.
5. **Stock** → enter how many of each product the store has.
6. Put it on the store devices: iPhone/iPad Share → Add to Home Screen; Android ⋮ → Install app.

## Good to know
- Refunds: void the sale (Sales → open → Void sale, managers), then refund the money in the Razorpay Dashboard or from the store's UPI app.
- Offline: Cash, UPI to store and Card machine sales keep working without internet and upload later. The Razorpay QR needs internet.
- The India app keeps its own settings file name and database, so it never mixes with pos.fomaxo.com.
