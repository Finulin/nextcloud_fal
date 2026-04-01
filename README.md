# nextcloud_fal

TYPO3 FAL-Driver, der NextCloud als Datei-Storage-Backend einbindet. Die Kommunikation mit NextCloud erfolgt über WebDAV (`remote.php/dav/files/`).

## Voraussetzungen

| Anforderung | Version |
|-------------|---------|
| PHP | ^8.2 |
| TYPO3 | ^13.4 |
| NextCloud | beliebig (WebDAV-Zugang erforderlich) |

## Installation

### Via GitHub (empfohlen)

Das GitHub-Repository als Composer-Quelle in der `composer.json` des TYPO3-Projekts eintragen:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/Finulin/nextcloud_fal"
    }
]
```

Anschließend installieren:

```bash
composer require codeblick/nextcloud-fal
composer exec typo3 -- extension:activate nextcloud_fal
composer exec typo3 -- cache:flush
```

### Als lokales Paket (Entwicklung)

Die Extension liegt als lokales Composer-Paket im TYPO3-Basisprojekt unter `packages/nextcloud_fal/`. Das Parent-`composer.json` muss den `path`-Repository-Eintrag enthalten:

```json
"repositories": [{"type": "path", "url": "./packages/*"}]
```

Anschließend aus dem Parent-Verzeichnis (`/Volumes/ZIKE/Projekte/WWW/typo3/`):

```bash
composer require codeblick/nextcloud-fal:@dev
composer exec typo3 -- extension:activate nextcloud_fal
composer exec typo3 -- cache:flush
```

## Konfiguration

Im TYPO3-Backend unter **Dateiliste → Speicher** einen neuen Speicher anlegen:

- **Treibertyp:** NextCloud WebDAV
- **Base URL:** URL der NextCloud-Instanz, z. B. `https://cloud.example.com`
- **Benutzername:** NextCloud-Benutzername
- **Passwort:** NextCloud-Passwort (oder App-Token)
- **Groß-/Kleinschreibung:** Standardmäßig aktiviert

Der Driver baut den WebDAV-Pfad automatisch als `{baseUrl}/remote.php/dav/files/{username}` auf.

## Verbindungstest (CLI)

```bash
# Alle NextCloud-Speicher auflisten und testen (bei nur einem automatisch)
composer exec typo3 -- nextcloud_fal:test-connection

# Gezielt einen Speicher per UID testen
composer exec typo3 -- nextcloud_fal:test-connection --storage=<uid>
```

Der Befehl gibt HTTP-Status, WebDAV-Pfad sowie den Inhalt des Root-Verzeichnisses aus.

## Funktionsumfang

| Funktion | Unterstützt |
|----------|-------------|
| Dateien hochladen / herunterladen | ja |
| Dateien erstellen / löschen / umbenennen | ja |
| Dateien kopieren / verschieben | ja |
| Ordner erstellen / löschen / umbenennen | ja |
| Ordner kopieren / verschieben | ja |
| Rekursive Verzeichnislisten | ja |
| Sortierung und Filterung | ja |
| Streaming-Download (speichereffizient) | ja |
| Bildmetadaten (Breite/Höhe) automatisch extrahieren | ja |
| Öffentliche URLs | nein |

## Architektur

```
Classes/
├── Client/
│   └── NextcloudClient.php          # HTTP-Client (Guzzle), WebDAV-Methoden
├── Command/
│   └── TestConnectionCommand.php    # CLI-Befehl für Verbindungstest
├── Driver/
│   └── NextcloudDriver.php          # FAL-DriverInterface-Implementierung
└── Index/
    └── NextcloudImageMetaDataExtractor.php  # Bildmetadaten-Extraktor (Breite/Höhe)
Configuration/
├── FlexForms/
│   └── NextcloudStorage.xml      # Backend-Konfigurationsformular
└── Services.yaml                 # Service-Konfiguration (CLI-Command)
Resources/Private/Language/
├── locallang.xlf                 # Beschriftungen (EN)
└── de.locallang.xlf              # Beschriftungen (DE)
```

### Driver-Registrierung

Der Driver ist über den Schlüssel `NextcloudFal` registriert (`ext_localconf.php`):

```php
$GLOBALS['TYPO3_CONF_VARS']['SYS']['fal']['registeredDrivers']['NextcloudFal'] = [
    'class' => \Codeblick\NextcloudFal\Driver\NextcloudDriver::class,
    'shortName' => 'NextcloudFal',
    'label' => 'NextCloud WebDAV',
    'flexFormDS' => 'FILE:EXT:nextcloud_fal/Configuration/FlexForms/NextcloudStorage.xml',
];
```

### Caching

Der Driver arbeitet mit zwei Cache-Ebenen, um WebDAV-Requests zu minimieren:

**In-Memory-Cache (Request-lokal)**

| Cache | Zweck |
|-------|-------|
| `entryCache` | Metadaten (Größe, mtime, MIME-Typ) je Pfad |
| `folderListingCache` | PROPFIND-Ergebnisse je Ordner (Depth 1) |
| `localFileCache` | Lokale Temp-Kopien für Hash-Berechnung und Verarbeitung |

**Persistenter Cache (request-übergreifend)**

Registriert als `nextcloud_fal` (TYPO3 CachingFramework, `FileBackend`, TTL 300 s). Ordner-Listings und Einzeleinträge werden gecacht und per Cache-Tag invalidiert, wenn ein Ordner oder eine Datei geändert wird.

Ein einzelner PROPFIND-Request mit `Depth: 1` befüllt beide Ebenen für alle Kinder eines Ordners gleichzeitig.

### Verarbeitete Bilder (Thumbnails)

Beim ersten Aufruf setzt der Driver `sys_file_storage.processingfolder` automatisch auf `1:/_processed_/`, sofern noch kein Storage-übergreifender Wert konfiguriert ist. Dadurch landen Thumbnails und skalierte Bilder im lokalen Fileadmin-Storage statt in NextCloud – was direkten URL-Zugriff ermöglicht und die Serverlast reduziert.

Den Ziel-Storage kann man nachträglich im Backend unter **Dateiliste → Speicher → Ordner für temporäre Bilder** ändern.

## Entwicklung

```bash
# Caches nach Konfigurationsänderungen leeren
composer exec typo3 -- cache:flush

# Alle verfügbaren CLI-Befehle auflisten
composer exec typo3 -- list
```

## Lizenz

GPL-2.0-or-later – siehe [GNU General Public License](https://www.gnu.org/licenses/gpl-2.0.html).
