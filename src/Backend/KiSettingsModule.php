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

            // Save all settings
            $settings = [
                'enabled' => $enabled,
                'apiKey' => $apiKey,
                'color' => $color,
                'position' => $position,
                'title' => $title,
                'welcomeMessage' => $welcomeMessage,
                'bubbleIcon' => $bubbleIcon,
            ];

            try {
                Config::persist('kiAssistentApiKey', $apiKey);
                Config::persist('kiAssistentSettings', json_encode($settings, JSON_THROW_ON_ERROR));

                $statusText = $enabled ? 'aktiviert' : 'deaktiviert';
                $this->Template->message = '<div class="tl_confirm">Einstellungen gespeichert! Widget ist ' . $statusText . '.</div>';
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
    }
}
