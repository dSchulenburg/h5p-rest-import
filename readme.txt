=== H5P REST Import ===
Contributors: dirkschulenburg
Tags: h5p, rest-api, import, automation, e-learning
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

REST-API für automatischen H5P-Import aus der WordPress-Mediathek oder externen URLs.

== Description ==

H5P REST Import erweitert das H5P-Plugin um REST-API Endpoints für den automatischen Import von H5P-Inhalten. Dies ermöglicht die vollautomatische Integration von H5P in Workflows und externe Systeme.

**Hauptfunktionen:**

* Import von H5P-Dateien via REST-API
* Unterstützung für Media Library IDs und externe URLs
* Vollständige Validierung der H5P-Pakete
* Kompatibel mit WordPress Application Passwords

**Anwendungsfälle:**

* Automatisierte Content-Pipelines
* Integration mit MCP (Model Context Protocol) Servern
* Batch-Import von H5P-Inhalten
* CI/CD für E-Learning-Inhalte

== Installation ==

1. Lade den Plugin-Ordner `h5p-rest-import` in `/wp-content/plugins/` hoch
2. Aktiviere das Plugin im WordPress Admin unter "Plugins"
3. Stelle sicher, dass das H5P-Plugin installiert und aktiviert ist
4. Konfiguriere Application Passwords für API-Zugriff

== API Endpoints ==

**POST /wp-json/h5p-import/v1/import**

Importiert eine H5P-Datei.

Request (mit Media ID):
`
{
  "media_id": 139,
  "title": "Mein Quiz"
}
`

Request (mit URL):
`
{
  "file_url": "https://example.com/quiz.h5p",
  "title": "Mein Quiz"
}
`

Response:
`
{
  "success": true,
  "h5p_id": 10,
  "title": "Mein Quiz",
  "content_type": "H5P.TrueFalse",
  "shortcode": "[h5p id=\"10\"]"
}
`

**GET /wp-json/h5p-import/v1/status**

Prüft den Plugin-Status.

**GET /wp-json/h5p-import/v1/list**

Listet alle H5P-Inhalte auf.

== Frequently Asked Questions ==

= Welche Berechtigungen werden benötigt? =

Der API-Benutzer benötigt die Capability `edit_h5p_contents`, `manage_options` oder `publish_posts`.

= Funktioniert das Plugin ohne das H5P-Plugin? =

Nein, das H5P-Plugin muss installiert und aktiviert sein.

= Wie groß dürfen H5P-Dateien sein? =

Das Plugin erlaubt Dateien bis 100 MB. Beachte auch die PHP- und WordPress-Upload-Limits.

== Changelog ==

= 1.2.0 =
* Direkter Datenbank-Import für zuverlässige H5P-Content-Erstellung
* Verbesserte Bibliotheks-Version-Erkennung aus preloadedDependencies
* Automatische Titel-Extraktion aus h5p.json
* Robuste Fehlerbehandlung mit aussagekräftigen Fehlermeldungen
* Getestet mit WordPress 6.9 und H5P Plugin 1.16.2

= 1.0.0 =
* Initiale Version
* Import Endpoint für Media ID und URL
* Status Endpoint
* List Endpoint
* Vollständige H5P-Validierung

== Upgrade Notice ==

= 1.2.0 =
Stabile Version mit zuverlässigem Import-Mechanismus.

= 1.0.0 =
Erste stabile Version.
