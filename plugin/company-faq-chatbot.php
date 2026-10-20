<?php
/**
 * Plugin Name: Company FAQ Chatbot
 * Description: Intelligenter FAQ-Chatbot fuer WordPress mit OpenAI, Website-Wissensbasis, manuellen FAQ-Eintraegen und automatischer Einbindung unten rechts.
 * Version: 1.3.97
 * Author: Codex
 * Text Domain: company-faq-chatbot
 */

if (! defined('ABSPATH')) {
    exit;
}

define('WPCA_VERSION', '1.3.97');
define('WPCA_PLUGIN_FILE', __FILE__);
define('WPCA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPCA_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once WPCA_PLUGIN_DIR . 'includes/class-wpca-plugin.php';

WPCA_Plugin::instance();
