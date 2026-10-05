# indiafomaxo
## Product reviews

Reviews are stored by `api/reviews.php` (PHP + SQLite) in a `fomaxo-private` folder next to `public_html`, so deploys never touch them.

- Customers rate 1–5 stars, write a review, give a name or post anonymously, add up to 3 photos, and mark reviews helpful.
- **Verified Purchaser**: after a payment is received, open `fomaxo.in/#/owner`, record the order, and send the customer the review link it creates. Reviews written from that link carry the badge, once per product.
- The owner page needs an owner key: a `config.php` in `fomaxo-private` (or `api/private-config.php`) returning `['admin_key_hash' => password_hash('your key', PASSWORD_DEFAULT)]`. Add `'moderate' => true` to hold new reviews until you publish them.

## Checkout and payments

`fomaxo.in/#/checkout` takes delivery details and payment. The bag's **Checkout** button and every **Buy now** button lead there.

- **Online payment (UPI, cards, netbanking, wallets)** runs through Razorpay. `api/razorpay.php` prices the order on the server from `index.html`, creates the Razorpay order and checks Razorpay's signature before an order counts as paid.
- **Razorpay keys**: copy `api/razorpay-config.example.php` to `fomaxo-private/razorpay-config.php` (next to `public_html`) and paste the Key ID and Key Secret from the Razorpay Dashboard. Test keys (`rzp_test_…`) take no real money. Open `fomaxo.in/api/razorpay.php?status` to check the set-up.
- **Cash on delivery**: `STORE.checkout.cod` in `index.html` sets the minimum order and fee (`cod: null` turns it off). Orders go through `api/cod.php`.
- Every order is emailed to the store and the customer, saved in the shop database, and also copied to `fomaxo-private/orders/orders.csv`.

## Orders, stock and products: fomaxo.in/admin

`api/shop-db.php` keeps one database for orders, stock, products added on the admin page and admin settings.

- **Database**: a Hostinger MySQL database, when `fomaxo-private/db-config.php` returns `['db_name' => …, 'db_user' => …, 'db_pass' => …]` (a `db-config.php` placed in `public_html/api/data/` is moved there on first use). Without it the same tables live in `fomaxo-private/shop.sqlite`.
- **Order numbers**: cash on delivery and online orders share one sequence, FMX-IN-1001, FMX-IN-1002 … An online order gets its number when Razorpay confirms the payment, so unfinished payments leave no gaps.
- **Admin page**: `fomaxo.in/admin` lists every order with details, payment type and status (New, Paid, Delivered, Cancelled), and downloads them as an Excel file. To set or reset the password, create `public_html/api/data/admin-password.txt` in Hostinger File Manager with the password as its only line, then open the page; it saves a hash and deletes the file.
- **Stock**: set per product and size on the admin page. Each order takes its items off, a cancelled order puts them back, and the site shows "Only N left" (5 or fewer by default) and "Sold out". A size with no number is not counted. A stock number wins over `soldOut: true` in `index.html`.
- **Products**: the admin page can hide or show any product, change prices, and add new fragrances, car perfumes and personal care products with photos (kept in `fomaxo-private/product-images`). The site loads these changes from `api/live.php`, and checkout prices are still worked out on the server.
- **Edit details & photos**: any product, including those written in `index.html`, can have its name, type, label, short line, description, notes, tier, sizes, prices and photos changed on the admin page. The edits are saved in the shop database and applied over `index.html` (which stays unchanged), so a later edit to `index.html` does not undo them for that product.
- **Fresh start**: the first time this version runs it saves any existing orders to `fomaxo-private/orders-before-fresh-start-<date>.json`, clears them and restarts numbering at FMX-IN-1001. It never runs again.
- **Product seeding**: on first run every product in `index.html` is copied into the database with today's prices, so the shop and checkout read prices from the database and fall back to `index.html` if it is unavailable.
- **Orders summary and Members**: the Orders page shows COD and online order counts and amounts for the current filters (cancelled and test orders left out) and a row of states to filter by. Members lists repeat customers (5 or more orders by default, changeable on the page), grouped by mobile or email, with their order history and an Excel download.
- **Expenses and profit & loss**: costs (ads, delivery, packaging, rent …) are added on the Expenses page. Reports show monthly and yearly sales, discounts, payment fees (estimated at the % set in Settings, default 2% of card/UPI sales), cost of goods (from each size's "My cost"), expenses and profit or loss, with Excel downloads.
- **Analytics**: `api/track.php` records anonymous visits on our own server (no Google or ad trackers): a random visitor id in the browser, the traffic source, product views, add to bag, checkout, payment and purchase. Bots and the owner's own visits (a cookie set when signed in to the admin) are skipped. Names and mobiles typed at checkout without an order appear under "Left at checkout".
- **Password reset**: "Forgot password?" on the sign-in page emails a one-time link (valid 30 minutes) to the order notification email set in Settings.
