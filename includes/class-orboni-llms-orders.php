<?php

defined('ABSPATH') || exit;

/**
 * An Orboni checkout session, read back, onto its LifterLMS order. The one
 * place an order changes for a payment: the webhook, the student's return,
 * the page's back link and the scheduled check all come here with the
 * session as Orboni shows it now, so the order follows the payment whatever
 * arrives first, twice or out of order.
 *
 * - complete + paid → a succeeded transaction: LifterLMS completes the
 *   order, enrols the student and sends its receipt;
 * - on its way (processing), short of the amount (partially_paid) or held
 *   for the merchant's review (held) → the order stays Pending, with a note;
 * - expired with nothing paid → a note; the order stays Pending, and the
 *   student pays it again from the course's checkout, which takes it up;
 * - money that paid it left the chain (`payment_reversed`) → its transaction
 *   Failed, so LifterLMS fails the order and takes the enrolment away; paid
 *   in full again → the transaction Succeeded, and the order is completed
 *   again. Nothing else takes a paid order back.
 *
 * The notes are the order's, for the site's admins; the student hears only
 * LifterLMS's own emails.
 */
class Orboni_LLMS_Orders
{
    /** The session the order is paid on now. */
    public const META_SESSION = '_orboni_session_id';

    /** Every session made for the order: a student may still pay an older one. */
    public const META_SESSIONS = '_orboni_session_ids';

    /** The amount the current session asks, in the currency's smallest unit, and its currency. */
    public const META_AMOUNT = '_orboni_amount';

    public const META_ATTEMPT = '_orboni_attempt';

    public const META_EXPIRES = '_orboni_expires_at';

    /** What was last applied, "id:status:payment_status": the same state twice adds no second note. */
    public const META_STATE = '_orboni_state';

    /** The webhook events already taken, so a retry or a replay adds nothing twice. */
    public const META_EVENTS = '_orboni_events';

    /** The random key the return and back addresses carry. */
    public const META_TOKEN = '_orboni_token';

    public const EVENTS_KEPT = 30;

    public const REVERSED = 'checkout.session.payment_reversed';

    public const EXTRA = 'checkout.session.extra_received';

    /** The scheduled read-back (WP-Cron). */
    public const CHECK_HOOK = 'orboni_lifterlms_check_order';

    /** Minutes after the session is made, then after it expires, that it is read back. */
    private const CHECK_AFTER_CREATED = [10];

    private const CHECK_AFTER_EXPIRY = [2, 30, 65];

    /** Seconds a lock left by a request that died is waited out. */
    private const LOCK_SECONDS = 60;

    /** Orboni's network keys as people write them; a network added later reads as its key. */
    private const CHAIN_NAMES = [
        'tron' => 'TRON', 'ethereum' => 'Ethereum', 'bsc' => 'BNB Chain', 'solana' => 'Solana', 'ton' => 'TON',
        'polygon' => 'Polygon', 'base' => 'Base', 'arbitrum' => 'Arbitrum', 'optimism' => 'Optimism',
        'avalanche' => 'Avalanche', 'bitcoin' => 'Bitcoin', 'litecoin' => 'Litecoin', 'dogecoin' => 'Dogecoin',
        'bitcoincash' => 'Bitcoin Cash', 'xrp' => 'XRP Ledger', 'stellar' => 'Stellar',
    ];

    public static function register()
    {
        add_action(self::CHECK_HOOK, [self::class, 'check']);
    }

    /** The order, fresh from the database, or null. @return LLMS_Order|null */
    public static function get($order_id)
    {
        $order_id = (int) $order_id;
        if ($order_id <= 0 || get_post_type($order_id) !== 'llms_order') {
            return null;
        }
        clean_post_cache($order_id);

        return new LLMS_Order($order_id);
    }

    /** @return mixed the meta's value, or '' */
    public static function meta($order_id, $key)
    {
        return get_post_meta((int) $order_id, $key, true);
    }

    public static function set_meta($order_id, $key, $value)
    {
        update_post_meta((int) $order_id, $key, $value);
    }

    /** @return list<string> */
    public static function sessions($order_id)
    {
        $known = self::meta($order_id, self::META_SESSIONS);

        return is_array($known) ? array_values(array_filter($known, 'is_string')) : [];
    }

    /** The order's random key for its return and back addresses, made the first time it is asked. */
    public static function token($order_id)
    {
        $token = (string) self::meta($order_id, self::META_TOKEN);
        if ($token === '') {
            $token = bin2hex(random_bytes(16));
            self::set_meta($order_id, self::META_TOKEN, $token);
        }

        return $token;
    }

    /** A note on the order, which LifterLMS shows its admins. @param  LLMS_Order|int  $order */
    public static function note($order, $message)
    {
        $order = $order instanceof LLMS_Order ? $order : self::get($order);
        if ($order) {
            $order->add_note($message);
        }
    }

    /**
     * {@see apply()} with the order loaded fresh under a lock: the webhook
     * and the student's return can land in the same second.
     *
     * @param  array<string, mixed>  $session
     * @return bool|null true applied, false an event already taken, null the order is busy
     */
    public static function apply_locked($order_id, array $session, $event = '', $event_id = '')
    {
        $order_id = (int) $order_id;
        $lock = 'orboni_lifterlms_lock_'.$order_id;
        // add_option() inserts or fails: one request at a time holds it.
        if (! add_option($lock, time(), '', false)) {
            if ((int) get_option($lock) > time() - self::LOCK_SECONDS) {
                return null;
            }
            update_option($lock, time(), false);
        }

        try {
            $order = self::get($order_id);
            if (! $order) {
                return false;
            }

            if ($event_id !== '') {
                $taken = self::meta($order_id, self::META_EVENTS);
                $taken = is_array($taken) ? $taken : [];
                if (in_array($event_id, $taken, true)) {
                    return false;
                }
                $taken[] = $event_id;
                self::set_meta($order_id, self::META_EVENTS, array_slice($taken, -self::EVENTS_KEPT));
            }

            self::apply($order, $session, $event);

            return true;
        } finally {
            delete_option($lock);
        }
    }

    /**
     * Read-backs for a new session: the order is right even when no webhook
     * is set up and the student never comes back.
     */
    public static function schedule($order_id, $expires_at)
    {
        $order_id = (int) $order_id;
        $times = [];
        foreach (self::CHECK_AFTER_CREATED as $minutes) {
            $times[] = time() + $minutes * MINUTE_IN_SECONDS;
        }
        foreach (self::CHECK_AFTER_EXPIRY as $minutes) {
            $times[] = (int) $expires_at + $minutes * MINUTE_IN_SECONDS;
        }

        wp_clear_scheduled_hook(self::CHECK_HOOK, [$order_id]);
        foreach (array_unique($times) as $time) {
            wp_schedule_single_event($time, self::CHECK_HOOK, [$order_id]);
        }
    }

    /** A scheduled read-back. */
    public static function check($order_id)
    {
        $order = self::get((int) $order_id);
        $secret = Orboni_LLMS_Settings::secret_key();
        if (! $order || $secret === '' || $order->get('status') === 'llms-completed') {
            return;
        }

        $id = (string) self::meta($order_id, self::META_SESSION);
        if ($id === '') {
            return;
        }

        $session = (new Orboni_LLMS_Api($secret))->get_session($id);
        if (is_wp_error($session)) {
            Orboni_LLMS_Log::error(sprintf('Order %d: scheduled check could not read session %s: %s', $order_id, $id, $session->get_error_message()));

            return;
        }

        self::apply_locked($order_id, $session);
    }

    /**
     * This order's session: made for it by this site. The webhook checks the
     * same before reading anything back, and a session read back is checked
     * again here.
     *
     * @param  LLMS_Order  $order
     * @param  array<string, mixed>  $session
     */
    public static function belongs($order, array $session)
    {
        $id = (string) ($session['id'] ?? '');
        $order_id = (int) $order->get('id');

        return $id !== ''
            && in_array($id, self::sessions($order_id), true)
            && (string) ($session['client_reference_id'] ?? '') === (string) $order_id
            && (string) (((array) ($session['metadata'] ?? []))['lifterlms_site'] ?? '') === Orboni_LLMS_Settings::site_key();
    }

    /**
     * The order's transaction that an Orboni session paid, the newest one,
     * whatever became of it since (failed, refunded).
     *
     * @param  LLMS_Order  $order
     * @return LLMS_Transaction|null
     */
    public static function transaction($order)
    {
        $sessions = self::sessions((int) $order->get('id'));
        $found = $order->get_transactions(['per_page' => 50, 'status' => 'any', 'no_found_rows' => true]);
        foreach ((array) ($found['transactions'] ?? []) as $txn) {
            if ($txn instanceof LLMS_Transaction && $txn->get('payment_gateway') === Orboni_LLMS_Settings::ID && in_array((string) $txn->get('gateway_transaction_id'), $sessions, true)) {
                return $txn;
            }
        }

        return null;
    }

    /**
     * @param  LLMS_Order  $order
     * @param  array<string, mixed>  $session  Orboni's checkout session object
     * @param  string  $event  the webhook's type, when a webhook brought it
     */
    private static function apply($order, array $session, $event = '')
    {
        $order_id = (int) $order->get('id');
        $id = (string) ($session['id'] ?? '');
        if (! self::belongs($order, $session)) {
            Orboni_LLMS_Log::error(sprintf('Order %d: session %s is not this order\'s; left alone.', $order_id, $id));

            return;
        }

        $status = (string) ($session['status'] ?? '');
        $payment = (string) ($session['payment_status'] ?? '');
        $state = $id.':'.$status.':'.$payment;
        $paid_here = $status === 'complete' && $payment === 'paid';

        $txn = self::transaction($order);
        if ($txn) {
            self::after_paid($order, $txn, $session, $event, $paid_here, $state);

            return;
        }

        if ((string) self::meta($order_id, self::META_STATE) === $state) {
            return;
        }
        self::set_meta($order_id, self::META_STATE, $state);

        $order_status = (string) $order->get('status');
        if ($order_status !== 'llms-pending') {
            // Completed another way, cancelled or failed in the admin, or trashed: not
            // changed by itself. Money that arrives anyway is said.
            if ($paid_here || in_array($payment, ['processing', 'partially_paid', 'held'], true)) {
                self::note($order, sprintf(
                    /* translators: 1: Orboni session id, 2: the amount received, with its currency, 3: the order's status, e.g. "Cancelled" */
                    __('A payment arrived on Orboni session %1$s (%2$s received) for this order, which is %3$s. It was left as it is: send the money back from your wallet, or record the payment by hand.', 'orboni-for-lifterlms'),
                    $id,
                    self::money($session, 'amount_received'),
                    function_exists('llms_get_order_status_name') ? llms_get_order_status_name($order_status) : $order_status
                ));
                Orboni_LLMS_Log::error(sprintf('Order %d: paid on session %s while %s.', $order_id, $id, $order_status));
            }

            return;
        }

        if ($paid_here) {
            self::complete($order, $session);

            return;
        }

        switch ($payment) {
            case 'processing':
                self::note($order, __('A payment is on the chain and waiting for confirmations. The order will be completed by itself once it is final.', 'orboni-for-lifterlms'));

                return;

            case 'partially_paid':
                self::note($order, $status === 'expired'
                    ? sprintf(
                        /* translators: 1: the amount received, 2: the amount due, each with its currency, e.g. "25.00 EUR" */
                        __('The payment page closed partly paid: %1$s of %2$s arrived in your wallet. Settle it with the student (send the rest back or ask for the difference); Orboni will not complete this order.', 'orboni-for-lifterlms'),
                        self::money($session, 'amount_received'),
                        self::money($session, 'amount_due')
                    )
                    : sprintf(
                        /* translators: 1: the amount received, 2: the amount due, each with its currency, e.g. "25.00 EUR" */
                        __('Partly paid so far: %1$s of %2$s. The payment page asks the student for the rest.', 'orboni-for-lifterlms'),
                        self::money($session, 'amount_received'),
                        self::money($session, 'amount_due')
                    ));

                return;

            case 'held':
                self::note($order, sprintf(
                    /* translators: %s: why Orboni held the payment, e.g. "late" or "overpaid" */
                    __('The money is in your wallet but held for your review in the Orboni dashboard (reason: %s). Accept it there and this order is completed.', 'orboni-for-lifterlms'),
                    (string) ($session['review_reason'] ?? '—')
                ));

                return;
        }

        if ($status === 'expired' && $payment === 'unpaid' && (string) self::meta($order_id, self::META_SESSION) === $id) {
            self::note($order, __('The Orboni payment page expired with nothing paid. The order stays Pending: the student can pay it again from the course\'s checkout.', 'orboni-for-lifterlms'));
        }
    }

    /**
     * Paid in full: a succeeded transaction, as LifterLMS's own gateways
     * record one. LifterLMS then completes the order, enrols the student and
     * sends its receipt.
     *
     * @param  LLMS_Order  $order
     * @param  array<string, mixed>  $session
     */
    private static function complete($order, array $session)
    {
        $currency = strtoupper((string) $order->get('currency'));
        $total = (float) $order->get_initial_price([], 'float');
        $asked = (int) ($session['amount_total'] ?? 0);
        $asked_in = strtoupper((string) ($session['currency'] ?? ''));
        $due = Orboni_LLMS_Settings::minor($total, $currency);

        // The order costs more than this page asked (checked out again with another plan or coupon, the currency changed).
        if ($asked_in !== $currency || $asked < $due) {
            self::note($order, sprintf(
                /* translators: 1: the amount the session asked, with its currency, 2: the order's total, with its currency */
                __('Orboni completed a payment of %1$s, but this order totals %2$s. Check it before completing the order by hand.', 'orboni-for-lifterlms'),
                Orboni_LLMS_Settings::format_minor($asked, $asked_in),
                Orboni_LLMS_Settings::format_minor($due, $currency)
            ));
            Orboni_LLMS_Log::error(sprintf('Order %d: session %s paid %d %s, the order totals %d %s.', $order->get('id'), $session['id'], $asked, $asked_in, $due, $currency));

            return;
        }

        $payment = is_array($session['payment'] ?? null) ? $session['payment'] : [];
        self::note($order, self::paid_note($session));
        $order->record_transaction([
            'amount' => $total,
            'source_description' => isset($payment['coin']) ? sprintf(
                /* translators: 1: coin, e.g. USDT, 2: network, e.g. TRON */
                __('%1$s on %2$s', 'orboni-for-lifterlms'),
                (string) $payment['coin'],
                self::chain($payment)
            ) : __('Crypto', 'orboni-for-lifterlms'),
            'transaction_id' => (string) $session['id'],
            'status' => 'llms-txn-succeeded',
            'payment_gateway' => Orboni_LLMS_Settings::ID,
            'payment_type' => 'single',
        ]);
        Orboni_LLMS_Log::info(sprintf('Order %d: paid by session %s.', $order->get('id'), $session['id']));
    }

    /**
     * @param  LLMS_Order  $order
     * @param  LLMS_Transaction  $txn  the order's transaction an Orboni session paid
     * @param  array<string, mixed>  $session
     */
    private static function after_paid($order, $txn, array $session, $event, $paid_here, $state)
    {
        $order_id = (int) $order->get('id');
        $id = (string) $session['id'];

        if ((string) $txn->get('gateway_transaction_id') === $id) {
            $txn_status = (string) $txn->get('status');
            if ($event === self::REVERSED && ! $paid_here && $txn_status === 'llms-txn-succeeded') {
                // Recorded, so the session paid in full again completes the order again.
                self::set_meta($order_id, self::META_STATE, $state);
                self::note($order, sprintf(
                    /* translators: %s: the amount still counted as received, with its currency */
                    __('Orboni reports that money which paid this order left the chain (a reorganisation or a double spend); %s still counts as received. The transaction is marked Failed and the enrolment ended until the Orboni dashboard shows it paid again.', 'orboni-for-lifterlms'),
                    self::money($session, 'amount_received')
                ));
                $txn->set('status', 'llms-txn-failed');
                Orboni_LLMS_Log::error(sprintf('Order %d: payment reversed on session %s.', $order_id, $id));
            } elseif ($paid_here && $txn_status === 'llms-txn-failed' && (string) self::meta($order_id, self::META_STATE) !== $state) {
                self::set_meta($order_id, self::META_STATE, $state);
                self::note($order, sprintf(
                    /* translators: %s: Orboni session id */
                    __('Orboni shows session %s paid in full again: the transaction is Succeeded and the order completed again.', 'orboni-for-lifterlms'),
                    $id
                ));
                $txn->set('status', 'llms-txn-succeeded');
            } elseif ($event === self::EXTRA) {
                self::note($order, sprintf(
                    /* translators: %s: the amount received in all, with its currency */
                    __('More money arrived after this order was paid: %s received in all. Orboni does not complete an order twice; send the difference back from your wallet if the student asks.', 'orboni-for-lifterlms'),
                    self::money($session, 'amount_received')
                ));
            }

            return;
        }

        // Another of the order's sessions paid too.
        if ($paid_here && (string) self::meta($order_id, self::META_STATE) !== $state) {
            self::set_meta($order_id, self::META_STATE, $state);
            self::note($order, sprintf(
                /* translators: 1: Orboni session id, 2: the amount received, with its currency */
                __('This order was already paid, and another payment page (%1$s) was paid as well: %2$s. Send it back to the student from your wallet.', 'orboni-for-lifterlms'),
                $id,
                self::money($session, 'amount_received')
            ));
            Orboni_LLMS_Log::error(sprintf('Order %d: paid twice, again by session %s.', $order_id, $id));
        }
    }

    /** "Paid with 25.00 USDT on TRON (25.00 USD) through Orboni, session cs_live_…." @param  array<string, mixed>  $session */
    private static function paid_note(array $session)
    {
        $payment = is_array($session['payment'] ?? null) ? $session['payment'] : [];
        $test = empty($session['livemode']) ? ' '.__('(test payment)', 'orboni-for-lifterlms') : '';
        $late = ! empty($session['paid_late']) ? ' '.__('It arrived after the payment page expired.', 'orboni-for-lifterlms') : '';

        if (isset($payment['coin'], $payment['amount'])) {
            return sprintf(
                /* translators: 1: coin amount, 2: coin, 3: network, 4: the amount it counted for, with its currency, 5: session id */
                __('Paid with %1$s %2$s on %3$s (%4$s) through Orboni, session %5$s.', 'orboni-for-lifterlms'),
                (string) $payment['amount'],
                (string) $payment['coin'],
                self::chain($payment),
                self::money($session, 'amount_received'),
                (string) $session['id']
            ).$test.$late;
        }

        return sprintf(
            /* translators: 1: the amount, with its currency, 2: session id */
            __('Paid %1$s through Orboni, session %2$s.', 'orboni-for-lifterlms'),
            self::money($session, 'amount_received'),
            (string) $session['id']
        ).$test.$late;
    }

    /** "TRON", "BNB Chain". @param  array<string, mixed>  $payment */
    private static function chain(array $payment)
    {
        $chain = (string) ($payment['chain'] ?? '');

        return self::CHAIN_NAMES[$chain] ?? ucfirst($chain);
    }

    /**
     * One of the session's decimal amounts with its currency: "25.00 EUR", "1500 JPY".
     *
     * @param  array<string, mixed>  $session
     */
    private static function money(array $session, $field)
    {
        $currency = strtoupper((string) ($session['currency'] ?? 'usd'));

        return number_format((float) ($session[$field] ?? 0), Orboni_LLMS_Settings::decimals($currency), '.', '').' '.$currency;
    }
}
