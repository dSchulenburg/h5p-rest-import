<?php
/**
 * Plugin Name: H5P REST Import
 * Plugin URI: https://github.com/dirkschulenburg/h5p-rest-import
 * Description: REST-API für automatischen H5P-Import aus der WordPress-Mediathek
 * Version: 1.0.0
 * Author: Dirk Schulenburg
 * Author URI: https://dirk-schulenburg.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: h5p-rest-import
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

// Direktzugriff verhindern
defined('ABSPATH') || exit;

// Plugin-Konstanten
define('H5P_REST_IMPORT_VERSION', '1.2.0');
define('H5P_REST_IMPORT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('H5P_REST_IMPORT_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Prüft ob das H5P-Plugin installiert und aktiviert ist
 */
function h5p_rest_import_check_dependencies() {
    if (!class_exists('H5P_Plugin')) {
        add_action('admin_notices', function() {
            $message = sprintf(
                '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
                __('H5P REST Import:', 'h5p-rest-import'),
                __('Das H5P-Plugin muss installiert und aktiviert sein, damit dieses Plugin funktioniert.', 'h5p-rest-import')
            );
            echo $message;
        });
        return false;
    }
    return true;
}

/**
 * Plugin initialisieren
 */
function h5p_rest_import_init() {
    // Abhängigkeiten prüfen
    if (!h5p_rest_import_check_dependencies()) {
        return;
    }

    // Klassen laden
    require_once H5P_REST_IMPORT_PLUGIN_DIR . 'includes/class-h5p-rest-validator.php';
    require_once H5P_REST_IMPORT_PLUGIN_DIR . 'includes/class-h5p-rest-importer.php';
    require_once H5P_REST_IMPORT_PLUGIN_DIR . 'includes/class-h5p-rest-api.php';

    // REST API initialisieren
    new H5P_REST_Import_API();
}
add_action('plugins_loaded', 'h5p_rest_import_init');

/**
 * Aktivierungs-Hook
 */
function h5p_rest_import_activate() {
    // Flush rewrite rules für REST API
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'h5p_rest_import_activate');

/**
 * Deaktivierungs-Hook
 */
function h5p_rest_import_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'h5p_rest_import_deactivate');
