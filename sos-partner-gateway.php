<?php
/**
 * Plugin Name: SOS Partner Gateway
 * Description: Gateway partner con login firmato ECC, protezione pagine partner, log integrati, sblocco IP e configurazione centralizzata per WordPress/LatePoint.
 * Version: 1.0.0
 * Author: OpenAI
 */

if (!defined('ABSPATH')) exit;

define('SOS_PG_FILE', __FILE__);
define('SOS_PG_DIR', plugin_dir_path(__FILE__));
define('SOS_PG_TABLE_LOGS', 'sos_partner_gateway_logs');

require_once SOS_PG_DIR . 'includes/class-sos-pg-plugin.php';

SOS_PG_Plugin::instance();