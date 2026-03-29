# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Projektübersicht

Diese TYPO3-Extension (`nextcloud_fal`) integriert NextCloud als Storage-Backend in TYPO3's File Abstraction Layer (FAL). Sie implementiert einen FAL-Driver, der Dateizugriffe auf eine NextCloud-Instanz über WebDAV oder die NextCloud API weiterleitet.

Die Extension liegt unter `packages/nextcloud_fal/` im TYPO3-Basisprojekt (`/Volumes/ZIKE/Projekte/WWW/typo3/`). Alle Befehle werden im Parent-Verzeichnis ausgeführt.

## Befehle

Alle Befehle aus dem Parent-Verzeichnis `/Volumes/ZIKE/Projekte/WWW/typo3/` ausführen:

```bash
# Entwicklungsserver starten
TYPO3_CONTEXT=Development php -S localhost:8000 -t public

# Abhängigkeiten installieren (auch die Extension einbinden)
composer install

# Extension in TYPO3 aktivieren
composer exec typo3 -- extension:activate nextcloud_fal

# Caches leeren
composer exec typo3 -- cache:flush

# Alle verfügbaren CLI-Befehle anzeigen
composer exec typo3 -- list
```

Nach Änderungen an `ext_localconf.php`, `ext_tables.php` oder Konfigurationsdateien müssen die Caches geleert werden.

## Architektur

### FAL-Driver-Konzept

TYPO3's FAL abstrahiert Dateizugriffe hinter einem einheitlichen Interface. Ein Storage-Backend wird als Driver implementiert:

- **`DriverInterface`** (`TYPO3\CMS\Core\Resource\Driver\DriverInterface`) – muss vollständig implementiert werden
- **`AbstractHierarchicalFilesystemDriver`** – Basisklasse für hierarchische Dateisysteme, sinnvolle Elternklasse für NextCloud
- Der Driver wird in `ext_localconf.php` über `DriverRegistry::registerDriverType()` registriert
- Im TYPO3-Backend kann dann unter *Dateiliste → Speicher* ein neuer Speicher mit dem NextCloud-Driver angelegt werden

### Erwartete Extension-Struktur

```
nextcloud_fal/
├── composer.json                          # type: typo3-cms-extension
├── ext_emconf.php                         # Extension-Metadaten für TYPO3
├── ext_localconf.php                      # FAL-Driver registrieren
├── Classes/
│   └── Driver/
│       └── NextcloudDriver.php            # Implementierung von DriverInterface
├── Configuration/
│   └── FlexForms/
│       └── NextcloudStorage.xml           # Konfigurationsformular im Backend
└── Resources/Private/
    └── Language/
        └── locallang.xlf                  # Beschriftungen
```

### Integration in das Parent-Projekt

Das Parent-`composer.json` enthält bereits:
```json
"repositories": [{"type": "path", "url": "./packages/*"}]
```

Sobald `packages/nextcloud_fal/composer.json` existiert, die Extension einbinden:
```bash
composer require <vendor>/nextcloud-fal:@dev
```

Die Extension muss danach im TYPO3-Backend oder per CLI aktiviert werden.

### Driver-Registrierung (`ext_localconf.php`)

```php
\TYPO3\CMS\Core\Resource\Driver\DriverRegistry::getInstance()
    ->registerDriverType(
        'NextcloudFal',
        \Vendor\NextcloudFal\Driver\NextcloudDriver::class,
        'NextCloud Storage'
    );
```

### Wichtige FAL-Interfaces und Klassen

| Klasse | Zweck |
|--------|-------|
| `TYPO3\CMS\Core\Resource\Driver\DriverInterface` | Pflicht-Interface für alle FAL-Driver |
| `TYPO3\CMS\Core\Resource\Driver\AbstractHierarchicalFilesystemDriver` | Basisimplementierung für hierarchische Dateisysteme |
| `TYPO3\CMS\Core\Resource\Driver\DriverRegistry` | Registriert Driver-Typen in TYPO3 |
| `TYPO3\CMS\Core\Resource\ResourceStorage` | Repräsentiert einen konfigurierten Speicherort |

Die Driver-Klasse erhält Konfigurationsparameter (NextCloud-URL, Zugangsdaten) aus dem FlexForm-Konfigurationsformular des jeweiligen Speichers.
