<?php

defined('ABSPATH') || exit;

/**
 * The gateway's settings, as LifterLMS keeps them: WordPress options named
 * `llms_gateway_orboni_<field>`, saved by LifterLMS's own settings screen
 * (LifterLMS → Settings → Checkout → Crypto). Read here, never written.
 */
class Orboni_LLMS_Settings
{
    /** The gateway's id in LifterLMS: its settings section, its orders' `payment_gateway`. */
    public const ID = 'orboni';

    /** LifterLMS's prefix for a gateway's options. */
    public const OPTION_PREFIX = 'llms_gateway_orboni_';

    /**
     * The currencies Orboni takes a price in, and the decimals of each one's
     * smallest unit (ISO 4217): Orboni turns a price into dollars at the
     * rate of the day, the ECB's or the market's.
     */
    public const CURRENCIES = [
        'USD' => 2, 'EUR' => 2, 'GBP' => 2, 'CHF' => 2, 'PLN' => 2, 'CZK' => 2,
        'HUF' => 2, 'RON' => 2, 'DKK' => 2, 'SEK' => 2, 'NOK' => 2, 'ISK' => 0,
        'TRY' => 2, 'JPY' => 0, 'KRW' => 0, 'CNY' => 2, 'HKD' => 2, 'SGD' => 2,
        'INR' => 2, 'IDR' => 2, 'MYR' => 2, 'PHP' => 2, 'THB' => 2, 'ILS' => 2,
        'AUD' => 2, 'NZD' => 2, 'CAD' => 2, 'MXN' => 2, 'BRL' => 2, 'ZAR' => 2,
        'UAH' => 2, 'ARS' => 2, 'CLP' => 0, 'AED' => 2, 'SAR' => 2, 'TWD' => 2,
        'VND' => 0,
    ];

    /** Orboni's least payment is worth $1: checked here on a dollar site, by Orboni in any other. */
    public const MIN_CENTS = 100;

    public const SECRET_KEY = '/^sk_(live|test)_[A-Za-z0-9]+$/';

    public const WEBHOOK_SECRET = '/^whsec_[A-Za-z0-9]+$/';

    private static function option($field)
    {
        $value = get_option(self::OPTION_PREFIX.$field, '');

        return is_scalar($value) ? trim((string) $value, " \t\n\r\0\x0B") : '';
    }

    /** The saved secret key, or '' while it is missing or not one. */
    public static function secret_key()
    {
        $key = self::saved_secret_key();

        return preg_match(self::SECRET_KEY, $key) === 1 ? $key : '';
    }

    /** What was saved as the secret key, valid or not: the settings' notices name a wrong one. */
    public static function saved_secret_key()
    {
        return self::option('secret_key');
    }

    public static function webhook_secret()
    {
        $secret = self::saved_webhook_secret();

        return preg_match(self::WEBHOOK_SECRET, $secret) === 1 ? $secret : '';
    }

    public static function saved_webhook_secret()
    {
        return self::option('webhook_secret');
    }

    /** LifterLMS's own "Debug Log" box on the gateway's settings. */
    public static function debug()
    {
        return self::option('logging_enabled') === 'yes';
    }

    /** Ticked "Enable Crypto" on the gateway's settings. */
    public static function is_enabled()
    {
        return self::option('enabled') === 'yes';
    }

    public static function is_test_key()
    {
        return strpos(self::secret_key(), 'sk_test_') === 0;
    }

    /** The site's currency, as LifterLMS prices its access plans. */
    public static function currency()
    {
        return strtoupper((string) get_lifterlms_currency());
    }

    /** Ready to take a payment: a secret key, and a currency Orboni takes. Crypto is offered at checkout only then. */
    public static function is_configured()
    {
        return self::secret_key() !== '' && self::supports_currency(self::currency());
    }

    public static function supports_currency($currency)
    {
        return isset(self::CURRENCIES[strtoupper((string) $currency)]);
    }

    public static function decimals($currency)
    {
        return self::CURRENCIES[strtoupper((string) $currency)] ?? 2;
    }

    /** A price in the currency's smallest unit, as Orboni takes it: 1250 for 12.50 EUR, 1500 for 1500 JPY. */
    public static function minor($amount, $currency)
    {
        return (int) round(((float) $amount) * (10 ** self::decimals($currency)));
    }

    /** "12.50 EUR" for 1250 euro cents, "1500 JPY" for 1500 yen. */
    public static function format_minor($amount, $currency)
    {
        $decimals = self::decimals($currency);

        return number_format(((int) $amount) / (10 ** $decimals), $decimals, '.', '').' '.strtoupper((string) $currency);
    }

    /**
     * This site among others on the same Orboni project: in the idempotency
     * key and the session's metadata, so two sites' order 42 never meet.
     */
    public static function site_key()
    {
        return substr(hash('sha256', (string) home_url()), 0, 12);
    }

    /** The gateway's settings screen. */
    public static function settings_url()
    {
        return admin_url('admin.php?page=llms-settings&tab=checkout&section='.self::ID);
    }
}
