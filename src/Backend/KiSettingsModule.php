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
        'apiKey' => '',
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
            // API Key
            $apiKey = trim((string) Input::post('ki_api_key'));
            $apiKey = html_entity_decode($apiKey, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $apiKey = preg_replace('/\s+/', '', $apiKey);

            if ($apiKey !== '' && (!str_starts_with($apiKey, 'caki_') || strlen($apiKey) < 20)) {
                $this->Template->message = '<div class="tl_error">Ungültiger API Key. Muss mit "caki_" beginnen.</div>';
                $this->passSettingsToTemplate($settings);
                return;
            }

            // Enabled toggle
            $enabled = (bool) Input::post('ki_enabled');

            // Design settings - color picker is the source of truth
            $color = trim((string) Input::post('ki_color'));
            // Normalize: lowercase, ensure leading #
            if ($color !== '' && $color[0] !== '#') {
                $color = '#' . $color;
            }
            $color = strtolower($color);
            if (!preg_match('/^#[0-9a-f]{6}$/', $color)) {
                $color = self::DEFAULTS['color'];
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

            // Logo URL - sanitize, only allow relative paths or http(s) URLs
            $logoUrl = trim((string) Input::post('ki_logo_url'));
            if ($logoUrl !== '' && !preg_match('#^(https?://|/)#', $logoUrl)) {
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

            // Crawl pages
            $selectedPages = Input::post('ki_crawl_pages');
            $crawlPageIds = is_array($selectedPages) ? array_map('intval', $selectedPages) : [];

            // Save all settings
            $settings = [
                'enabled' => $enabled,
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
            ];

            try {
                Config::persist('kiAssistentApiKey', $apiKey);
                Config::persist('kiAssistentSettings', json_encode($settings, JSON_THROW_ON_ERROR));

                // Auto-register site at portal when API key is set
                if ($apiKey !== '') {
                    $registerResult = $this->registerSiteAtPortal($apiKey);

                    // Send crawl page URLs to portal
                    if (!empty($crawlPageIds)) {
                        $this->sendCrawlPagesToPortal($apiKey, $crawlPageIds);
                    }
                }

                $statusText = $enabled ? 'aktiviert' : 'deaktiviert';
                $message = 'Einstellungen gespeichert! Widget ist ' . $statusText . '.';
                if (isset($registerResult) && $registerResult === true) {
                    $message .= ' Seite wurde im Portal registriert.';
                } elseif (isset($registerResult) && is_string($registerResult)) {
                    $message .= ' <span style="color:#d97706">Portal-Registrierung: ' . htmlspecialchars($registerResult, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
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
     * @return true|string true on success, error message on failure
     */
    private function registerSiteAtPortal(string $apiKey): true|string
    {
        try {
            $siteUrl = \Contao\Environment::get('url');
            if (empty($siteUrl)) {
                $siteUrl = \Contao\Environment::get('base');
            }
            if (empty($siteUrl)) {
                return 'Seiten-URL konnte nicht ermittelt werden.';
            }

            $payload = json_encode(['siteUrl' => $siteUrl], JSON_THROW_ON_ERROR);
            $endpoint = 'https://portal.venne-software.de/contao-agent/api/ki/' . $apiKey . '/register-site';

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
                return 'API Key nicht gefunden im Portal.';
            }

            if ($httpCode !== 200) {
                return 'Portal antwortet mit HTTP ' . $httpCode;
            }

            return true;
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
