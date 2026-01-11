<?php
/**
 * H5P REST Import - Validator Klasse
 *
 * Validiert H5P-Dateien vor dem Import
 */

defined('ABSPATH') || exit;

class H5P_REST_Validator {

    /**
     * Maximale Dateigröße in Bytes (100 MB)
     */
    const MAX_FILE_SIZE = 104857600;

    /**
     * Erlaubte MIME-Types
     */
    private $allowed_mime_types = [
        'application/zip',
        'application/x-zip',
        'application/x-zip-compressed',
        'application/octet-stream', // Manchmal wird .h5p so erkannt
    ];

    /**
     * H5P-Datei validieren
     *
     * @param string $file_path Pfad zur H5P-Datei
     * @return true|WP_Error True bei Erfolg, WP_Error bei Fehler
     */
    public function validate($file_path) {
        // Datei existiert?
        if (!file_exists($file_path)) {
            return new WP_Error(
                'file_not_found',
                __('Datei nicht gefunden.', 'h5p-rest-import'),
                ['status' => 404]
            );
        }

        // Datei lesbar?
        if (!is_readable($file_path)) {
            return new WP_Error(
                'file_not_readable',
                __('Datei ist nicht lesbar.', 'h5p-rest-import'),
                ['status' => 403]
            );
        }

        // Dateigröße prüfen
        $file_size = filesize($file_path);
        if ($file_size === false || $file_size === 0) {
            return new WP_Error(
                'empty_file',
                __('Datei ist leer.', 'h5p-rest-import'),
                ['status' => 400]
            );
        }

        if ($file_size > self::MAX_FILE_SIZE) {
            return new WP_Error(
                'file_too_large',
                sprintf(
                    __('Datei ist zu groß. Maximum: %s MB', 'h5p-rest-import'),
                    round(self::MAX_FILE_SIZE / 1048576)
                ),
                ['status' => 400]
            );
        }

        // MIME-Type prüfen
        $mime_check = $this->validate_mime_type($file_path);
        if (is_wp_error($mime_check)) {
            return $mime_check;
        }

        // ZIP-Struktur prüfen
        $zip_check = $this->validate_zip_structure($file_path);
        if (is_wp_error($zip_check)) {
            return $zip_check;
        }

        // H5P-spezifische Struktur prüfen
        $h5p_check = $this->validate_h5p_structure($file_path);
        if (is_wp_error($h5p_check)) {
            return $h5p_check;
        }

        return true;
    }

    /**
     * MIME-Type validieren
     *
     * @param string $file_path Pfad zur Datei
     * @return true|WP_Error
     */
    private function validate_mime_type($file_path) {
        // PHP finfo nutzen
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file_path);
            finfo_close($finfo);

            if (!in_array($mime, $this->allowed_mime_types, true)) {
                // Zusätzliche Prüfung: Datei-Extension
                $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
                if ($ext !== 'h5p' && $ext !== 'zip') {
                    return new WP_Error(
                        'invalid_mime_type',
                        sprintf(
                            __('Ungültiger Dateityp: %s. Erwartet: ZIP/H5P', 'h5p-rest-import'),
                            $mime
                        ),
                        ['status' => 400]
                    );
                }
            }
        }

        return true;
    }

    /**
     * ZIP-Struktur validieren
     *
     * @param string $file_path Pfad zur Datei
     * @return true|WP_Error
     */
    private function validate_zip_structure($file_path) {
        if (!class_exists('ZipArchive')) {
            // ZipArchive nicht verfügbar, überspringen
            return true;
        }

        $zip = new ZipArchive();
        $result = $zip->open($file_path);

        if ($result !== true) {
            $error_messages = [
                ZipArchive::ER_EXISTS => 'Datei existiert bereits',
                ZipArchive::ER_INCONS => 'Inkonsistentes ZIP-Archiv',
                ZipArchive::ER_INVAL  => 'Ungültiges Argument',
                ZipArchive::ER_MEMORY => 'Speicherfehler',
                ZipArchive::ER_NOENT  => 'Datei nicht gefunden',
                ZipArchive::ER_NOZIP  => 'Keine ZIP-Datei',
                ZipArchive::ER_OPEN   => 'Datei konnte nicht geöffnet werden',
                ZipArchive::ER_READ   => 'Lesefehler',
                ZipArchive::ER_SEEK   => 'Seek-Fehler',
            ];

            $message = isset($error_messages[$result])
                ? $error_messages[$result]
                : 'Unbekannter Fehler';

            return new WP_Error(
                'zip_open_failed',
                sprintf(
                    __('ZIP-Archiv konnte nicht geöffnet werden: %s', 'h5p-rest-import'),
                    $message
                ),
                ['status' => 400]
            );
        }

        $zip->close();
        return true;
    }

    /**
     * H5P-spezifische Struktur validieren
     *
     * @param string $file_path Pfad zur Datei
     * @return true|WP_Error
     */
    private function validate_h5p_structure($file_path) {
        if (!class_exists('ZipArchive')) {
            return true;
        }

        $zip = new ZipArchive();
        if ($zip->open($file_path) !== true) {
            return new WP_Error(
                'zip_open_failed',
                __('ZIP-Archiv konnte nicht geöffnet werden.', 'h5p-rest-import'),
                ['status' => 400]
            );
        }

        // h5p.json muss vorhanden sein
        $has_h5p_json = $zip->locateName('h5p.json') !== false;

        if (!$has_h5p_json) {
            $zip->close();
            return new WP_Error(
                'invalid_h5p_structure',
                __('Keine gültige H5P-Datei: h5p.json fehlt.', 'h5p-rest-import'),
                ['status' => 400]
            );
        }

        // h5p.json Inhalt prüfen
        $h5p_json_content = $zip->getFromName('h5p.json');
        $zip->close();

        if ($h5p_json_content === false) {
            return new WP_Error(
                'h5p_json_read_failed',
                __('h5p.json konnte nicht gelesen werden.', 'h5p-rest-import'),
                ['status' => 400]
            );
        }

        $h5p_data = json_decode($h5p_json_content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error(
                'h5p_json_invalid',
                sprintf(
                    __('h5p.json ist kein gültiges JSON: %s', 'h5p-rest-import'),
                    json_last_error_msg()
                ),
                ['status' => 400]
            );
        }

        // Pflichtfelder in h5p.json prüfen
        $required_fields = ['mainLibrary'];
        foreach ($required_fields as $field) {
            if (!isset($h5p_data[$field])) {
                return new WP_Error(
                    'h5p_json_incomplete',
                    sprintf(
                        __('h5p.json ist unvollständig: Feld "%s" fehlt.', 'h5p-rest-import'),
                        $field
                    ),
                    ['status' => 400]
                );
            }
        }

        return true;
    }
}
