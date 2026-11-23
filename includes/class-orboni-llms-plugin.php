<?php

defined('ABSPATH') || exit;

/**
 * The gateway in LifterLMS: registered among its payment gateways, the two
 * addresses Orboni's payment page sends the student back to, the line on the
 * student's order page, and the notices on the gateway's settings.
 */
class Orboni_LLMS_Plugin
{
    /** The query argument of the return and back addresses. */
    public const ACTION_ARG = 'orboni-lifterlms';

    public static function register()
    {
        add_filter('lifterlms_payment_gateways', [self::class, 'add_gateway']);
        add_action('template_redirect', [self::class, 'on_return'], 1);
        add_action('lifterlms_before_view_order_table', [self::class, 'order_page_line']);
        add_action('admin_notices', [self::class, 'settings_notices']);

        Orboni_LLMS_Orders::register();
        Orboni_LLMS_Webhook::register();
    }

    /** @param  list<string>  $gateways */
    public static function add_gateway($gateways)
    {
        $gateways[] = Orboni_LLMS_Gateway::class;

        return $gateways;
    }

    /** Where Orboni's payment page sends the student: `return` once paid, `cancel` by its back link. */
    public static function return_url($order_id, $action)
    {
        return add_query_arg([
            self::ACTION_ARG => $action,
            'order' => (int) $order_id,
            'key' => Orboni_LLMS_Orders::token($order_id),
        ], home_url('/'));
    }

    /**
     * The student back from the payment page. The session is read and
     * applied first, so the order is right even before the webhook comes, or
     * without one; then: paid → LifterLMS's own page after a purchase; on its
     * way → the order, saying so; still open → the same payment page; ran
     * out → the course's checkout, which takes the order up again. By the
     * page's back link the page is closed first, and an unpaid order goes
     * back to that checkout, to pay again or another way.
     */
    public static function on_return()
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- a link from Orboni's payment page; the order's own random key checks it.
        if (! isset($_GET[self::ACTION_ARG], $_GET['order'], $_GET['key'])) {
            return;
        }
        $action = sanitize_key(wp_unslash($_GET[self::ACTION_ARG]));
        $order_id = absint(wp_unslash($_GET['order']));
        $key = sanitize_text_field(wp_unslash($_GET['key']));
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        if (! in_array($action, ['return', 'cancel'], true)) {
            return;
        }

        nocache_headers();
        $order = Orboni_LLMS_Orders::get($order_id);
        $token = $order ? (string) Orboni_LLMS_Orders::meta($order_id, Orboni_LLMS_Orders::META_TOKEN) : '';
        if (! $order || $token === '' || ! hash_equals($token, $key)) {
            wp_safe_redirect(home_url('/'));
            exit;
        }

        $id = (string) Orboni_LLMS_Orders::meta($order_id, Orboni_LLMS_Orders::META_SESSION);
        $secret = Orboni_LLMS_Settings::secret_key();
        $session = null;
        if ($id !== '' && $secret !== '' && $order->get('status') === 'llms-pending') {
            $api = new Orboni_LLMS_Api($secret);
            $session = $action === 'cancel' ? $api->expire_session($id) : $api->get_session($id);
            // Closed already (it ran out, or was paid): read as it is.
            if ($action === 'cancel' && is_wp_error($session)) {
                $session = $api->get_session($id);
            }
            if (is_wp_error($session)) {
                Orboni_LLMS_Log::error(sprintf('Order %d: could not read session %s on the student\'s return: %s', $order_id, $id, $session->get_error_message()));
                $session = null;
            } else {
                Orboni_LLMS_Orders::apply_locked($order_id, $session);
                if ($action === 'cancel' && ($session['status'] ?? '') === 'expired' && ($session['payment_status'] ?? '') === 'unpaid') {
                    Orboni_LLMS_Orders::note($order_id, __('The student left the Orboni payment page by its back link; the page was closed.', 'orboni-for-lifterlms'));
                }
            }
            $order = Orboni_LLMS_Orders::get($order_id);
        }

        $status = (string) $order->get('status');
        if ($status === 'llms-completed') {
            $gateway = llms()->payment_gateways()->get_gateway_by_id(Orboni_LLMS_Settings::ID);
            if ($gateway) {
                $gateway->complete_transaction($order);
            }
        }

        if ($status !== 'llms-pending') {
            wp_safe_redirect($order->get_view_link(), 303);
            exit;
        }

        if (is_array($session) && self::waiting($session)) {
            llms_add_notice(self::on_its_way(), 'notice');
            wp_safe_redirect($order->get_view_link(), 303);
            exit;
        }

        if ($action === 'return' && Orboni_LLMS_Gateway::reusable($session)) {
            Orboni_LLMS_Gateway::redirect_to($session['url']);
        }

        llms_add_notice($action === 'cancel'
            ? __('The crypto payment was cancelled. Your order is kept: pay it here, with crypto or another way.', 'orboni-for-lifterlms')
            : __('The payment page closed before a payment arrived. Your order is kept: pay it again here.', 'orboni-for-lifterlms'), 'notice');
        wp_safe_redirect(self::checkout_url($order), 303);
        exit;
    }

    /**
     * Money arrived or on its way that does not complete the order by
     * itself yet: confirming, short of the amount, held, or paid against an
     * order that now costs more.
     *
     * @param  array<string, mixed>  $session
     */
    private static function waiting(array $session)
    {
        $payment = (string) ($session['payment_status'] ?? '');

        return $payment === 'paid' || in_array($payment, ['processing', 'partially_paid', 'held'], true);
    }

    private static function on_its_way()
    {
        return __('Your payment is on its way. You will be enrolled as soon as the network confirms the transfer; you can close this page.', 'orboni-for-lifterlms');
    }

    /**
     * The checkout for the order's plan: LifterLMS takes the student's
     * pending order up again there. The order's page if the plan is gone.
     *
     * @param  LLMS_Order  $order
     */
    public static function checkout_url($order)
    {
        $plan = llms_get_post((int) $order->get('plan_id'));
        $url = $plan instanceof LLMS_Access_Plan ? (string) $plan->get_checkout_url(false) : '';

        return $url !== '' && strpos($url, '#') !== 0 ? $url : $order->get_view_link();
    }

    /**
     * On the student's own order page, for a Pending crypto order: that the
     * payment is on its way, or a link to pay it.
     */
    public static function order_page_line()
    {
        global $wp;
        $order_id = isset($wp->query_vars['orders']) ? absint($wp->query_vars['orders']) : 0;
        $order = $order_id ? Orboni_LLMS_Orders::get($order_id) : null;
        if (! $order || $order->get('payment_gateway') !== Orboni_LLMS_Settings::ID || $order->get('status') !== 'llms-pending' || get_current_user_id() !== (int) $order->get('user_id')) {
            return;
        }

        $state = explode(':', (string) Orboni_LLMS_Orders::meta($order_id, Orboni_LLMS_Orders::META_STATE));
        $payment = $state[2] ?? '';
        if ($payment === 'paid' || in_array($payment, ['processing', 'partially_paid', 'held'], true)) {
            echo '<div class="llms-notice llms-info">'.esc_html(self::on_its_way()).'</div>';

            return;
        }

        echo '<div class="llms-notice llms-info">'.esc_html__('This order waits for its payment.', 'orboni-for-lifterlms')
            .' <a href="'.esc_url(self::checkout_url($order)).'">'.esc_html__('Pay with crypto', 'orboni-for-lifterlms').'</a></div>';
    }

    /** On the gateway's settings: what keeps crypto from the checkout, and test mode. */
    public static function settings_notices()
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- which settings screen is open, read only.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        $section = isset($_GET['section']) ? sanitize_key(wp_unslash($_GET['section'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        if ($page !== 'llms-settings' || $tab !== 'checkout' || $section !== Orboni_LLMS_Settings::ID || ! current_user_can('manage_lifterlms')) {
            return;
        }

        $notices = [];
        $currency = Orboni_LLMS_Settings::currency();
        if (! Orboni_LLMS_Settings::supports_currency($currency)) {
            $notices[] = ['warning', sprintf(
                /* translators: 1: the site's currency code, e.g. ARS, 2: the codes Orboni takes, comma-separated */
                __('Orboni does not take prices in %1$s, this site\'s currency: crypto is not offered at checkout. It takes %2$s.', 'orboni-for-lifterlms'),
                $currency,
                implode(', ', array_keys(Orboni_LLMS_Settings::CURRENCIES))
            )];
        }

        $key = Orboni_LLMS_Settings::saved_secret_key();
        if ($key === '') {
            $notices[] = ['warning', __('Crypto is offered at checkout once its secret key is saved here.', 'orboni-for-lifterlms')];
        } elseif (Orboni_LLMS_Settings::secret_key() === '') {
            $notices[] = ['error', __('The secret key is not one: it starts with sk_live_ or sk_test_ (a publishable key, pk_…, will not work). Crypto is not offered at checkout until it is.', 'orboni-for-lifterlms')];
        }
        $webhook = Orboni_LLMS_Settings::saved_webhook_secret();
        if ($webhook !== '' && Orboni_LLMS_Settings::webhook_secret() === '') {
            $notices[] = ['error', __('The webhook signing secret starts with whsec_: until it does, webhooks are refused, and orders are completed only when the student comes back or by a check a few minutes later.', 'orboni-for-lifterlms')];
        }
        if (Orboni_LLMS_Settings::is_test_key()) {
            $notices[] = ['info', __('Crypto is in test mode: a test key makes test payments only. Open the payment page while signed in to your Orboni dashboard to simulate one.', 'orboni-for-lifterlms')];
        }

        foreach ($notices as [$type, $text]) {
            echo '<div class="notice notice-'.esc_attr($type).'"><p>'.esc_html($text).'</p></div>';
        }
    }
}
