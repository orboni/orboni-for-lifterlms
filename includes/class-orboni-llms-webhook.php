<?php

defined('ABSPATH') || exit;

/**
 * `POST /wp-json/orboni-lifterlms/v1/webhook`: Orboni's events for this site.
 * The signature is checked, the event matched to its order, and the session
 * read back from Orboni before anything changes — the order follows the
 * session as it is now, never an old event's copy of it.
 *
 * Answers: 200 for an event taken or one that is not this site's (Orboni
 * sends every event of a project to each of its endpoints, and a non-2xx
 * would be sent again for days); 401 for a bad signature; 400 for a body that
 * is no event; 503 while the site cannot decide yet (no signing secret saved,
 * Orboni not answering, the order busy) — Orboni retries it later.
 */
class Orboni_LLMS_Webhook
{
    /** Apart from the other Orboni plugins' routes: a site may run several. */
    public const ROUTE_NAMESPACE = 'orboni-lifterlms/v1';

    public const ROUTE = '/webhook';

    public static function register()
    {
        add_action('rest_api_init', static function () {
            register_rest_route(self::ROUTE_NAMESPACE, self::ROUTE, [
                'methods' => 'POST',
                'callback' => [self::class, 'handle'],
                // Orboni's server, not a signed-in user: the signature is the check.
                'permission_callback' => '__return_true',
            ]);
        });
    }

    /** The address to add in the Orboni dashboard; with plain permalinks, its `?rest_route=` form. */
    public static function url()
    {
        return rest_url(self::ROUTE_NAMESPACE.self::ROUTE);
    }

    public static function handle(WP_REST_Request $request)
    {
        $secret = Orboni_LLMS_Settings::webhook_secret();
        $key = Orboni_LLMS_Settings::secret_key();
        if ($secret === '' || $key === '') {
            return self::answer(503, 'not_configured');
        }

        $body = (string) $request->get_body();
        if (! Orboni_LLMS_Signature::verify($secret, $body, $request->get_header('webhook_signature'))) {
            Orboni_LLMS_Log::error('Webhook refused: the signature does not match this site\'s signing secret.');

            return self::answer(401, 'bad_signature');
        }

        $event = json_decode($body, true);
        if (! is_array($event) || ! is_string($event['type'] ?? null) || ! is_array($event['data']['object'] ?? null)) {
            return self::answer(400, 'bad_event');
        }

        $type = $event['type'];
        $object = $event['data']['object'];
        if (strpos($type, 'checkout.session.') !== 0) {
            return self::answer(200, 'ignored');
        }

        $id = (string) ($object['id'] ?? '');
        $reference = (string) ($object['client_reference_id'] ?? '');
        $site = (string) (((array) ($object['metadata'] ?? []))['lifterlms_site'] ?? '');
        if (preg_match(Orboni_LLMS_Api::SESSION_ID, $id) !== 1 || ! ctype_digit($reference) || $site !== Orboni_LLMS_Settings::site_key()) {
            // Another site's, or a payment link's: not ours to act on.
            return self::answer(200, 'ignored');
        }

        $order = Orboni_LLMS_Orders::get((int) $reference);
        if (! $order || ! in_array($id, Orboni_LLMS_Orders::sessions($reference), true)) {
            Orboni_LLMS_Log::info(sprintf('Webhook %s for session %s: no order of this site has it.', $type, $id));

            return self::answer(200, 'ignored');
        }

        $session = (new Orboni_LLMS_Api($key))->get_session($id);
        if (is_wp_error($session)) {
            Orboni_LLMS_Log::error(sprintf('Webhook %s, order %d: could not read session %s back: %s', $type, $reference, $id, $session->get_error_message()));

            return self::answer(503, 'retry_later');
        }

        $taken = Orboni_LLMS_Orders::apply_locked((int) $reference, $session, $type, (string) ($event['id'] ?? ''));
        if ($taken === null) {
            return self::answer(503, 'busy');
        }

        Orboni_LLMS_Log::info(sprintf('Webhook %s, order %d: %s.', $type, $reference, $taken ? 'applied' : 'already taken'));

        return self::answer(200, $taken ? 'received' : 'duplicate');
    }

    private static function answer($code, $result)
    {
        return new WP_REST_Response(['result' => $result], $code);
    }
}
