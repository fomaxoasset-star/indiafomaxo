# FOMAXO POS for Android

The India till (https://pos.fomaxo.in) as an Android app, with home-screen widgets:

- **Today's sales** (4×2): today's total, number of sales, cash / UPI / card, New sale and Sales buttons. Tap the numbers to update.
- **Today's total** (2×2): total and number of sales.
- **New sale** (2×1): opens the till.

Managers see all stores; staff see their own sales (the same as the Sales screen).
The widgets update when you leave the app, after each sale, when tapped, and every 30 minutes.

The app loads the live till, so changes to the `pos-app` branch reach the app without a new APK.

GitHub Actions builds the APK on every push to this branch (Actions → Build Android app → artifact `fomaxo-pos-apk`).
The release APK is signed outside GitHub with the FOMAXO release key (kept privately, never in this repository).
