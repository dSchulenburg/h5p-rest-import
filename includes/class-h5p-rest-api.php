<?php
/**
 * H5P REST Import - REST API Klasse
 *
 * Registriert und verarbeitet die REST API Endpoints für H5P Import
 */

defined('ABSPATH') || exit;

class H5P_REST_Import_API {

    /**
     * API Namespace
     */
    private $namespace = 'h5p-import/v1';

    /**
     * Konstruktor - registriert REST Routes
     */
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * REST API Routes registrieren
     */
    public function register_routes() {
        // Import Endpoint
        register_rest_route($this->namespace, '/import', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handle_import'],
            'permission_callback' => [$this, 'check_permissions'],
            'args'                => $this->get_import_args(),
        ]);

        // Status Endpoint
        register_rest_route($this->namespace, '/status', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_status'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);

        // List Endpoint - Liste aller H5P Inhalte
        register_rest_route($this->namespace, '/list', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_list'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);
    }

    /**
     * Argumente für Import Endpoint definieren
     */
    private function get_import_args() {
        return [
            'media_id' => [
                'type'        => 'integer',
                'required'    => false,
                'description' => __('WordPress Media Library ID der H5P-Datei', 'h5p-rest-import'),
                'sanitize_callback' => 'absint',
            ],
            'file_url' => [
                'type'        => 'string',
                'format'      => 'uri',
                'required'    => false,
                'description' => __('URL zur H5P-Datei', 'h5p-rest-import'),
                'sanitize_callback' => 'esc_url_raw',
            ],
            'title' => [
                'type'        => 'string',
                'required'    => false,
                'description' => __('Optionaler Titel für den H5P-Inhalt', 'h5p-rest-import'),
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    /**
     * Berechtigungsprüfung
     */
    public function check_permissions($request) {
        // Prüfe verschiedene Capabilities
        // H5P Plugin nutzt 'edit_h5p_contents' oder 'manage_h5p_libraries'
        if (current_user_can('manage_options')) {
            return true;
        }

        if (current_user_can('edit_h5p_contents')) {
            return true;
        }

        // Fallback: Autor-Rechte
        if (current_user_can('publish_posts')) {
            return true;
        }

        return new WP_Error(
            'rest_forbidden',
            __('Du hast keine Berechtigung für diese Aktion.', 'h5p-rest-import'),
            ['status' => 403]
        );
    }

    /**
     * Import Handler
     */
    public function handle_import($request) {
        $media_id = $request->get_param('media_id');
        $file_url = $request->get_param('file_url');
        $title    = $request->get_param('title');

        // Validierung: media_id oder file_url muss angegeben sein
        if (empty($media_id) && empty($file_url)) {
            return new WP_Error(
                'missing_parameter',
                __('Entweder media_id oder file_url muss angegeben werden.', 'h5p-rest-import'),
                ['status' => 400]
            );
        }

        // Datei-Pfad ermitteln
        $file_path = null;
        $is_temp_file = false;

        if (!empty($media_id)) {
            $file_path = get_attached_file($media_id);

            if (!$file_path || !file_exists($file_path)) {
                return new WP_Error(
                    'file_not_found',
                    __('Media-Datei nicht gefunden.', 'h5p-rest-import'),
                    ['status' => 404]
                );
            }
        } else {
            // Download von URL
            $file_path = $this->download_temp_file($file_url);

            if (is_wp_error($file_path)) {
                return $file_path;
            }

            $is_temp_file = true;
        }

        // H5P Import durchführen
        $importer = new H5P_REST_Importer();
        $result = $importer->import($file_path, $title);

        // Temp-Datei löschen wenn von URL geladen
        if ($is_temp_file && file_exists($file_path)) {
            @unlink($file_path);
        }

        // Fehler behandeln
        if (is_wp_error($result)) {
            return $result;
        }

        // Erfolgreiche Antwort
        return rest_ensure_response([
            'success'      => true,
            'h5p_id'       => $result['id'],
            'title'        => $result['title'],
            'content_type' => $result['content_type'],
            'shortcode'    => '[h5p id="' . $result['id'] . '"]',
            'embed_code'   => '<!-- wp:shortcode -->[h5p id="' . $result['id'] . '"]<!-- /wp:shortcode -->',
        ]);
    }

    /**
     * Status Handler
     */
    public function handle_status($request) {
        $h5p_active = class_exists('H5P_Plugin');
        $h5p_version = null;

        if ($h5p_active) {
            $h5p_version = defined('H5P_Plugin::VERSION')
                ? H5P_Plugin::VERSION
                : (defined('H5P_VERSION') ? H5P_VERSION : 'unknown');
        }

        return rest_ensure_response([
            'plugin_active'     => true,
            'plugin_version'    => H5P_REST_IMPORT_VERSION,
            'h5p_plugin_active' => $h5p_active,
            'h5p_version'       => $h5p_version,
            'import_ready'      => $h5p_active,
            'php_version'       => PHP_VERSION,
            'wp_version'        => get_bloginfo('version'),
        ]);
    }

    /**
     * List Handler - Liste aller H5P Inhalte
     */
    public function handle_list($request) {
        global $wpdb;

        $contents = $wpdb->get_results(
            "SELECT c.id, c.title, c.slug, c.created_at, c.updated_at, l.name as library_name
             FROM {$wpdb->prefix}h5p_contents c
             LEFT JOIN {$wpdb->prefix}h5p_libraries l ON c.library_id = l.id
             ORDER BY c.id DESC"
        );

        $result = [];
        foreach ($contents as $content) {
            $result[] = [
                'id'           => (int) $content->id,
                'title'        => $content->title,
                'slug'         => $content->slug,
                'content_type' => $content->library_name,
                'created_at'   => $content->created_at,
                'updated_at'   => $content->updated_at,
                'shortcode'    => '[h5p id="' . $content->id . '"]',
            ];
        }

        return rest_ensure_response([
            'success' => true,
            'count'   => count($result),
            'items'   => $result,
        ]);
    }

    /**
     * Temporäre Datei von URL herunterladen
     */
    private function download_temp_file($url) {
        // WordPress download_url Funktion nutzen
        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $temp_file = download_url($url, 300); // 5 Minuten Timeout

        if (is_wp_error($temp_file)) {
            return new WP_Error(
                'download_failed',
                sprintf(
                    __('Datei konnte nicht heruntergeladen werden: %s', 'h5p-rest-import'),
                    $temp_file->get_error_message()
                ),
                ['status' => 500]
            );
        }

        return $temp_file;
    }
}
