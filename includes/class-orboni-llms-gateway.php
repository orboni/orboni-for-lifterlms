<?php

defined('ABSPATH') || exit;

/**
 * "Crypto" among LifterLMS's payment gateways. LifterLMS's checkout makes a
 * pending order and hands it to `handle_pending_order()`, which sends the
 * student to Orboni's payment page for it (a session made, or the open one
 * reused). The order then follows the payment ({@see Orboni_LLMS_Orders}):
 * the webhook, the student's return and scheduled checks read it back.
 *
 * One-time payments only: LifterLMS does not offer it for a plan with
 * recurring payments, as it does not support `recurring_payments`.
 */
class Orboni_LLMS_Gateway extends LLMS_Payment_Gateway
{
    /**
     * Time a payment page must still have to be reused when the student
     * checks out again: less, and they get a new one. A page Orboni still
     * calls open may be past its time (held open while a network cannot be
     * read).
     */
    public const REUSE_MIN_SECONDS = 300;

    /** @var bool the settings filter is added once, however often LifterLMS builds the gateway */
    private static $hooked = false;

    public function __construct()
    {
        $this->id = Orboni_LLMS_Settings::ID;
        $this->admin_title = __('Crypto (Orboni)', 'orboni-for-lifterlms');
        $this->admin_description = __('USDT, USDC, BTC, ETH and other coins, paid straight to your own wallets through Orboni. One-time payments.', 'orboni-for-lifterlms');
        $this->title = __('Crypto', 'orboni-for-lifterlms');
        $this->description = __('Pay with USDT, USDC, BTC, ETH or another coin on the next page.', 'orboni-for-lifterlms');
        $this->icon = '<img class="orboni-llms-icon" src="'.esc_url(ORBONI_LLMS_URL.'assets/images/orboni.svg').'" alt="" width="24" height="24" style="vertical-align:middle">';
        $this->display_order = 5;
        $this->supports = [
            'checkout_fields' => false,
            'refunds' => false, // LifterLMS's manual refund is there for every gateway; the money is in the merchant's wallet.
            'single_payments' => true,
            'recurring_payments' => false,
            'test_mode' => false, // The key says it: sk_test_ makes test payments.
        ];

        if (! self::$hooked) {
            self::$hooked = true;
            add_filter('llms_get_gateway_settings_fields', [self::class, 'settings_fields'], 10, 2);
        }
    }

    /** Offered at checkout only once ticked, with a key, in a currency Orboni takes. */
    public function is_enabled()
    {
        return Orboni_LLMS_Settings::is_enabled() && Orboni_LLMS_Settings::is_configured();
    }

    /** Test or live, as the key is: LifterLMS keeps it on the order and its transactions. */
    public function get_api_mode()
    {
        return Orboni_LLMS_Settings::is_test_key() ? 'test' : 'live';
    }

    /** The payment page is Orboni's own. */
    public function is_external_payment_entry()
    {
        return true;
    }

    /**
     * The gateway's fields under LifterLMS → Settings → Checkout → Crypto,
     * after LifterLMS's own (enable, title, description, debug log).
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    public static function settings_fields($fields, $gateway_id)
    {
        if ($gateway_id !== Orboni_LLMS_Settings::ID) {
            return $fields;
        }

        $fields[] = [
            'id' => Orboni_LLMS_Settings::OPTION_PREFIX.'secret_key',
            'title' => __('Secret key', 'orboni-for-lifterlms'),
            'desc' => '<br>'.__('In the Orboni dashboard, Developers → API keys: sk_live_… or sk_test_…. A test key makes test payments only.', 'orboni-for-lifterlms'),
            'type' => 'password',
            'default' => '',
        ];
        $fields[] = [
            'id' => Orboni_LLMS_Settings::OPTION_PREFIX.'webhook_secret',
            'title' => __('Webhook signing secret', 'orboni-for-lifterlms'),
            'desc' => '<br>'.__('In the Orboni dashboard, Developers → Webhooks: add an endpoint at the address below, then paste its signing secret (whsec_…) here.', 'orboni-for-lifterlms'),
            'type' => 'password',
            'default' => '',
        ];
        $fields[] = [
            'type' => 'custom-html',
            'value' => '<p><strong>'.esc_html__('Webhook address', 'orboni-for-lifterlms').'</strong><br><code>'.esc_html(Orboni_LLMS_Webhook::url()).'</code></p>'
                .'<p>'.esc_html__('Orders are completed without it too, when the student comes back from the payment page and by checks a few minutes later; with it, at once.', 'orboni-for-lifterlms').'</p>',
        ];

        return $fields;
    }

    /**
     * Pay pressed with Crypto: the order goes to Orboni's payment page. A
     * refusal is a notice on the checkout, which LifterLMS shows again with
     * the order kept.
     *
     * @param  LLMS_Order  $order
     * @param  LLMS_Access_Plan  $plan
     * @param  LLMS_Student  $student
     * @param  LLMS_Coupon|false  $coupon
     */
    public function handle_pending_order($order, $plan, $student, $coupon = false)
    {
        // Nothing to pay (a coupon took it all): LifterLMS's own gateway for free orders.
        if ((float) $order->get_initial_price([], 'float') <= 0) {
            $manual = llms()->payment_gateways()->get_gateway_by_id('manual');
            if ($manual) {
                $manual->handle_pending_order($order, $plan, $student, $coupon);
            }

            return;
        }

        $currency = Orboni_LLMS_Settings::currency();
        if (! Orboni_LLMS_Settings::supports_currency($currency)) {
            return llms_add_notice(sprintf(
                /* translators: %s: the site's currency code, e.g. ARS */
                __('Crypto payments are not taken in %s.', 'orboni-for-lifterlms'),
                $currency
            ), 'error');
        }
        if (Orboni_LLMS_Settings::secret_key() === '') {
            return llms_add_notice(self::unavailable(), 'error');
        }

        $amount = Orboni_LLMS_Settings::minor((float) $order->get_initial_price([], 'float'), $currency);
        if ($currency === 'USD' && $amount < Orboni_LLMS_Settings::MIN_CENTS) {
            return llms_add_notice(__('This order is below the minimum for a crypto payment ($1).', 'orboni-for-lifterlms'), 'error');
        }

        $order_id = (int) $order->get('id');
        $api = new Orboni_LLMS_Api(Orboni_LLMS_Settings::secret_key());

        // Checked out again while a payment for the order is on its way, or paid
        // meanwhile on the page open before: the order follows that, never a second page.
        $existing = (string) Orboni_LLMS_Orders::meta($order_id, Orboni_LLMS_Orders::META_SESSION);
        $session = $existing !== '' ? $api->get_session($existing) : null;
        if (is_array($session) && self::money_moving($session)) {
            Orboni_LLMS_Orders::apply_locked($order_id, $session);
            $order = Orboni_LLMS_Orders::get($order_id);
            if ($order && $order->get('status') === 'llms-completed') {
                return $this->complete_transaction($order);
            }

            return llms_add_notice(__('A payment for this order has already arrived or is on its way. You will be enrolled as soon as it is final; check the order under My Orders.', 'orboni-for-lifterlms'), 'notice');
        }

        $url = self::session_url($order, $api, $amount, $currency, $session);
        if (is_wp_error($url)) {
            return llms_add_notice($url->get_error_message(), 'error');
        }

        self::redirect_to($url);
    }

    /**
     * Money arrived, or is on its way, on this session: a payment the order follows.
     *
     * @param  array<string, mixed>  $session
     */
    private static function money_moving(array $session)
    {
        $payment = (string) ($session['payment_status'] ?? '');

        return $payment === 'paid' || in_array($payment, ['processing', 'partially_paid', 'held'], true);
    }

    /**
     * The payment page for the order: the open session for its amount, or a
     * new one.
     *
     * @param  LLMS_Order  $order
     * @param  array<string, mixed>|WP_Error|null  $current  the order's current session, already read
     * @return string|WP_Error the page's address, or what to tell the student
     */
    private static function session_url($order, Orboni_LLMS_Api $api, $amount, $currency, $current)
    {
        $order_id = (int) $order->get('id');
        $priced = $amount.strtolower($currency);

        // Checked out again for the same amount: the session still open is reused,
        // unless it has run out or is about to (REUSE_MIN_SECONDS).
        if (self::reusable($current) && (string) Orboni_LLMS_Orders::meta($order_id, Orboni_LLMS_Orders::META_AMOUNT) === $priced) {
            Orboni_LLMS_Log::info(sprintf('Order %d: session %s reused.', $order_id, $current['id']));

            return $current['url'];
        }

        // The key is kept while a try fails, so a session Orboni made for a try
        // whose answer was lost is the one the next try gets.
        $attempt = (int) Orboni_LLMS_Orders::meta($order_id, Orboni_LLMS_Orders::META_ATTEMPT) + 1;
        $body = self::session_body($order, $amount, $currency);
        $session = $api->create_session($body, self::idempotency_key($order, $amount, $currency, $attempt));

        // That lost try was sent with other details (the student's language, a
        // changed title): Orboni refuses the key for them, and a new one is taken.
        if (is_wp_error($session) && self::error_type($session) === 'idempotency_error' && (int) (($session->get_error_data())['status'] ?? 0) === 400) {
            $attempt++;
            $session = $api->create_session($body, self::idempotency_key($order, $amount, $currency, $attempt));
        }

        if (is_wp_error($session)) {
            $type = self::error_type($session);
            Orboni_LLMS_Log::error(sprintf('Order %d: Orboni refused a session (%s): %s', $order_id, $type, $session->get_error_message()));
            Orboni_LLMS_Orders::note($order, sprintf(
                /* translators: %s: Orboni's error, e.g. "authentication_error: Invalid API key." */
                __('Orboni did not open a payment page: %s', 'orboni-for-lifterlms'),
                $type.': '.$session->get_error_message()
            ));

            // Below the least Orboni takes: under a network's minimum, or worth under $1 in another currency.
            $param = is_array($session->get_error_data()) ? (string) (($session->get_error_data())['param'] ?? '') : '';
            if ($type === 'amount_too_small' || ($type === 'invalid_request_error' && $param === 'amount')) {
                return new WP_Error('orboni_minimum', __('This order is below the minimum amount for a crypto payment.', 'orboni-for-lifterlms'));
            }

            return new WP_Error('orboni_unavailable', self::unavailable());
        }

        if (! is_string($session['id'] ?? null) || ! is_string($session['url'] ?? null)) {
            Orboni_LLMS_Log::error(sprintf('Order %d: Orboni answered without a session id or url.', $order_id));

            return new WP_Error('orboni_unavailable', self::unavailable());
        }

        $known = Orboni_LLMS_Orders::sessions($order_id);
        $known[] = $session['id'];
        $expires = (int) ($session['expires_at'] ?? 0) ?: time() + HOUR_IN_SECONDS;

        Orboni_LLMS_Orders::set_meta($order_id, Orboni_LLMS_Orders::META_SESSION, $session['id']);
        Orboni_LLMS_Orders::set_meta($order_id, Orboni_LLMS_Orders::META_SESSIONS, array_values(array_unique($known)));
        Orboni_LLMS_Orders::set_meta($order_id, Orboni_LLMS_Orders::META_AMOUNT, $priced);
        Orboni_LLMS_Orders::set_meta($order_id, Orboni_LLMS_Orders::META_ATTEMPT, $attempt);
        Orboni_LLMS_Orders::set_meta($order_id, Orboni_LLMS_Orders::META_EXPIRES, $expires);
        Orboni_LLMS_Orders::note($order, sprintf(
            /* translators: 1: Orboni checkout session id, 2: the amount, with its currency */
            __('Student sent to the Orboni payment page (session %1$s, %2$s).', 'orboni-for-lifterlms'),
            $session['id'],
            Orboni_LLMS_Settings::format_minor($amount, $currency)
        ));
        Orboni_LLMS_Orders::schedule($order_id, $expires);

        Orboni_LLMS_Log::info(sprintf('Order %d: session %s for %s.', $order_id, $session['id'], Orboni_LLMS_Settings::format_minor($amount, $currency)));

        return $session['url'];
    }

    /**
     * A session the student can still pay on: open, with its page, and more
     * than REUSE_MIN_SECONDS before it runs out.
     *
     * @param  mixed  $session
     */
    public static function reusable($session)
    {
        return is_array($session)
            && ($session['status'] ?? '') === 'open'
            && is_string($session['url'] ?? null)
            && (int) ($session['expires_at'] ?? 0) > time() + self::REUSE_MIN_SECONDS;
    }

    /** To Orboni's payment page (its host allowed for this one redirect). */
    public static function redirect_to($url)
    {
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        add_filter('allowed_redirect_hosts', static function ($hosts) use ($host) {
            $hosts[] = $host;

            return $hosts;
        });

        wp_safe_redirect($url, 303);
        exit;
    }

    /**
     * @param  LLMS_Order  $order
     * @return array<string, mixed>
     */
    private static function session_body($order, $amount, $currency)
    {
        $order_id = (int) $order->get('id');
        $user = get_userdata((int) $order->get('user_id'));
        $email = (string) $order->get('billing_email');
        if (! is_email($email) && $user) {
            $email = (string) $user->user_email;
        }
        $locale = $user ? get_user_locale($user) : determine_locale();

        $body = [
            'amount' => $amount,
            'currency' => strtolower($currency),
            'description' => self::fit(self::item($order), 250),
            'details' => self::fit(sprintf(
                /* translators: 1: order number, 2: site name */
                __('Order #%1$s at %2$s', 'orboni-for-lifterlms'),
                (string) $order_id,
                wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)
            ), 500),
            'client_reference_id' => (string) $order_id,
            'customer_email' => is_email($email) ? $email : null,
            'locale' => str_replace('_', '-', (string) $locale),
            'success_url' => Orboni_LLMS_Plugin::return_url($order_id, 'return'),
            'cancel_url' => Orboni_LLMS_Plugin::return_url($order_id, 'cancel'),
            'metadata' => [
                'lifterlms_order_id' => (string) $order_id,
                'lifterlms_site' => Orboni_LLMS_Settings::site_key(),
            ],
        ];

        return array_filter($body, static function ($value) {
            return $value !== null && $value !== '';
        });
    }

    /**
     * "Watercolour basics — Lifetime access": the course or membership and
     * its plan, what the payment page shows as the purchase.
     *
     * @param  LLMS_Order  $order
     */
    private static function item($order)
    {
        $clean = static function ($text) {
            return trim(wp_strip_all_tags(html_entity_decode((string) $text, ENT_QUOTES, 'UTF-8')), " \t\n\r\0\x0B");
        };
        $product = $clean($order->get('product_title'));
        $plan = $clean($order->get('plan_title'));

        if ($product === '') {
            return sprintf(
                /* translators: %s: order number */
                __('Order #%s', 'orboni-for-lifterlms'),
                (string) $order->get('id')
            );
        }

        return $plan !== '' && $plan !== $product ? $product.' — '.$plan : $product;
    }

    /** At most `$max` characters, cut at a word with an ellipsis. */
    private static function fit($text, $max)
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text), " \t\n\r\0\x0B");
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > $max / 2) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, ' ,.;:').'…';
    }

    /**
     * The site, the order, the amount and the try. The order's own random
     * key is in it too: a site restored from a backup numbers its orders
     * again, and Orboni keeps a key a day.
     *
     * @param  LLMS_Order  $order
     */
    private static function idempotency_key($order, $amount, $currency, $attempt)
    {
        $instance = substr(hash('sha256', (string) $order->get('order_key')), 0, 8);

        return sprintf('llms-%s-%d-%s-%d%s-%d', Orboni_LLMS_Settings::site_key(), (int) $order->get('id'), $instance, $amount, strtolower($currency), $attempt);
    }

    private static function error_type(WP_Error $error)
    {
        $data = $error->get_error_data();

        return is_array($data) ? (string) ($data['type'] ?? '') : '';
    }

    public static function unavailable()
    {
        return __('Crypto payment is not available right now. Please try again in a minute or choose another payment method.', 'orboni-for-lifterlms');
    }
}
