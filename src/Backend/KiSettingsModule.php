<?php

declare(strict_types=1);

namespace VenneMedia\ContaoKiAssistent\Backend;

use Contao\BackendModule;
use Contao\BackendUser;
use Contao\Config;
use Contao\Input;
use Contao\System;

class KiSettingsModule extends BackendModule
{
    protected $strTemplate = 'be_ki_settings';

    /** Default widget settings */
    private const DEFAULTS = [
        'enabled' => false,
        'kundeKey' => '',
        'apiKey' => '', // siteKey - auto-set from portal init response
        'color' => '#10b981',
        'position' => 'bottom-right',
        'title' => 'KI Assistent',
        'subtitle' => 'Ihr digitaler Assistent',
        'welcomeMessage' => 'Hallo! Wie kann ich Ihnen helfen?',
        'bubbleIcon' => 'chat',
        'logoUrl' => '',
        'customCss' => '',
        'crawlPageIds' => [],
        'hoursEnabled' => false,
        'offlineMessage' => 'Wir sind aktuell nicht erreichbar. Bitte versuchen Sie es später erneut.',
        'hours' => [
            'mon' => ['open' => true, 'from' => '09:00', 'to' => '17:00'],
            'tue' => ['open' => true, 'from' => '09:00', 'to' => '17:00'],
            'wed' => ['open' => true, 'from' => '09:00', 'to' => '17:00'],
            'thu' => ['open' => true, 'from' => '09:00', 'to' => '17:00'],
            'fri' => ['open' => true, 'from' => '09:00', 'to' => '17:00'],
            'sat' => ['open' => false, 'from' => '09:00', 'to' => '17:00'],
            'sun' => ['open' => false, 'from' => '09:00', 'to' => '17:00'],
        ],
        'consentEnabled' => true,
        'consentTitle' => 'Datenschutzhinweis',
        'consentText' => 'Dieser Chat wird von einer KI betrieben. Ihre Eingaben werden zur Beantwortung an einen externen KI-Dienst übermittelt und dort verarbeitet. Bitte geben Sie keine personenbezogenen oder sensiblen Daten (z.B. Namen, Adressen, Passwörter, Bankdaten) ein. Mit dem Klick auf "Akzeptieren" stimmen Sie der Verarbeitung Ihrer Eingaben zu.',
        'consentPrivacyUrl' => '',
    ];

    private const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public function __construct()
    {
        parent::__construct();
        $this->strTemplate = 'be_ki_settings';
    }

    public function generate()
    {
        $user = BackendUser::getInstance();
        if (!$user->isAdmin) {
            return '<div class="tl_error"><p>Nur Administratoren haben Zugriff auf diese Funktion.</p></div>';
        }

        return parent::generate();
    }

    protected function compile()
    {
        $this->Template->message = '';

        // Load current settings
        $settings = $this->loadSettings();

        if (Input::post('FORM_SUBMIT') === 'ki_assistent_settings') {
            // Kunde-Key (cak_) - global identifier from portal Settings page
            $kundeKey = trim((string) Input::post('ki_kunde_key'));
            $kundeKey = html_entity_decode($kundeKey, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $kundeKey = preg_replace('/\s+/', '', $kundeKey);

            if ($kundeKey !== '' && (!str_starts_with($kundeKey, 'cak_') || strlen($kundeKey) < 20)) {
                $this->Template->message = '<div class="tl_error">Ungültiger Kunden-Key. Muss mit "cak_" beginnen.</div>';
                $this->passSettingsToTemplate($settings);
                return;
            }

            // Site-Key wird automatisch vom Portal vergeben (caki_)
            // Aktuellen behalten falls schon vorhanden
            $apiKey = $settings['apiKey'] ?? '';

            // Enabled toggle
            $enabled = (bool) Input::post('ki_enabled');

            // Design settings - color picker is the source of truth.
            // Use postRaw + manual decode because Contao's Input::post strips/encodes
            // the leading '#' which breaks hex color values.
            $color = trim((string) Input::postRaw('ki_color'));
            $color = html_entity_decode($color, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // If # was stripped, prepend it
            if ($color !== '' && $color[0] !== '#') {
                $color = '#' . $color;
            }
            $color = strtolower($color);
            if (!preg_match('/^#[0-9a-f]{6}$/', $color)) {
                // Keep current color instead of falling back to default,
                // so users don't lose their color on validation issues
                $color = $settings['color'] ?? self::DEFAULTS['color'];
            }

            $position = Input::post('ki_position');
            if (!in_array($position, ['bottom-right', 'bottom-left'], true)) {
                $position = 'bottom-right';
            }

            $title = trim((string) Input::post('ki_title')) ?: self::DEFAULTS['title'];
            $subtitle = trim((string) Input::post('ki_subtitle'));
            $welcomeMessage = trim((string) Input::post('ki_welcome_message')) ?: self::DEFAULTS['welcomeMessage'];
            $bubbleIcon = Input::post('ki_bubble_icon');
            if (!in_array($bubbleIcon, ['chat', 'sparkle', 'bot'], true)) {
                $bubbleIcon = 'chat';
            }

            // Logo: handle file upload OR existing URL
            $logoUrl = trim((string) Input::post('ki_logo_url'));
            if ($logoUrl !== '' && !preg_match('#^(https?://|/)#', $logoUrl)) {
                $logoUrl = '';
            }

            // Check for uploaded file
            $uploadError = null;
            if (!empty($_FILES['ki_logo_file']['tmp_name']) && empty($_FILES['ki_logo_file']['error'])) {
                $uploadResult = $this->handleLogoUpload($_FILES['ki_logo_file']);
                if (is_array($uploadResult)) {
                    $logoUrl = $uploadResult['url'];
                } else {
                    $uploadError = $uploadResult;
                }
            } elseif (!empty($_FILES['ki_logo_file']['error']) && $_FILES['ki_logo_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                $uploadError = 'Upload-Fehlercode: ' . $_FILES['ki_logo_file']['error'];
            }

            // Remove logo if requested
            if (Input::post('ki_logo_remove') === '1') {
                $logoUrl = '';
            }

            $customCss = trim((string) Input::post('ki_custom_css'));
            $customCss = str_replace(['{', '}', '<', '>', '"'], '', $customCss);

            // Opening hours
            $hoursEnabled = (bool) Input::post('ki_hours_enabled');
            $offlineMessage = trim((string) Input::post('ki_offline_message')) ?: self::DEFAULTS['offlineMessage'];

            $hoursPost = Input::post('ki_hours');
            $hours = self::DEFAULTS['hours'];
            if (is_array($hoursPost)) {
                foreach (self::DAYS as $day) {
                    $dayData = $hoursPost[$day] ?? [];
                    $open = !empty($dayData['open']);
                    $from = $this->normalizeTime($dayData['from'] ?? '09:00');
                    $to = $this->normalizeTime($dayData['to'] ?? '17:00');
                    $hours[$day] = ['open' => $open, 'from' => $from, 'to' => $to];
                }
            }

            // Consent / Datenschutz
            $consentEnabled = (bool) Input::post('ki_consent_enabled');
            $consentTitle = trim((string) Input::post('ki_consent_title')) ?: self::DEFAULTS['consentTitle'];
            $consentText = trim((string) Input::post('ki_consent_text')) ?: self::DEFAULTS['consentText'];
            $consentPrivacyUrl = trim((string) Input::post('ki_consent_privacy_url'));
            if ($consentPrivacyUrl !== '' && !preg_match('#^(https?://|/)#', $consentPrivacyUrl)) {
                $consentPrivacyUrl = '';
            }

            // Crawl pages
            $selectedPages = Input::post('ki_crawl_pages');
            $crawlPageIds = is_array($selectedPages) ? array_map('intval', $selectedPages) : [];

            // Save all settings
            $settings = [
                'enabled' => $enabled,
                'kundeKey' => $kundeKey,
                'apiKey' => $apiKey,
                'color' => $color,
                'position' => $position,
                'title' => $title,
                'subtitle' => $subtitle,
                'welcomeMessage' => $welcomeMessage,
                'bubbleIcon' => $bubbleIcon,
                'logoUrl' => $logoUrl,
                'customCss' => $customCss,
                'crawlPageIds' => $crawlPageIds,
                'hoursEnabled' => $hoursEnabled,
                'offlineMessage' => $offlineMessage,
                'hours' => $hours,
                'consentEnabled' => $consentEnabled,
                'consentTitle' => $consentTitle,
                'consentText' => $consentText,
                'consentPrivacyUrl' => $consentPrivacyUrl,
            ];

            try {
                // Step 1: Init site at portal with kunde key → get site key back
                $initResult = null;
                if ($kundeKey !== '') {
                    $initResult = $this->initSiteAtPortal($kundeKey);
                    if (is_array($initResult) && !empty($initResult['siteKey'])) {
                        $apiKey = $initResult['siteKey'];
                        $settings['apiKey'] = $apiKey;
                    }
                }

                Config::persist('kiAssistentApiKey', $apiKey);
                Config::persist('kiAssistentSettings', json_encode($settings, JSON_THROW_ON_ERROR));

                // Send crawl page URLs to portal (uses site key)
                if ($apiKey !== '' && !empty($crawlPageIds)) {
                    $this->sendCrawlPagesToPortal($apiKey, $crawlPageIds);
                }

                $statusText = $enabled ? 'aktiviert' : 'deaktiviert';
                $message = 'Einstellungen gespeichert! Widget ist ' . $statusText . '.';
                if (is_array($initResult) && !empty($initResult['siteKey'])) {
                    $message .= ' Seite wurde im Portal registriert.';
                } elseif (is_string($initResult)) {
                    $message .= ' <span style="color:#d97706">Portal-Init fehlgeschlagen: ' . htmlspecialchars($initResult, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
                } elseif ($kundeKey === '') {
                    $message .= ' <span style="color:#d97706">Bitte tragen Sie den Kunden-Key aus dem Portal ein, damit die Webseite verbunden wird.</span>';
                }
                if ($uploadError !== null) {
                    $message .= ' <span style="color:#dc2626">Logo-Upload fehlgeschlagen: ' . htmlspecialchars($uploadError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
                }
                $this->Template->message = '<div class="tl_confirm">' . $message . '</div>';
            } catch (\Exception $e) {
                $this->Template->message = '<div class="tl_error">Fehler beim Speichern: '
                    . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . '</div>';
            }
        }

        $this->passSettingsToTemplate($settings);

        if (!defined('REQUEST_TOKEN')) {
            define('REQUEST_TOKEN', System::getContainer()->get('contao.csrf.token_manager')->getDefaultTokenValue());
        }
        $this->Template->requestToken = REQUEST_TOKEN;
    }

    /**
     * Init site at portal using the global Kunde-Key (cak_).
     * Returns ['siteKey' => 'caki_...', 'siteId' => 123, ...] on success or string error message on failure.
     *
     * @return array|string
     */
    private function initSiteAtPortal(string $kundeKey): array|string
    {
        try {
            $siteUrl = \Contao\Environment::get('url');
            if (empty($siteUrl)) {
                $siteUrl = \Contao\Environment::get('base');
            }
            if (empty($siteUrl)) {
                return 'Seiten-URL konnte nicht ermittelt werden.';
            }

            $payload = json_encode([
                'kundeKey' => $kundeKey,
                'siteUrl' => $siteUrl,
            ], JSON_THROW_ON_ERROR);
            $endpoint = 'https://portal.venne-software.de/contao-agent/api/ki/init';

            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => false,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                return 'Verbindungsfehler: ' . $error;
            }

            if ($httpCode === 404) {
                return 'Kunden-Key nicht gefunden im Portal. Bitte prüfen Sie den Key.';
            }

            if ($httpCode !== 200) {
                return 'Portal antwortet mit HTTP ' . $httpCode;
            }

            $data = json_decode((string) $response, true);
            if (!is_array($data) || empty($data['siteKey'])) {
                return 'Portal antwortet ohne Site-Key.';
            }

            return $data;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Send the selected crawl page URLs to the portal.
     *
     * @param int[] $pageIds
     */
    private function sendCrawlPagesToPortal(string $apiKey, array $pageIds): void
    {
        try {
            $urls = [];
            foreach ($pageIds as $pageId) {
                $page = \Contao\PageModel::findByPk($pageId);
                if ($page !== null) {
                    try {
                        $urls[] = $page->getAbsoluteUrl();
                    } catch (\Throwable) {
                        // Page might not have a valid URL (e.g. root page)
                    }
                }
            }

            if (empty($urls)) {
                return;
            }

            $payload = json_encode(['crawlUrls' => array_values($urls)], JSON_THROW_ON_ERROR);
            $endpoint = 'https://portal.venne-software.de/contao-agent/api/ki/' . $apiKey . '/crawl-pages';

            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable) {
        }
    }

    private function loadSettings(): array
    {
        $settings = self::DEFAULTS;

        $apiKey = Config::get('kiAssistentApiKey');
        if (!empty($apiKey)) {
            $settings['apiKey'] = (string) $apiKey;
        }

        $json = Config::get('kiAssistentSettings');
        if (!empty($json)) {
            try {
                $stored = json_decode((string) $json, true, 4, JSON_THROW_ON_ERROR);
                if (is_array($stored)) {
                    $settings = array_merge($settings, $stored);
                }
            } catch (\JsonException) {
            }
        }

        return $settings;
    }

    private function passSettingsToTemplate(array $settings): void
    {
        $this->Template->kiKundeKey = $settings['kundeKey'] ?? '';
        $this->Template->kiApiKey = $settings['apiKey'];
        $this->Template->kiEnabled = $settings['enabled'];
        $this->Template->kiColor = $settings['color'];
        $this->Template->kiPosition = $settings['position'];
        $this->Template->kiTitle = $settings['title'];
        $this->Template->kiSubtitle = $settings['subtitle'] ?? '';
        $this->Template->kiWelcomeMessage = $settings['welcomeMessage'];
        $this->Template->kiBubbleIcon = $settings['bubbleIcon'];
        $this->Template->kiLogoUrl = $settings['logoUrl'] ?? '';
        $this->Template->kiCustomCss = $settings['customCss'] ?? '';
        $this->Template->kiCrawlPageIds = $settings['crawlPageIds'] ?? [];
        $this->Template->kiHoursEnabled = $settings['hoursEnabled'] ?? false;
        $this->Template->kiOfflineMessage = $settings['offlineMessage'] ?? self::DEFAULTS['offlineMessage'];
        $this->Template->kiHours = $settings['hours'] ?? self::DEFAULTS['hours'];
        $this->Template->kiConsentEnabled = $settings['consentEnabled'] ?? true;
        $this->Template->kiConsentTitle = $settings['consentTitle'] ?? self::DEFAULTS['consentTitle'];
        $this->Template->kiConsentText = $settings['consentText'] ?? self::DEFAULTS['consentText'];
        $this->Template->kiConsentPrivacyUrl = $settings['consentPrivacyUrl'] ?? '';
        $this->Template->kiDays = self::DAYS;
        $this->Template->kiDayLabels = [
            'mon' => 'Montag',
            'tue' => 'Dienstag',
            'wed' => 'Mittwoch',
            'thu' => 'Donnerstag',
            'fri' => 'Freitag',
            'sat' => 'Samstag',
            'sun' => 'Sonntag',
        ];

        // Load page tree for the template
        $this->Template->pageTree = $this->loadPageTree();
    }

    /**
     * Handle uploaded logo file. Stores in files/ki-assistent/ and returns
     * an array with 'url' on success or an error string on failure.
     *
     * @return array{url: string}|string
     */
    private function handleLogoUpload(array $file): array|string
    {
        $allowedMimes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml'];
        $allowedExts = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];

        if ($file['size'] > 2 * 1024 * 1024) {
            return 'Datei zu groß (max. 2 MB).';
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts, true)) {
            return 'Dateityp nicht erlaubt: ' . $ext;
        }

        // MIME check (best effort - can be unavailable)
        if (function_exists('mime_content_type')) {
            $mime = @mime_content_type($file['tmp_name']);
            if ($mime !== false && !in_array($mime, $allowedMimes, true)) {
                return 'MIME-Typ nicht erlaubt: ' . $mime;
            }
        }

        // Determine target directory. In Contao 5 the webroot is <project>/public/
        // and 'files' is a directory there. In older setups it can be at project root.
        $container = System::getContainer();
        $projectDir = $container->getParameter('kernel.project_dir');

        $uploadPath = 'files';
        if ($container->hasParameter('contao.upload_path')) {
            $uploadPath = $container->getParameter('contao.upload_path');
        }

        // Try public/<uploadPath> first (Contao 5), then <uploadPath> (older)
        $candidates = [
            $projectDir . '/public/' . $uploadPath . '/ki-assistent',
            $projectDir . '/web/' . $uploadPath . '/ki-assistent',
            $projectDir . '/' . $uploadPath . '/ki-assistent',
        ];

        $uploadDir = null;
        foreach ($candidates as $candidate) {
            $parent = dirname($candidate);
            if (is_dir($parent) || is_dir(dirname($parent))) {
                $uploadDir = $candidate;
                break;
            }
        }

        if ($uploadDir === null) {
            $uploadDir = $candidates[0];
        }

        if (!is_dir($uploadDir)) {
            if (!@mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                return 'Verzeichnis konnte nicht erstellt werden: ' . $uploadDir;
            }
        }

        if (!is_writable($uploadDir)) {
            return 'Verzeichnis nicht beschreibbar: ' . $uploadDir;
        }

        $filename = 'logo-' . time() . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $targetPath = $uploadDir . '/' . $filename;

        if (!@move_uploaded_file($file['tmp_name'], $targetPath)) {
            // Fallback: copy + unlink
            if (!@copy($file['tmp_name'], $targetPath)) {
                return 'move_uploaded_file fehlgeschlagen. Ziel: ' . $targetPath;
            }
            @unlink($file['tmp_name']);
        }

        if (!file_exists($targetPath)) {
            return 'Datei nach Upload nicht vorhanden: ' . $targetPath;
        }

        @chmod($targetPath, 0664);

        // Sync into Contao DBAFS so the file shows up in the file manager
        try {
            $relativePath = $uploadPath . '/ki-assistent/' . $filename;
            \Contao\Dbafs::addResource($relativePath);
        } catch (\Throwable) {
            // Non-fatal: file is still on disk and accessible via URL
        }

        return ['url' => '/' . $uploadPath . '/ki-assistent/' . $filename];
    }

    private function normalizeTime(string $time): string
    {
        $time = trim($time);
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
            $h = max(0, min(23, (int) $m[1]));
            $min = max(0, min(59, (int) $m[2]));
            return sprintf('%02d:%02d', $h, $min);
        }
        return '09:00';
    }

    /**
     * Load the Contao page tree as a flat list with hierarchy info.
     *
     * @return array<array{id: int, title: string, alias: string, type: string, level: int, published: bool, url: string}>
     */
    private function loadPageTree(): array
    {
        $pages = [];

        try {
            $rootPages = \Contao\PageModel::findBy(
                ['pid=?', 'type=?', 'published=?'],
                [0, 'root', 1],
                ['order' => 'sorting ASC']
            );

            if ($rootPages === null) {
                return [];
            }

            foreach ($rootPages as $rootPage) {
                $pages[] = [
                    'id' => (int) $rootPage->id,
                    'title' => $rootPage->title,
                    'alias' => $rootPage->alias ?? '',
                    'type' => $rootPage->type,
                    'level' => 0,
                    'published' => true,
                    'url' => '',
                ];
                $this->loadChildPages((int) $rootPage->id, 1, $pages);
            }
        } catch (\Throwable) {
            // PageModel might not be available
        }

        return $pages;
    }

    private function loadChildPages(int $parentId, int $level, array &$pages): void
    {
        $children = \Contao\PageModel::findBy(
            ['pid=?', 'published=?'],
            [$parentId, 1],
            ['order' => 'sorting ASC']
        );

        if ($children === null) {
            return;
        }

        foreach ($children as $child) {
            $url = '';
            if (in_array($child->type, ['regular', 'forward', 'redirect'], true)) {
                try {
                    $url = $child->getAbsoluteUrl();
                } catch (\Throwable) {
                }
            }

            $pages[] = [
                'id' => (int) $child->id,
                'title' => $child->title,
                'alias' => $child->alias ?? '',
                'type' => $child->type,
                'level' => $level,
                'published' => (bool) $child->published,
                'url' => $url,
            ];

            $this->loadChildPages((int) $child->id, $level + 1, $pages);
        }
    }
}
