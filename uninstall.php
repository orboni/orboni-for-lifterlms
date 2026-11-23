<?php

// Deleted from the Plugins screen: the gateway's settings go (the secret key
// with them). Orders keep their notes, transactions and Orboni's session ids,
// which are the site's records.
defined('WP_UNINSTALL_PLUGIN') || exit;

foreach (['enabled', 'title', 'description', 'logging_enabled', 'display_order', 'secret_key', 'webhook_secret'] as $orboni_llms_field) {
    delete_option('llms_gateway_orboni_'.$orboni_llms_field);
}

wp_unschedule_hook('orboni_lifterlms_check_order');
