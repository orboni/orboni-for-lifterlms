<?php

defined('ABSPATH') || exit;

/**
 * LifterLMS's own log for the gateway (LifterLMS → Status → Logs, the file
 * starting "orboni"). Requests and webhooks are written while the gateway's
 * "Debug Log" box is ticked; errors always. Never given a key or a signing
 * secret. What happened to an order is in its notes too.
 */
class Orboni_LLMS_Log
{
    public static function info($message)
    {
        if (Orboni_LLMS_Settings::debug()) {
            self::write($message);
        }
    }

    public static function error($message)
    {
        // A merchant asking why crypto failed needs these whatever the setting.
        self::write($message);
    }

    private static function write($message)
    {
        if (function_exists('llms_log')) {
            llms_log((string) $message, Orboni_LLMS_Settings::ID);
        }
    }
}
