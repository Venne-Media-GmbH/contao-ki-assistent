# Contao KI-Assistent

Ein Contao 5.3+ Bundle, das ein KI Chat-Widget über das [Venne Portal](https://portal.venne-software.de) in Ihre Contao-Website integriert.

## Features

- **Backend-Modul** zur Verwaltung des API-Keys
- **Frontend-Modul** zum Einbetten des KI Chat-Widgets auf beliebigen Seiten
- API-Key-Validierung (Format: `caki_...`)
- Mehrsprachig (Deutsch & Englisch)

## Voraussetzungen

- PHP >= 8.2
- Contao >= 5.3

## Installation

### Über Composer

```bash
composer require venne-media/contao-ki-assistent
```

Anschließend den Contao Install-Tool aufrufen oder die Datenbank aktualisieren:

```bash
php vendor/bin/contao-console contao:migrate
```

## Konfiguration

### 1. API-Key hinterlegen

1. Im Contao-Backend zu **System → KI Assistent** navigieren
2. Den API-Key aus dem [Venne Portal](https://portal.venne-software.de) eintragen
3. Der Key muss mit `caki_` beginnen und exakt 61 Zeichen lang sein
4. Speichern

### 2. Frontend-Modul einrichten

1. Unter **Layout → Module** ein neues Modul vom Typ **KI Chat Widget** anlegen
2. Das Modul in das gewünschte Seitenlayout einbinden
3. Das Chat-Widget wird automatisch auf allen Seiten mit diesem Layout angezeigt

## Struktur

```
contao-ki-assistent/
├── config/
│   └── services.yaml
├── src/
│   ├── Backend/
│   │   └── KiSettingsModule.php
│   ├── ContaoManager/
│   │   └── Plugin.php
│   ├── Controller/
│   │   └── FrontendModule/
│   │       └── KiChatWidgetController.php
│   ├── DependencyInjection/
│   │   └── ContaoKiAssistentExtension.php
│   └── ContaoKiAssistentBundle.php
├── Resources/
│   └── contao/
│       ├── config/
│       ├── dca/
│       ├── languages/
│       └── templates/
├── composer.json
└── README.md
```

## Lizenz

Proprietary – © Venne Media GmbH
