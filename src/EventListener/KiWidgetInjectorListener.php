<?php

declare(strict_types=1);

namespace VenneMedia\ContaoKiAssistent\EventListener;

use Contao\Config;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Automatically injects the KI Chat Widget (complete CSS + HTML + JS inline)
 * into all frontend HTML responses when enabled in backend settings.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -128)]
class KiWidgetInjectorListener
{
    private const string VERSION = '2.1.0';

    private const string API_BASE = 'https://portal.venne-software.de/contao-agent/api/ki';

    private const array ICONS = [
        'chat' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 20.105V4.125A1.875 1.875 0 015.625 2.25h12.75A1.875 1.875 0 0120.25 4.125v11.25a1.875 1.875 0 01-1.875 1.875H7.875L3.75 20.105z"/>',
        'sparkle' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456z"/>',
        'bot' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8.25 3v1.5M4.5 8.25H3m18 0h-1.5M4.5 12H3m18 0h-1.5m-15 3.75H3m18 0h-1.5M8.25 19.5V21M12 3v1.5m0 15V21m3.75-18v1.5m0 15V21m-9-1.5h10.5a2.25 2.25 0 002.25-2.25V6.75a2.25 2.25 0 00-2.25-2.25H6.75A2.25 2.25 0 004.5 6.75v10.5a2.25 2.25 0 002.25 2.25zm.75-12h9v9h-9v-9z"/>',
    ];

    private const array DAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        $contentType = $response->headers->get('Content-Type', '');
        if (!str_contains($contentType, 'text/html')) {
            return;
        }

        $pathInfo = $request->getPathInfo();
        if (str_starts_with($pathInfo, '/contao') || str_starts_with($pathInfo, '/_')) {
            return;
        }

        if ($response->getStatusCode() !== 200) {
            return;
        }

        try {
            $settingsJson = Config::get('kiAssistentSettings');
            $apiKey = Config::get('kiAssistentApiKey');
        } catch (\Throwable) {
            return;
        }

        if (empty($apiKey) || !str_starts_with((string) $apiKey, 'caki_')) {
            return;
        }

        $settings = [];
        if (!empty($settingsJson)) {
            try {
                $settings = json_decode((string) $settingsJson, true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
            }
        }

        if (empty($settings['enabled'])) {
            return;
        }

        $content = $response->getContent();
        if ($content === false || !str_contains($content, '</body>')) {
            return;
        }

        $color = $settings['color'] ?? '#10b981';
        $position = $settings['position'] ?? 'bottom-right';
        $title = $settings['title'] ?? 'KI Assistent';
        $subtitle = $settings['subtitle'] ?? 'Ihr digitaler Assistent';
        $welcomeMessage = $settings['welcomeMessage'] ?? 'Hallo! Wie kann ich Ihnen helfen?';
        $bubbleIcon = $settings['bubbleIcon'] ?? 'chat';
        $logoUrl = $settings['logoUrl'] ?? '';
        $customCss = $settings['customCss'] ?? '';

        $isOffline = $this->isOutsideBusinessHours($settings);
        $offlineMessage = $settings['offlineMessage'] ?? 'Wir sind aktuell nicht erreichbar.';

        $consentEnabled = (bool) ($settings['consentEnabled'] ?? true);
        $consentTitle = $settings['consentTitle'] ?? 'Datenschutzhinweis';
        $consentText = $settings['consentText'] ?? '';
        $consentPrivacyUrl = $settings['consentPrivacyUrl'] ?? '';

        $apiUrl = self::API_BASE . '/' . $apiKey;
        $widget = $this->buildWidgetHtml(
            apiUrl: $apiUrl,
            apiKey: (string) $apiKey,
            color: $color,
            position: $position,
            title: $title,
            subtitle: $subtitle,
            welcomeMessage: $welcomeMessage,
            bubbleIcon: $bubbleIcon,
            logoUrl: $logoUrl,
            customCss: $customCss,
            isOffline: $isOffline,
            offlineMessage: $offlineMessage,
            consentEnabled: $consentEnabled,
            consentTitle: $consentTitle,
            consentText: $consentText,
            consentPrivacyUrl: $consentPrivacyUrl,
        );

        $content = str_replace('</body>', $widget . "\n</body>", $content);
        $response->setContent($content);
    }

    private function isOutsideBusinessHours(array $settings): bool
    {
        if (empty($settings['hoursEnabled'])) {
            return false;
        }

        $hours = $settings['hours'] ?? [];
        if (!is_array($hours)) {
            return false;
        }

        try {
            $now = new \DateTimeImmutable('now');
        } catch (\Throwable) {
            return false;
        }

        $dayKey = self::DAYS[(int) $now->format('w')];
        $today = $hours[$dayKey] ?? null;

        if (!is_array($today) || empty($today['open'])) {
            return true;
        }

        $from = $today['from'] ?? '00:00';
        $to = $today['to'] ?? '23:59';
        $nowMinutes = ((int) $now->format('H')) * 60 + (int) $now->format('i');
        $fromMinutes = $this->timeToMinutes($from);
        $toMinutes = $this->timeToMinutes($to);

        return !($nowMinutes >= $fromMinutes && $nowMinutes < $toMinutes);
    }

    private function timeToMinutes(string $time): int
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
            return ((int) $m[1]) * 60 + (int) $m[2];
        }
        return 0;
    }

    private function buildWidgetHtml(
        string $apiUrl,
        string $apiKey,
        string $color,
        string $position,
        string $title,
        string $subtitle,
        string $welcomeMessage,
        string $bubbleIcon,
        string $logoUrl,
        string $customCss,
        bool $isOffline,
        string $offlineMessage,
        bool $consentEnabled,
        string $consentTitle,
        string $consentText,
        string $consentPrivacyUrl,
    ): string {
        // Decode any pre-encoded entities from Contao's Input filtering before re-encoding
        $decode = static fn(string $s): string => html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $title = $decode($title);
        $subtitle = $decode($subtitle);
        $welcomeMessage = $decode($welcomeMessage);
        $offlineMessage = $decode($offlineMessage);
        $consentTitle = $decode($consentTitle);
        $consentText = $decode($consentText);

        $apiUrlJs = addslashes($apiUrl);
        $apiKeyJs = addslashes($apiKey);
        $titleHtml = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $subtitleHtml = htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8');
        $welcomeJs = addslashes(htmlspecialchars($welcomeMessage, ENT_QUOTES, 'UTF-8'));
        $offlineJs = addslashes(htmlspecialchars($offlineMessage, ENT_QUOTES, 'UTF-8'));
        $colorCss = htmlspecialchars($color, ENT_QUOTES, 'UTF-8');
        $logoUrlHtml = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
        $consentTitleHtml = htmlspecialchars($consentTitle, ENT_QUOTES, 'UTF-8');
        $consentTextHtml = nl2br(htmlspecialchars($consentText, ENT_QUOTES, 'UTF-8'));
        $consentPrivacyUrlHtml = htmlspecialchars($consentPrivacyUrl, ENT_QUOTES, 'UTF-8');
        $consentFlag = $consentEnabled ? 'true' : 'false';
        $consentPrivacyLink = $consentPrivacyUrl !== ''
            ? '<a href="' . $consentPrivacyUrlHtml . '" target="_blank" rel="noopener" class="ca-ki-consent-link">Vollständige Datenschutzerklärung</a>'
            : '';

        $isLeft = $position === 'bottom-left';
        $posRight = $isLeft ? 'auto' : '24px';
        $posLeft = $isLeft ? '24px' : 'auto';
        $panelRight = $isLeft ? 'auto' : '24px';
        $panelLeft = $isLeft ? '24px' : 'auto';

        $iconSvg = self::ICONS[$bubbleIcon] ?? self::ICONS['chat'];

        // Bubble & header content depends on whether logo is set
        $bubbleInner = $logoUrl !== ''
            ? '<img src="' . $logoUrlHtml . '" alt="">'
            : '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">' . $iconSvg . '</svg>';

        $headerIconInner = $logoUrl !== ''
            ? '<img src="' . $logoUrlHtml . '" alt="">'
            : '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z"/></svg>';

        // Custom CSS for bubble
        $customCssClean = $customCss !== '' ? rtrim($customCss, ';') . ';' : '';

        $offlineFlag = $isOffline ? 'true' : 'false';

        $template = <<<'WIDGET'
<!-- Contao KI Assistent Widget – Powered by Venne Media -->
<style>
@keyframes ca-ki-in{from{opacity:0;transform:translateY(12px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}
@keyframes ca-ki-fade{from{opacity:0}to{opacity:1}}
@keyframes ca-ki-pulse{0%,100%{transform:scale(1);opacity:.6}50%{transform:scale(1.4);opacity:0}}
@keyframes ca-ki-dots{0%,80%,100%{opacity:.25;transform:translateY(0)}40%{opacity:1;transform:translateY(-2px)}}
#ca-ki-bubble{position:fixed;bottom:66px;right:__POS_RIGHT__;left:__POS_LEFT__;z-index:99999;width:62px;height:62px;border-radius:50%;background:__COLOR__;color:#fff;border:none;cursor:pointer;box-shadow:0 8px 24px -4px rgba(0,0,0,.25),0 4px 12px -2px rgba(0,0,0,.15);display:flex;align-items:center;justify-content:center;transition:transform .25s cubic-bezier(.34,1.56,.64,1),box-shadow .25s ease;overflow:hidden;__CUSTOM_CSS__}
#ca-ki-bubble:hover{transform:translateY(-3px) scale(1.04);box-shadow:0 12px 32px -4px rgba(0,0,0,.3),0 6px 16px -2px rgba(0,0,0,.18)}
#ca-ki-bubble:active{transform:scale(.96)}
#ca-ki-bubble svg{width:28px;height:28px}
#ca-ki-bubble img{width:100%;height:100%;object-fit:cover;border-radius:50%}
#ca-ki-bubble::after{content:'';position:absolute;inset:-4px;border-radius:50%;border:2px solid __COLOR__;animation:ca-ki-pulse 2.5s ease-out infinite;pointer-events:none}
#ca-ki-panel{position:fixed;bottom:140px;right:__PANEL_RIGHT__;left:__PANEL_LEFT__;z-index:99999;width:400px;max-width:calc(100vw - 24px);height:620px;max-height:calc(100vh - 160px);background:#fff;border-radius:20px;box-shadow:0 32px 80px -12px rgba(0,0,0,.28),0 0 0 1px rgba(0,0,0,.04);display:none;flex-direction:column;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',sans-serif;color:#1f2937}
#ca-ki-panel.open{display:flex;animation:ca-ki-in .3s cubic-bezier(.16,1,.3,1)}
#ca-ki-header{background:linear-gradient(135deg,__COLOR__ 0%,__COLOR__cc 100%);color:#fff;padding:18px 20px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;position:relative;overflow:hidden}
#ca-ki-header::before{content:'';position:absolute;top:-50%;right:-20%;width:200px;height:200px;background:radial-gradient(circle,rgba(255,255,255,.15),transparent 70%);pointer-events:none}
#ca-ki-hdr-left{display:flex;align-items:center;gap:12px;position:relative;z-index:1}
#ca-ki-hdr-icon{width:42px;height:42px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;overflow:hidden;border:2px solid rgba(255,255,255,.25);flex-shrink:0}
#ca-ki-hdr-icon svg{width:22px;height:22px}
#ca-ki-hdr-icon img{width:100%;height:100%;object-fit:cover}
#ca-ki-hdr-text h3{margin:0;font-size:16px;font-weight:600;letter-spacing:-.01em}
#ca-ki-hdr-text p{margin:2px 0 0;font-size:12px;opacity:.85;font-weight:400;display:flex;align-items:center;gap:5px}
#ca-ki-hdr-text .status-dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:#4ade80;box-shadow:0 0 0 2px rgba(74,222,128,.3)}
#ca-ki-hdr-text .status-dot.offline{background:#fbbf24;box-shadow:0 0 0 2px rgba(251,191,36,.3)}
#ca-ki-close{background:rgba(255,255,255,.15);border:none;color:#fff;cursor:pointer;padding:8px;border-radius:10px;display:flex;transition:background .15s;position:relative;z-index:1}
#ca-ki-close:hover{background:rgba(255,255,255,.25)}
#ca-ki-msgs{flex:1;overflow-y:auto;padding:20px 18px;display:flex;flex-direction:column;gap:12px;background:linear-gradient(180deg,#f9fafb 0%,#f3f4f6 100%)}
#ca-ki-msgs::-webkit-scrollbar{width:5px}
#ca-ki-msgs::-webkit-scrollbar-track{background:transparent}
#ca-ki-msgs::-webkit-scrollbar-thumb{background:rgba(0,0,0,.12);border-radius:3px}
#ca-ki-msgs::-webkit-scrollbar-thumb:hover{background:rgba(0,0,0,.2)}
.ca-ki-msg-wrap{display:flex;align-items:flex-end;gap:8px;animation:ca-ki-in .3s cubic-bezier(.16,1,.3,1)}
.ca-ki-msg-wrap.user{justify-content:flex-end}
.ca-ki-avatar{width:28px;height:28px;border-radius:50%;background:__COLOR__;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;box-shadow:0 2px 6px rgba(0,0,0,.12)}
.ca-ki-avatar svg{width:16px;height:16px;color:#fff}
.ca-ki-avatar img{width:100%;height:100%;object-fit:cover}
.ca-ki-msg{max-width:78%;padding:12px 16px;border-radius:18px;font-size:14.5px;line-height:1.55;word-wrap:break-word}
.ca-ki-msg a{color:inherit;text-decoration:underline;text-underline-offset:2px}
.ca-ki-msg code{background:rgba(0,0,0,.07);padding:2px 6px;border-radius:5px;font-size:12.5px;font-family:SFMono-Regular,Consolas,monospace}
.ca-ki-user .ca-ki-msg{background:__COLOR__;color:#fff;border-bottom-right-radius:6px;box-shadow:0 2px 8px -2px __COLOR__40}
.ca-ki-bot .ca-ki-msg{background:#fff;color:#1f2937;border-bottom-left-radius:6px;box-shadow:0 2px 8px -2px rgba(0,0,0,.08),0 0 0 1px rgba(0,0,0,.03)}
.ca-ki-typing .ca-ki-msg{padding:14px 18px}
.ca-ki-dots{display:inline-flex;align-items:center;gap:4px}
.ca-ki-dots span{display:inline-block;width:7px;height:7px;border-radius:50%;background:#9ca3af;animation:ca-ki-dots 1.4s infinite ease-in-out both}
.ca-ki-dots span:nth-child(1){animation-delay:-.32s}
.ca-ki-dots span:nth-child(2){animation-delay:-.16s}
.ca-ki-chip{display:inline-block;padding:8px 16px;background:#fff;color:#374151;border:1px solid #e5e7eb;border-radius:20px;font-size:13px;cursor:pointer;margin:3px 5px 3px 0;transition:all .2s;font-weight:500}
.ca-ki-chip:hover{background:__COLOR__;color:#fff;border-color:__COLOR__;transform:translateY(-1px);box-shadow:0 4px 12px -2px __COLOR__40}
.ca-ki-err{color:#dc2626;font-size:13px;background:#fef2f2;padding:10px 14px;border-radius:10px;border:1px solid #fecaca}
.ca-ki-pre{background:#1e1e2e;color:#cdd6f4;padding:12px 14px;border-radius:10px;font-size:12.5px;overflow-x:auto;margin:6px 0;font-family:SFMono-Regular,Consolas,monospace;white-space:pre-wrap;word-break:break-word}
.ca-ki-heading{display:block;margin:8px 0 3px;font-size:14.5px;font-weight:700}
.ca-ki-li{display:block;padding-left:16px;text-indent:-12px;margin:4px 0;line-height:1.55}
.ca-ki-hr{border:none;border-top:1px solid #e5e7eb;margin:10px 0}
.ca-ki-sources{margin-top:12px;padding:10px 14px;background:linear-gradient(135deg,rgba(0,0,0,.02),rgba(0,0,0,.04));border-radius:12px;font-size:12px;line-height:1.5;border:1px solid rgba(0,0,0,.04)}
.ca-ki-sources-title{font-weight:700;font-size:10px;text-transform:uppercase;letter-spacing:.6px;color:#9ca3af;margin-bottom:8px}
.ca-ki-src-link{display:flex;align-items:center;gap:8px;color:#4b5563;text-decoration:none;padding:5px 8px;margin:2px -8px;border-radius:8px;transition:all .15s}
.ca-ki-src-link:hover{background:__COLOR__10;color:__COLOR__;text-decoration:none}
.ca-ki-src-link span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ca-ki-src-link svg{color:__COLOR__}
.ca-ki-tbl{display:table;width:100%;border-collapse:collapse;margin:8px 0;font-size:13px}
.ca-ki-tbl-row{display:table-row}
.ca-ki-tbl-head{font-weight:700}
.ca-ki-tbl-head .ca-ki-tbl-cell{border-bottom:2px solid #d1d5db;padding-bottom:5px}
.ca-ki-tbl-cell{display:table-cell;padding:4px 10px 4px 0;vertical-align:top;border-bottom:1px solid #f0f0f0}
#ca-ki-input-area{border-top:1px solid #e5e7eb;padding:14px 16px;display:flex;gap:10px;flex-shrink:0;background:#fff;align-items:center}
#ca-ki-input{flex:1;border:1.5px solid #e5e7eb;border-radius:14px;padding:11px 16px;font-size:14px;resize:none;max-height:90px;line-height:1.45;font-family:inherit;outline:none;transition:border-color .15s,box-shadow .15s;background:#fafafa;color:#1f2937}
#ca-ki-input:focus{border-color:__COLOR__;background:#fff;box-shadow:0 0 0 4px __COLOR__15}
#ca-ki-input::placeholder{color:#9ca3af}
#ca-ki-input:disabled{background:#f3f4f6;cursor:not-allowed}
#ca-ki-send{width:44px;height:44px;min-width:44px;min-height:44px;border-radius:50%;background:__COLOR__;color:#fff!important;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:transform .15s,box-shadow .15s;box-shadow:0 4px 12px -2px __COLOR__50;font-size:22px;line-height:1;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;font-weight:400;text-align:center}
#ca-ki-send:hover{transform:translateY(-1px);box-shadow:0 6px 16px -2px __COLOR__60}
#ca-ki-send:active{transform:scale(.95)}
#ca-ki-send:disabled{background:#d1d5db;cursor:not-allowed;box-shadow:none;transform:none}
#ca-ki-footer{padding:8px 14px 10px;text-align:center;flex-shrink:0;background:#fff;border-top:1px solid #f3f4f6}
#ca-ki-footer a{font-size:11px;color:#9ca3af;text-decoration:none;letter-spacing:.02em;font-weight:500}
#ca-ki-footer a:hover{color:__COLOR__}
.ca-ki-ver{font-size:10px;color:#d1d5db;margin-left:6px;font-weight:400}
#ca-ki-consent{position:absolute;inset:0;top:74px;background:#fff;z-index:5;padding:24px 22px;display:none;flex-direction:column;overflow-y:auto;animation:ca-ki-fade .2s ease}
#ca-ki-consent.show{display:flex}
#ca-ki-consent .icon-wrap{width:56px;height:56px;border-radius:16px;background:__COLOR__15;display:flex;align-items:center;justify-content:center;margin-bottom:14px;align-self:flex-start}
#ca-ki-consent .icon-wrap svg{width:28px;height:28px;color:__COLOR__}
#ca-ki-consent h4{margin:0 0 10px;font-size:17px;font-weight:700;color:#1f2937;letter-spacing:-.01em}
#ca-ki-consent .text{font-size:13.5px;line-height:1.6;color:#4b5563;margin:0 0 14px;flex:1}
.ca-ki-consent-link{display:inline-block;font-size:12.5px;color:__COLOR__;text-decoration:underline;text-underline-offset:2px;margin-bottom:16px}
.ca-ki-consent-actions{display:flex;flex-direction:column;gap:8px;margin-top:auto;padding-top:8px;border-top:1px solid #f3f4f6}
.ca-ki-consent-btn{padding:13px 18px;border-radius:12px;font-size:14px;font-weight:600;cursor:pointer;border:none;font-family:inherit;transition:all .15s}
.ca-ki-consent-btn-accept{background:__COLOR__;color:#fff;box-shadow:0 4px 12px -2px __COLOR__50}
.ca-ki-consent-btn-accept:hover{transform:translateY(-1px);box-shadow:0 6px 16px -2px __COLOR__60}
.ca-ki-consent-btn-decline{background:#f3f4f6;color:#6b7280}
.ca-ki-consent-btn-decline:hover{background:#e5e7eb}
@media(max-width:640px){#ca-ki-panel{bottom:0;right:0;left:0;width:100vw;max-width:100vw;height:100vh;max-height:100vh;border-radius:0}#ca-ki-bubble{bottom:20px;right:20px}}
</style>
<button id="ca-ki-bubble" title="Chat öffnen" type="button">__BUBBLE_INNER__</button>
<div id="ca-ki-panel">
  <div id="ca-ki-header">
    <div id="ca-ki-hdr-left">
      <div id="ca-ki-hdr-icon">__HEADER_ICON__</div>
      <div id="ca-ki-hdr-text">
        <h3>__TITLE__</h3>
        <p><span class="status-dot __STATUS_CLASS__"></span>__SUBTITLE__</p>
      </div>
    </div>
    <button id="ca-ki-close" title="Schließen" type="button"><svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
  </div>
  <div id="ca-ki-consent">
    <div class="icon-wrap"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z"/></svg></div>
    <h4>__CONSENT_TITLE__</h4>
    <div class="text">__CONSENT_TEXT__</div>
    __CONSENT_PRIVACY_LINK__
    <div class="ca-ki-consent-actions">
      <button type="button" class="ca-ki-consent-btn ca-ki-consent-btn-accept" id="ca-ki-consent-accept">Akzeptieren &amp; Chat starten</button>
      <button type="button" class="ca-ki-consent-btn ca-ki-consent-btn-decline" id="ca-ki-consent-decline">Ablehnen</button>
    </div>
  </div>
  <div id="ca-ki-msgs"></div>
  <div id="ca-ki-input-area">
    <textarea id="ca-ki-input" rows="1" placeholder="Nachricht eingeben..." maxlength="5000"></textarea>
    <button id="ca-ki-send" title="Senden" type="button" aria-label="Senden">&#10148;</button>
  </div>
  <div id="ca-ki-footer"><a href="https://venne-media.de" target="_blank" rel="noopener">Powered by Venne Media</a><span class="ca-ki-ver">v__VERSION__</span></div>
</div>
<script>
(function(){
var API='__API_URL__',KEY='__API_KEY__';
var IS_OFFLINE=__OFFLINE_FLAG__,OFFLINE_MSG='__OFFLINE_MSG__',WELCOME='__WELCOME__';
var CONSENT_REQUIRED=__CONSENT_FLAG__;
var LOGO_URL='__LOGO_URL__';
var SK='ca_ki_s_'+KEY,VK='ca_ki_v_'+KEY,CK='ca_ki_consent_'+KEY;
var st=localStorage.getItem(SK)||null;
var vid=localStorage.getItem(VK);
if(!vid){vid='v_'+Math.random().toString(36).substr(2)+Date.now().toString(36);localStorage.setItem(VK,vid)}
var hasConsent=!CONSENT_REQUIRED||localStorage.getItem(CK)==='1';
var bubble=document.getElementById('ca-ki-bubble'),panel=document.getElementById('ca-ki-panel'),msgs=document.getElementById('ca-ki-msgs'),input=document.getElementById('ca-ki-input'),sendBtn=document.getElementById('ca-ki-send'),closeBtn=document.getElementById('ca-ki-close');
var consentBox=document.getElementById('ca-ki-consent'),acceptBtn=document.getElementById('ca-ki-consent-accept'),declineBtn=document.getElementById('ca-ki-consent-decline');
var isOpen=false,welcomed=false,sending=false,curBot=null,curTxt='';

function botAvatarHtml(){
  if(LOGO_URL){return '\x3cimg src="'+LOGO_URL.replace(/"/g,'')+'" alt="">'}
  return '\x3csvg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">\x3cpath stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z"/>\x3c/svg>'
}

function startChat(){
  welcomed=true;
  if(IS_OFFLINE){
    addMsg('bot',OFFLINE_MSG);
    input.disabled=true;sendBtn.disabled=true;
    input.placeholder='Aktuell nicht verfügbar';
  }else{
    addMsg('bot',WELCOME);
    loadConfig();
  }
}

function toggle(){
  isOpen=!isOpen;panel.classList.toggle('open',isOpen);
  if(isOpen){
    if(CONSENT_REQUIRED&&!hasConsent){
      consentBox.classList.add('show');
      input.disabled=true;sendBtn.disabled=true;
    }else if(!welcomed){
      startChat();
    }
  }
}
bubble.onclick=toggle;
closeBtn.onclick=function(){isOpen=false;panel.classList.remove('open')};

if(acceptBtn){
  acceptBtn.onclick=function(){
    localStorage.setItem(CK,'1');hasConsent=true;
    consentBox.classList.remove('show');
    if(!IS_OFFLINE){input.disabled=false;sendBtn.disabled=false}
    if(!welcomed)startChat();
  };
}
if(declineBtn){
  declineBtn.onclick=function(){
    consentBox.classList.remove('show');
    isOpen=false;panel.classList.remove('open');
  };
}

function addMsg(role,text){
  var wrap=document.createElement('div');
  wrap.className='ca-ki-msg-wrap '+(role==='user'?'user ca-ki-user':'ca-ki-bot');
  if(role==='bot'){
    var av=document.createElement('div');av.className='ca-ki-avatar';av.innerHTML=botAvatarHtml();wrap.appendChild(av);
  }
  var d=document.createElement('div');d.className='ca-ki-msg';d.innerHTML=md(text);wrap.appendChild(d);
  msgs.appendChild(wrap);msgs.scrollTop=msgs.scrollHeight;return d}

function addChips(qs){
  if(!qs||!qs.length)return;
  var w=document.createElement('div');w.style.padding='4px 0 4px 36px';
  qs.forEach(function(q){var c=document.createElement('span');c.className='ca-ki-chip';c.textContent=q;c.onclick=function(){w.remove();send(q)};w.appendChild(c)});
  msgs.appendChild(w);msgs.scrollTop=msgs.scrollHeight}

function md(t){
  t=t.replace(/&/g,'&amp;').replace(/\x3c/g,'&lt;').replace(/>/g,'&gt;');
  t=t.replace(/```[\w]*\n([\s\S]*?)```/g,function(m,c){return '\n\x3cpre class="ca-ki-pre">\x3ccode>'+c.trim()+'\x3c/code>\x3c/pre>\n'});
  var lines=t.split('\n'),out=[],inTbl=false,tblRows=[];
  function flushTbl(){if(!tblRows.length)return;var h='\x3cdiv class="ca-ki-tbl">';tblRows.forEach(function(r,i){h+='\x3cdiv class="ca-ki-tbl-row'+(i===0?' ca-ki-tbl-head':'')+'">'+r+'\x3c/div>'});h+='\x3c/div>';out.push(h);tblRows=[];inTbl=false}
  for(var i=0;i<lines.length;i++){var l=lines[i].trim();
    if(/^\|[\s\-:|]+\|$/.test(l)){inTbl=true;continue}
    if(/^\|(.+)\|$/.test(l)){inTbl=true;var cells=l.slice(1,-1).split('|').map(function(c){return c.trim()});tblRows.push(cells.map(function(c){return'\x3cspan class="ca-ki-tbl-cell">'+c+'\x3c/span>'}).join(''));continue}
    if(inTbl)flushTbl();
    if(/^#{1,6}\s+(.+)$/.test(l)){out.push('\x3cstrong class="ca-ki-heading">'+l.replace(/^#{1,6}\s+/,'')+'\x3c/strong>');continue}
    if(/^[-*_]{3,}$/.test(l)){out.push('\x3chr class="ca-ki-hr">');continue}
    if(/^[-*•]\s+(.+)$/.test(l)){out.push('\x3cdiv class="ca-ki-li">\u2022 '+l.replace(/^[-*•]\s+/,'')+'\x3c/div>');continue}
    if(/^\d+\.\s+(.+)$/.test(l)){out.push('\x3cdiv class="ca-ki-li">'+l+'\x3c/div>');continue}
    if(l===''){out.push('\x3cbr>');continue}
    out.push(l)}
  if(inTbl)flushTbl();
  t=out.join('\x3cbr>');
  t=t.replace(/`([^`]+)`/g,'\x3ccode>$1\x3c/code>');
  t=t.replace(/\*\*(.+?)\*\*/g,'\x3cstrong>$1\x3c/strong>');
  t=t.replace(/\*(.+?)\*/g,'\x3cem>$1\x3c/em>');
  t=t.replace(/\[([^\]]+)\]\(([^)]+)\)/g,'\x3ca href="$2" target="_blank" rel="noopener">$1\x3c/a>');
  t=t.replace(/(\x3cbr>){3,}/g,'\x3cbr>\x3cbr>');
  return t}

function loadConfig(){
  fetch(API+'/config').then(function(r){return r.json()}).then(function(d){
    if(d.suggestedQuestions&&d.suggestedQuestions.length)addChips(d.suggestedQuestions);
  }).catch(function(){});}

function send(text){
  if(sending||IS_OFFLINE)return;if(!text)text=input.value.trim();if(!text)return;
  input.value='';input.style.height='auto';sending=true;sendBtn.disabled=true;
  addMsg('user',text);
  curBot=addMsg('bot','');
  var wrap=curBot.parentNode;wrap.classList.add('ca-ki-typing');
  curBot.innerHTML='\x3cdiv class="ca-ki-dots">\x3cspan>\x3c/span>\x3cspan>\x3c/span>\x3cspan>\x3c/span>\x3c/div>';curTxt='';

  fetch(API+'/chat',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({message:text,session_token:st,visitor_id:vid})
  }).then(function(r){
    if(!r.ok){
      if(r.status===429){curBot.innerHTML='\x3cspan class="ca-ki-err">Zu viele Anfragen. Bitte warten.\x3c/span>'}
      else{curBot.innerHTML='\x3cspan class="ca-ki-err">Fehler ('+r.status+')\x3c/span>'}
      sending=false;sendBtn.disabled=false;return}
    var reader=r.body.getReader(),dec=new TextDecoder(),buf='';
    wrap.classList.remove('ca-ki-typing');curBot.innerHTML='';

    function read(){
      reader.read().then(function(res){
        if(res.done){sending=false;sendBtn.disabled=false;return}
        buf+=dec.decode(res.value,{stream:true});
        var lines=buf.split('\n');buf=lines.pop()||'';
        lines.forEach(function(line){
          line=line.trim();if(!line.startsWith('data: '))return;
          try{var d=JSON.parse(line.substring(6));
            if(d.type==='token'&&d.content){curTxt+=d.content;curBot.innerHTML=md(curTxt);msgs.scrollTop=msgs.scrollHeight}
            if(d.type==='start'&&d.session_token){st=d.session_token;localStorage.setItem(SK,st)}
            if(d.type==='done'){if(d.session_token){st=d.session_token;localStorage.setItem(SK,st)}sending=false;sendBtn.disabled=false;if(d.sources&&d.sources.length&&curBot){var sb='\x3cdiv class="ca-ki-sources">\x3cdiv class="ca-ki-sources-title">Quellen\x3c/div>';for(var si=0;si<d.sources.length;si++){var s=d.sources[si];sb+='\x3ca href="'+s.url+'" target="_blank" rel="noopener" class="ca-ki-src-link">\x3csvg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;opacity:.5">\x3cpath stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/>\x3c/svg>\x3cspan>'+s.title+'\x3c/span>\x3c/a>'}sb+='\x3c/div>';curBot.innerHTML+=sb;msgs.scrollTop=msgs.scrollHeight}}
            if(d.error==='limit_reached'){curBot.innerHTML='\x3cspan class="ca-ki-err">Das monatliche Limit wurde erreicht.\x3c/span>';sending=false;sendBtn.disabled=false}
          }catch(e){}});
        read()}).catch(function(){
          if(!curTxt)curBot.innerHTML='\x3cspan class="ca-ki-err">Verbindungsfehler.\x3c/span>';
          sending=false;sendBtn.disabled=false})}
    read()
  }).catch(function(){curBot.innerHTML='\x3cspan class="ca-ki-err">Verbindungsfehler. Bitte versuchen Sie es erneut.\x3c/span>';sending=false;sendBtn.disabled=false})}

sendBtn.onclick=function(){send()};
input.addEventListener('keydown',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();send()}});
input.addEventListener('input',function(){this.style.height='auto';this.style.height=Math.min(this.scrollHeight,90)+'px'});
})();
</script>
WIDGET;

        return str_replace(
            ['__API_URL__', '__API_KEY__', '__COLOR__', '__POS_RIGHT__', '__POS_LEFT__', '__PANEL_RIGHT__', '__PANEL_LEFT__', '__BUBBLE_INNER__', '__HEADER_ICON__', '__TITLE__', '__SUBTITLE__', '__STATUS_CLASS__', '__WELCOME__', '__OFFLINE_MSG__', '__OFFLINE_FLAG__', '__LOGO_URL__', '__CUSTOM_CSS__', '__CONSENT_TITLE__', '__CONSENT_TEXT__', '__CONSENT_PRIVACY_LINK__', '__CONSENT_FLAG__', '__VERSION__'],
            [$apiUrlJs, $apiKeyJs, $colorCss, $posRight, $posLeft, $panelRight, $panelLeft, $bubbleInner, $headerIconInner, $titleHtml, $subtitleHtml, $isOffline ? 'offline' : '', $welcomeJs, $offlineJs, $offlineFlag, addslashes($logoUrlHtml), $customCssClean, $consentTitleHtml, $consentTextHtml, $consentPrivacyLink, $consentFlag, self::VERSION],
            $template,
        );
    }
}
