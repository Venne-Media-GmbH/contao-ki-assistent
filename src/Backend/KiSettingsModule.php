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
        'welcomeMessage' => 'Hallo! Wie kann ich Ihnen helfen?',
        'bubbleIcon' => 'chat',
        'customCss' => '',
        'excludedPages' => '',
    ];

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

            // Design settings
            $color = trim((string) Input::post('ki_color'));
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                $color = self::DEFAULTS['color'];
            }

            $position = Input::post('ki_position');
            if (!in_array($position, ['bottom-right', 'bottom-left'], true)) {
                $position = 'bottom-right';
            }

            $title = trim((string) Input::post('ki_title')) ?: self::DEFAULTS['title'];
            $welcomeMessage = trim((string) Input::post('ki_welcome_message')) ?: self::DEFAULTS['welcomeMessage'];
            $bubbleIcon = Input::post('ki_bubble_icon');
            if (!in_array($bubbleIcon, ['chat', 'sparkle', 'bot'], true)) {
                $bubbleIcon = 'chat';
            }

            $customCss = trim((string) Input::post('ki_custom_css'));
            // Strip dangerous characters to prevent injection
            $customCss = str_replace(['{', '}', '<', '>', '"'], '', $customCss);

            $excludedPages = trim((string) Input::post('ki_excluded_pages'));

            // Save all settings
            $settings = [
                'enabled' => $enabled,
                'apiKey' => $apiKey,
                'color' => $color,
                'position' => $position,
                'title' => $title,
                'welcomeMessage' => $welcomeMessage,
                'bubbleIcon' => $bubbleIcon,
                'customCss' => $customCss,
                'excludedPages' => $excludedPages,
            ];

            try {
                Config::persist('kiAssistentApiKey', $apiKey);
                Config::persist('kiAssistentSettings', json_encode($settings, JSON_THROW_ON_ERROR));

                // Auto-register site at portal when API key is set
                if ($apiKey !== '') {
                    $registerResult = $this->registerSiteAtPortal($apiKey);

                    // Send excluded pages to portal
                    if ($excludedPages !== '') {
                        $this->sendExcludedPagesToPortal($apiKey, $excludedPages);
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

    private function sendExcludedPagesToPortal(string $apiKey, string $excludedPages): void
    {
        try {
            $lines = array_filter(array_map('trim', explode("\n", $excludedPages)));
            $payload = json_encode(['excludedPatterns' => array_values($lines)], JSON_THROW_ON_ERROR);
            $endpoint = 'https://portal.venne-software.de/contao-agent/api/ki/' . $apiKey . '/excluded-pages';

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
        $this->Template->kiWelcomeMessage = $settings['welcomeMessage'];
        $this->Template->kiBubbleIcon = $settings['bubbleIcon'];
        $this->Template->kiCustomCss = $settings['customCss'] ?? '';
        $this->Template->kiExcludedPages = $settings['excludedPages'] ?? '';
    }
}
