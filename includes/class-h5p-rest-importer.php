<?php
/**
 * H5P REST Import - Importer Klasse
 *
 * Kernlogik für den H5P Import unter Nutzung des H5P Plugin Interfaces
 */

defined('ABSPATH') || exit;

class H5P_REST_Importer {

    /**
     * H5P-Datei importieren
     *
     * @param string $file_path Pfad zur H5P-Datei
     * @param string|null $title Optionaler Titel
     * @return array|WP_Error Import-Ergebnis oder Fehler
     */
    public function import($file_path, $title = null) {
        global $wpdb;

        // Validierung durchführen
        $validator = new H5P_REST_Validator();
        $validation = $validator->validate($file_path);

        if (is_wp_error($validation)) {
            return $validation;
        }

        // H5P Plugin Instanz holen
        if (!class_exists('H5P_Plugin')) {
            return new WP_Error(
                'h5p_not_available',
                __('H5P Plugin ist nicht verfügbar.', 'h5p-rest-import'),
                ['status' => 500]
            );
        }

        $plugin = H5P_Plugin::get_instance();

        // H5P Komponenten initialisieren
        $interface = $plugin->get_h5p_instance('interface');
        $validator_h5p = $plugin->get_h5p_instance('validator');
        $core = $plugin->get_h5p_instance('core');

        if (!$interface || !$validator_h5p || !$core) {
            return new WP_Error(
                'h5p_init_failed',
                __('H5P Komponenten konnten nicht initialisiert werden.', 'h5p-rest-import'),
                ['status' => 500]
            );
        }

        // H5P Upload-Pfad ermitteln
        $tmp_path = $interface->getUploadedH5pPath();
        $tmp_dir = dirname($tmp_path);

        // Verzeichnis erstellen falls nicht vorhanden
        if (!is_dir($tmp_dir)) {
            wp_mkdir_p($tmp_dir);
        }

        // Datei ins H5P temp-Verzeichnis kopieren
        if (!copy($file_path, $tmp_path)) {
            return new WP_Error(
                'copy_failed',
                __('H5P-Datei konnte nicht in das Temp-Verzeichnis kopiert werden.', 'h5p-rest-import'),
                ['status' => 500]
            );
        }

        // H5P Paket validieren und extrahieren
        $valid = $validator_h5p->isValidPackage(false, false);

        if (!$valid) {
            @unlink($tmp_path);
            $this->cleanup_temp_dir($tmp_dir);

            $h5p_errors = $interface->getMessages('error');
            return new WP_Error(
                'invalid_h5p_package',
                __('Das H5P-Paket ist ungültig oder beschädigt.', 'h5p-rest-import'),
                ['status' => 400, 'h5p_errors' => $h5p_errors]
            );
        }

        // mainJsonData aus Core holen (wurde durch isValidPackage gesetzt)
        $main_json_data = isset($core->mainJsonData) ? $core->mainJsonData : null;

        if (!$main_json_data || !isset($main_json_data['mainLibrary'])) {
            @unlink($tmp_path);
            $this->cleanup_temp_dir($tmp_dir);

            return new WP_Error(
                'missing_main_library',
                __('H5P-Paket enthält keine gültige mainLibrary Definition.', 'h5p-rest-import'),
                ['status' => 400]
            );
        }

        // Titel ermitteln
        $h5p_title = $title;
        if (empty($h5p_title) && isset($main_json_data['title'])) {
            $h5p_title = $main_json_data['title'];
        }
        if (empty($h5p_title)) {
            $h5p_title = 'Imported Content';
        }

        // Bibliothek-ID ermitteln
        $lib_name = $main_json_data['mainLibrary'];
        $library_id = $this->find_library_id($lib_name, $main_json_data);

        if (!$library_id) {
            @unlink($tmp_path);
            $this->cleanup_temp_dir($tmp_dir);

            return new WP_Error(
                'library_not_found',
                sprintf(
                    __('Die benötigte H5P-Bibliothek "%s" ist nicht installiert.', 'h5p-rest-import'),
                    $lib_name
                ),
                ['status' => 400]
            );
        }

        // Content-Parameter aus extrahiertem Paket lesen
        $uploaded_folder = $interface->getUploadedH5pFolderPath();
        $content_json_path = $uploaded_folder . '/content/content.json';

        $params = '{}';
        if (file_exists($content_json_path)) {
            $params = file_get_contents($content_json_path);
        }

        // H5P-Inhalt in Datenbank einfügen
        $current_user_id = get_current_user_id();
        $insert_result = $wpdb->insert(
            $wpdb->prefix . 'h5p_contents',
            array(
                'title' => sanitize_text_field($h5p_title),
                'library_id' => (int) $library_id,
                'parameters' => $params,
                'filtered' => '',
                'slug' => sanitize_title($h5p_title),
                'embed_type' => 'div',
                'disable' => 0,
                'user_id' => $current_user_id,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ),
            array('%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s')
        );

        if ($insert_result === false) {
            @unlink($tmp_path);
            $this->cleanup_temp_dir($tmp_dir);

            return new WP_Error(
                'db_insert_failed',
                __('H5P-Inhalt konnte nicht in der Datenbank gespeichert werden.', 'h5p-rest-import'),
                ['status' => 500, 'db_error' => $wpdb->last_error]
            );
        }

        $content_id = $wpdb->insert_id;

        // Temp-Dateien aufräumen
        @unlink($tmp_path);
        $this->cleanup_temp_dir($tmp_dir);

        // Content-Informationen laden
        $content_info = $this->get_content_info($content_id);

        if (!$content_info) {
            return new WP_Error(
                'content_load_failed',
                __('H5P-Inhalt konnte nicht geladen werden.', 'h5p-rest-import'),
                ['status' => 500]
            );
        }

        return [
            'id'           => (int) $content_id,
            'title'        => $content_info->title,
            'content_type' => $content_info->content_type,
            'slug'         => $content_info->slug,
        ];
    }

    /**
     * Bibliothek-ID in der Datenbank finden
     *
     * @param string $lib_name Name der Bibliothek
     * @param array $main_json_data H5P Metadaten
     * @return int|null Bibliothek-ID oder null
     */
    private function find_library_id($lib_name, $main_json_data) {
        global $wpdb;

        // Versuche exakte Version aus preloadedDependencies zu finden
        if (isset($main_json_data['preloadedDependencies'])) {
            foreach ($main_json_data['preloadedDependencies'] as $dep) {
                if (isset($dep['machineName']) && $dep['machineName'] === $lib_name) {
                    $major = isset($dep['majorVersion']) ? (int)$dep['majorVersion'] : null;
                    $minor = isset($dep['minorVersion']) ? (int)$dep['minorVersion'] : null;

                    if ($major !== null && $minor !== null) {
                        $library_id = $wpdb->get_var($wpdb->prepare(
                            "SELECT id FROM {$wpdb->prefix}h5p_libraries
                             WHERE name = %s AND major_version = %d AND minor_version = %d",
                            $lib_name, $major, $minor
                        ));

                        if ($library_id) {
                            return (int) $library_id;
                        }
                    }
                    break;
                }
            }
        }

        // Fallback: Neueste Version der Bibliothek verwenden
        $library_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}h5p_libraries
             WHERE name = %s ORDER BY major_version DESC, minor_version DESC LIMIT 1",
            $lib_name
        ));

        return $library_id ? (int) $library_id : null;
    }

    /**
     * Content-Informationen aus der Datenbank laden
     *
     * @param int $content_id H5P Content ID
     * @return object|null Content-Daten
     */
    private function get_content_info($content_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT c.id, c.title, c.slug, c.created_at, c.updated_at, l.name as content_type
             FROM {$wpdb->prefix}h5p_contents c
             LEFT JOIN {$wpdb->prefix}h5p_libraries l ON c.library_id = l.id
             WHERE c.id = %d",
            $content_id
        ));
    }

    /**
     * Temporäres Verzeichnis aufräumen
     *
     * @param string $dir Verzeichnispfad
     */
    private function cleanup_temp_dir($dir) {
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            } elseif (is_dir($file)) {
                $this->delete_directory($file);
            }
        }
    }

    /**
     * Verzeichnis rekursiv löschen
     *
     * @param string $dir Verzeichnispfad
     */
    private function delete_directory($dir) {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->delete_directory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
