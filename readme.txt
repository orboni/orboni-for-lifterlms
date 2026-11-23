=== Orboni for LifterLMS ===
Contributors: orboni
Tags: crypto, cryptocurrency, usdt, bitcoin, lifterlms
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: lifterlms
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell courses and memberships for USDT, USDC, BTC, ETH and more, paid straight to your own wallets. Orboni never holds your money.

== Description ==

Orboni adds "Crypto" to LifterLMS's own checkout. The student picks it, presses the checkout button, picks a coin on Orboni's payment page and pays from any wallet or exchange. The money goes straight to the wallet addresses you set in your Orboni dashboard; Orboni never receives, holds or forwards it. When the payment is final on the chain, LifterLMS records the transaction, completes the order and enrols the student by itself.

It is a payment gateway for LifterLMS (LifterLMS → Settings → Checkout → Crypto), beside the Manual gateway and any other you use, for courses and memberships sold with one-time access plans.

**What students can pay with**

* **USDT** on TRON, Ethereum, BNB Chain, Solana, TON, Polygon, Arbitrum, Optimism and Avalanche
* **USDC** on Ethereum, Base, Solana, Polygon, Arbitrum, Optimism, Avalanche, BNB Chain and Stellar
* **DAI** and **PYUSD**
* **BTC**, **ETH**, **BNB**, **SOL**, **TRX**, **POL**, **AVAX**, **LTC**, **DOGE**, **BCH**, **XRP**, **XLM** and TON's **GRAM**

The payment page offers only the coins you have set a wallet for in the Orboni dashboard.

**How it works**

* The student picks "Crypto" at checkout and is sent to Orboni's payment page, in their language, with the course or membership, its plan and the order's total in your site's currency.
* The page shows the exact amount in the chosen coin, an address and a QR code, and follows the transfer on the chain.
* Orboni tells your site with a signed webhook, and the order moves on: Pending while the payment confirms, then a succeeded transaction — LifterLMS completes the order, enrols the student and sends its receipt.
* Without a webhook the plugin still reads the payment back when the student returns to your site, and checks again by itself a few minutes later.

**Built for real orders**

* Underpaid payments, and payments held for your review, keep the order Pending with a note, so nobody is enrolled by mistake. A reversed payment marks its transaction Failed, which ends the enrolment until it is paid again. An overpaid order is completed, and a note says how much arrived.
* A student who checks out again gets the same payment page while it has more than five minutes left. An order whose page ran out stays Pending, and the student pays it again from the course's checkout, which takes the same order up.
* The payment page's back link closes the page and brings the student back to the course's checkout, to pay again or another way.
* The student's order page says when a payment is on its way, or links to pay an order that waits for one.
* Test keys let you try the whole flow before you take real payments.

Your site can sell in US dollars, euros, pounds, Swiss francs, złoty, Czech koruna, forints, lei, Danish, Swedish and Norwegian kroner, Icelandic krónur, lira, yen, won, yuan, Hong Kong and Singapore dollars, rupees, rupiah, ringgit, pesos (Philippine and Mexican), baht, shekels, Australian, New Zealand and Canadian dollars, reais, rand, hryvnias, Argentine and Chilean pesos, dirhams, riyals, Taiwan dollars and đồng. Orboni turns the price into dollars at the rate of the day (the European Central Bank's, or the market's for a currency the ECB does not publish), and the student pays that in the coin they pick. A payment is worth at least $1.

== Installation ==

1. Install and activate the plugin (LifterLMS 7.0 or later must be active).
2. Sign up at [orboni.com](https://orboni.com), add a project and set the wallet addresses you want to be paid to.
3. In the Orboni dashboard, under Developers → API keys, copy the secret key. Use a test key (`sk_test_…`) first.
4. In WordPress, go to LifterLMS → Settings → Checkout → Crypto, tick "Enable / Disable" and paste the secret key.
5. In the Orboni dashboard, under Developers → Webhooks, add an endpoint at the webhook address the gateway's settings show (`https://your-site.example/wp-json/orboni-lifterlms/v1/webhook`) and paste its signing secret (`whsec_…`) into the gateway's settings. Save.
6. Buy a course as a student. Once it works, switch to your live key.

== Frequently Asked Questions ==

= Does Orboni hold my money? =

No. Students pay to the wallet addresses you set in your dashboard, and the money is yours from the moment it is on the chain. Orboni watches the chain and tells your site; it never has your keys or your coins.

= Can students pay for a plan with recurring payments? =

No. Crypto is sent from the student's wallet each time, so nothing can be charged later by itself: LifterLMS offers the gateway for one-time access plans only, and leaves it out at a recurring plan's checkout.

= My site is not in US dollars. =

Orboni takes 37 currencies, listed above: the payment page shows the order's total in your site's currency, and the student pays its worth at the rate of the day. With a currency outside the list crypto is not offered at checkout, and the gateway's settings say so.

= What happens if a student sends too little or too much? =

Too little: the payment page asks for the rest, and the order stays Pending with a note. If the page expires part paid, it stays Pending for you to settle with the student. Too much: the order is completed, and a note says how much arrived in all; send the difference back from your own wallet. Only a payment far above the total (over twice it, and over $50 more) to a Bitcoin-style address is held for your review in the Orboni dashboard first, since it may be someone else's payment; the order stays Pending until you accept it there.

= How do refunds work? =

The money is in your wallet, so you send a refund from there and record it in the Orboni dashboard. Then record the refund in LifterLMS (the order → its transaction → Refund, "Manual Refund"), which marks the order Refunded and ends the enrolment.

= What about free orders? =

A coupon that takes the whole price makes a free order, which LifterLMS's own Manual gateway completes: no payment page.

= Where are the logs? =

What happened to each order is in its notes (LifterLMS → Orders → the order). Requests and webhooks are written to LifterLMS's log (LifterLMS → Status → Logs, the file starting "orboni") while the gateway's "Debug Log" box is ticked; errors are written either way. Keys and signing secrets are never logged.

= Can I use one Orboni project for several sites? =

Yes. Each site adds its own webhook endpoint, and each one acts only on its own orders.

== External services ==

This plugin connects to Orboni (https://orboni.com), the payment service it exists for. Without it the plugin does nothing.

* **When a student checks out with "Crypto"**, the plugin sends Orboni the order's total and currency, the course's or membership's title and its plan's, the order's number, your site's name, the student's email address and language, the addresses to return the student to, the order's id and a short hash of your site's address (to tell your sites apart). Orboni uses them to draw the payment page and to send the student a receipt.
* **When the student returns, when a webhook arrives, and in a few scheduled checks after the payment page is opened and after it expires**, the plugin asks Orboni for the payment's status by its session id. When the student leaves the payment page by its back link, the plugin asks Orboni to close that page.
* **Orboni sends your site webhooks** about the payment to `/wp-json/orboni-lifterlms/v1/webhook`, signed with your endpoint's secret.
* The student pays on Orboni's own payment page at `https://orboni.com/pay/…`.

Every request carries your Orboni secret key. Orboni's [Terms of Service](https://orboni.com/page/terms-of-service) and [Privacy Policy](https://orboni.com/page/privacy-policy) apply.

== Screenshots ==

1. "Crypto" at LifterLMS's checkout.
2. Orboni's payment page: the coin, the amount, the address and its QR code.
3. The gateway's settings in LifterLMS → Settings → Checkout → Crypto.
4. An order paid with crypto, with its transaction and notes.

== Changelog ==

= 1.0.0 =
* First release: LifterLMS's own checkout for one-time access plans, prices in 37 currencies, read-backs without a webhook, paying a pending order again from the course's checkout.
