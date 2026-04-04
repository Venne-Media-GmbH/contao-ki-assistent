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

        if (Input::post('FORM_SUBMIT') === 'ki_assistent_settings') {
            $apiKey = trim((string) Input::post('ki_api_key'));

            // Bereinige Input
            $apiKey = html_entity_decode($apiKey, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $apiKey = preg_replace('/\s+/', '', $apiKey);

            if ($apiKey === '') {
                // Leerer Key = Key entfernen
                Config::persist('kiAssistentApiKey', '');
                $this->Template->message = '<div class="tl_confirm">API Key wurde entfernt.</div>';
                $this->Template->kiApiKey = '';
            } elseif (!str_starts_with($apiKey, 'caki_')) {
                $this->Template->message = '<div class="tl_error">Ung&uuml;ltiger API Key. Muss mit "caki_" beginnen.</div>';
                $this->Template->kiApiKey = $apiKey;
            } elseif (strlen($apiKey) !== 61) {
                $this->Template->message = '<div class="tl_error">Ung&uuml;ltiger API Key. Muss genau 61 Zeichen lang sein.</div>';
                $this->Template->kiApiKey = $apiKey;
            } elseif (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $apiKey)) {
                $this->Template->message = '<div class="tl_error">Ung&uuml;ltige Zeichen im API Key.</div>';
                $this->Template->kiApiKey = $apiKey;
            } else {
                try {
                    Config::persist('kiAssistentApiKey', $apiKey);
                    $this->Template->message = '<div class="tl_confirm">API Key wurde erfolgreich gespeichert!</div>';
                    $this->Template->kiApiKey = $apiKey;
                } catch (\Exception $e) {
                    $this->Template->message = '<div class="tl_error">Fehler beim Speichern: '
                        . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                        . '</div>';
                    $this->Template->kiApiKey = $apiKey;
                }
            }
        } else {
            $this->Template->kiApiKey = Config::get('kiAssistentApiKey') ?? '';
        }

        if (!defined('REQUEST_TOKEN')) {
            define('REQUEST_TOKEN', System::getContainer()->get('contao.csrf.token_manager')->getDefaultTokenValue());
        }
        $this->Template->requestToken = REQUEST_TOKEN;
    }
}
