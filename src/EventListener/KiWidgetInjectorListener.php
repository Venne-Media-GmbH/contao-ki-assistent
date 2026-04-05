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
    private const string API_BASE = 'https://portal.venne-software.de/contao-agent/api/ki';

    private const array ICONS = [
        'chat' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 20.105V4.125A1.875 1.875 0 015.625 2.25h12.75A1.875 1.875 0 0120.25 4.125v11.25a1.875 1.875 0 01-1.875 1.875H7.875L3.75 20.105z"/>',
        'sparkle' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456z"/>',
        'bot' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8.25 3v1.5M4.5 8.25H3m18 0h-1.5M4.5 12H3m18 0h-1.5m-15 3.75H3m18 0h-1.5M8.25 19.5V21M12 3v1.5m0 15V21m3.75-18v1.5m0 15V21m-9-1.5h10.5a2.25 2.25 0 002.25-2.25V6.75a2.25 2.25 0 00-2.25-2.25H6.75A2.25 2.25 0 004.5 6.75v10.5a2.25 2.25 0 002.25 2.25zm.75-12h9v9h-9v-9z"/>',
    ];

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

        // Load settings
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
                $settings = json_decode((string) $settingsJson, true, 4, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
            }
        }

        // Check if widget is enabled
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
        $welcomeMessage = $settings['welcomeMessage'] ?? 'Hallo! Wie kann ich Ihnen helfen?';
        $bubbleIcon = $settings['bubbleIcon'] ?? 'chat';

        $apiUrl = self::API_BASE . '/' . $apiKey;
        $widget = $this->buildWidgetHtml($apiUrl, (string) $apiKey, $color, $position, $title, $welcomeMessage, $bubbleIcon);

        $content = str_replace('</body>', $widget . "\n</body>", $content);
        $response->setContent($content);
    }

    private function buildWidgetHtml(
        string $apiUrl,
        string $apiKey,
        string $color,
        string $position,
        string $title,
        string $welcomeMessage,
        string $bubbleIcon,
    ): string {
        $apiUrlJs = addslashes($apiUrl);
        $apiKeyJs = addslashes($apiKey);
        $titleJs = addslashes(htmlspecialchars($title, ENT_QUOTES, 'UTF-8'));
        $welcomeJs = addslashes(htmlspecialchars($welcomeMessage, ENT_QUOTES, 'UTF-8'));
        $colorCss = htmlspecialchars($color, ENT_QUOTES, 'UTF-8');

        $isLeft = $position === 'bottom-left';
        $posRight = $isLeft ? 'auto' : '24px';
        $posLeft = $isLeft ? '24px' : 'auto';
        $panelRight = $isLeft ? 'auto' : '24px';
        $panelLeft = $isLeft ? '24px' : 'auto';

        $iconSvg = self::ICONS[$bubbleIcon] ?? self::ICONS['chat'];

        // customCss from settings
        $customCss = $this->getCustomCss();

        // Use Nowdoc to avoid PHP escape issues in JS code
        $template = <<<'WIDGET'
<!-- Contao KI Assistent Widget – Powered by Venne Media -->
<style>
@keyframes ca-ki-in{from{opacity:0;transform:translateY(12px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}
@keyframes ca-ki-dots{0%,80%,100%{opacity:.25}40%{opacity:1}}
#ca-ki-bubble{position:fixed;bottom:28px;right:__POS_RIGHT__;left:__POS_LEFT__;z-index:99999;width:58px;height:58px;border-radius:16px;background:__COLOR__;color:#fff;border:none;cursor:pointer;box-shadow:0 4px 20px rgba(0,0,0,.18);display:flex;align-items:center;justify-content:center;transition:transform .2s ease,box-shadow .2s ease;__CUSTOM_CSS__}
#ca-ki-bubble:hover{transform:translateY(-2px);box-shadow:0 8px 28px rgba(0,0,0,.22)}
#ca-ki-bubble:active{transform:scale(.95)}
#ca-ki-bubble svg{width:26px;height:26px}
#ca-ki-panel{position:fixed;bottom:98px;right:__PANEL_RIGHT__;left:__PANEL_LEFT__;z-index:99999;width:400px;max-width:calc(100vw - 24px);height:600px;max-height:calc(100vh - 120px);background:#fff;border-radius:16px;box-shadow:0 20px 50px -10px rgba(0,0,0,.22),0 0 0 1px rgba(0,0,0,.04);display:none;flex-direction:column;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif}
#ca-ki-panel.open{display:flex;animation:ca-ki-in .25s ease}
#ca-ki-header{background:__COLOR__;color:#fff;padding:16px 18px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
#ca-ki-hdr-left{display:flex;align-items:center;gap:10px}
#ca-ki-hdr-icon{width:34px;height:34px;border-radius:10px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center}
#ca-ki-hdr-icon svg{width:18px;height:18px}
#ca-ki-header h3{margin:0;font-size:15px;font-weight:600}
#ca-ki-header p{margin:1px 0 0;font-size:11px;opacity:.75;font-weight:400}
#ca-ki-close{background:none;border:none;color:rgba(255,255,255,.75);cursor:pointer;padding:4px;border-radius:6px;display:flex;transition:color .15s}
#ca-ki-close:hover{color:#fff}
#ca-ki-msgs{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:10px;background:#f8f9fa}
#ca-ki-msgs::-webkit-scrollbar{width:3px}
#ca-ki-msgs::-webkit-scrollbar-thumb{background:#ccc;border-radius:3px}
.ca-ki-msg{max-width:84%;padding:11px 15px;border-radius:14px;font-size:14px;line-height:1.6;word-wrap:break-word;animation:ca-ki-in .2s ease}
.ca-ki-msg a{color:inherit;text-decoration:underline}
.ca-ki-msg code{background:rgba(0,0,0,.06);padding:1px 5px;border-radius:4px;font-size:12.5px;font-family:SFMono-Regular,Consolas,monospace}
.ca-ki-user{align-self:flex-end;background:__COLOR__;color:#fff;border-bottom-right-radius:4px}
.ca-ki-bot{align-self:flex-start;background:#fff;color:#1f2937;border-bottom-left-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.ca-ki-typing{color:#9ca3af}
.ca-ki-dots span{display:inline-block;width:5px;height:5px;border-radius:50%;background:#9ca3af;margin:0 2px;animation:ca-ki-dots 1.4s infinite ease-in-out both}
.ca-ki-dots span:nth-child(1){animation-delay:-.32s}
.ca-ki-dots span:nth-child(2){animation-delay:-.16s}
.ca-ki-chip{display:inline-block;padding:7px 14px;background:#fff;color:#374151;border:1px solid #e5e7eb;border-radius:20px;font-size:13px;cursor:pointer;margin:2px 4px 2px 0;transition:all .15s}
.ca-ki-chip:hover{background:__COLOR__;color:#fff;border-color:__COLOR__}
.ca-ki-err{color:#dc2626;font-size:13px;background:#fef2f2;padding:8px 12px;border-radius:8px;border:1px solid #fecaca}
.ca-ki-src{margin-top:8px;padding-top:8px;border-top:1px solid rgba(0,0,0,.06);font-size:11px;color:#9ca3af}
.ca-ki-src a{color:#6b7280;text-decoration:underline}
.ca-ki-pre{background:#1e1e2e;color:#cdd6f4;padding:10px 12px;border-radius:8px;font-size:12px;overflow-x:auto;margin:4px 0;font-family:SFMono-Regular,Consolas,monospace;white-space:pre-wrap;word-break:break-word}
.ca-ki-heading{display:block;margin:6px 0 2px;font-size:14px;font-weight:700}
.ca-ki-li{display:block;padding-left:6px;margin:2px 0}
.ca-ki-hr{border:none;border-top:1px solid #e5e7eb;margin:8px 0}
.ca-ki-tbl{display:table;width:100%;border-collapse:collapse;margin:8px 0;font-size:13px}
.ca-ki-tbl-row{display:table-row}
.ca-ki-tbl-head{font-weight:700}
.ca-ki-tbl-head .ca-ki-tbl-cell{border-bottom:2px solid #d1d5db;padding-bottom:4px}
.ca-ki-tbl-cell{display:table-cell;padding:3px 8px 3px 0;vertical-align:top;border-bottom:1px solid #f0f0f0}
#ca-ki-input-area{border-top:1px solid #eee;padding:12px 14px;display:flex;gap:8px;flex-shrink:0;background:#fff;align-items:flex-end}
#ca-ki-input{flex:1;border:1px solid #ddd;border-radius:12px;padding:10px 14px;font-size:14px;resize:none;max-height:80px;line-height:1.4;font-family:inherit;outline:none;transition:border-color .15s;background:#fafafa}
#ca-ki-input:focus{border-color:__COLOR__;background:#fff}
#ca-ki-send{width:40px;height:40px;min-width:40px;border-radius:12px;background:__COLOR__;color:#fff;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:opacity .15s}
#ca-ki-send:hover{opacity:.88}
#ca-ki-send:disabled{background:#d1d5db;cursor:not-allowed;opacity:1}
#ca-ki-send svg{width:20px;height:20px}
#ca-ki-footer{padding:5px 14px 7px;text-align:center;flex-shrink:0;background:#fff;border-top:1px solid #f5f5f5}
#ca-ki-footer a{font-size:10px;color:#b0b0b0;text-decoration:none;letter-spacing:.01em}
#ca-ki-footer a:hover{color:#888}
@media(max-width:640px){#ca-ki-panel{bottom:0;right:0;left:0;width:100vw;max-width:100vw;height:100vh;max-height:100vh;border-radius:0}#ca-ki-bubble{bottom:16px;right:16px}}
</style>
<div id="ca-ki-bubble" title="Chat öffnen">
  <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">__ICON_SVG__</svg>
</div>
<div id="ca-ki-panel">
  <div id="ca-ki-header">
    <div id="ca-ki-hdr-left">
      <div id="ca-ki-hdr-icon"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z"/></svg></div>
      <div><h3>__TITLE__</h3><p>Ihr digitaler Assistent</p></div>
    </div>
    <button id="ca-ki-close" title="Schließen"><svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
  </div>
  <div id="ca-ki-msgs"></div>
  <div id="ca-ki-input-area">
    <textarea id="ca-ki-input" rows="1" placeholder="Nachricht eingeben..." maxlength="5000"></textarea>
    <button id="ca-ki-send" title="Senden"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg></button>
  </div>
  <div id="ca-ki-footer"><a href="https://venne-media.de" target="_blank" rel="noopener">Powered by Venne Media</a></div>
</div>
<script>
(function(){
var API='__API_URL__',KEY='__API_KEY__';
var SK='ca_ki_s_'+KEY,VK='ca_ki_v_'+KEY;
var st=localStorage.getItem(SK)||null;
var vid=localStorage.getItem(VK);
if(!vid){vid='v_'+Math.random().toString(36).substr(2)+Date.now().toString(36);localStorage.setItem(VK,vid)}
var bubble=document.getElementById('ca-ki-bubble'),panel=document.getElementById('ca-ki-panel'),msgs=document.getElementById('ca-ki-msgs'),input=document.getElementById('ca-ki-input'),sendBtn=document.getElementById('ca-ki-send'),closeBtn=document.getElementById('ca-ki-close');
var isOpen=false,welcomed=false,sending=false,curBot=null,curTxt='';

function toggle(){isOpen=!isOpen;panel.classList.toggle('open',isOpen);if(isOpen&&!welcomed){welcomed=true;addMsg('bot','__WELCOME__');loadConfig()}}
bubble.onclick=toggle;
closeBtn.onclick=function(){isOpen=false;panel.classList.remove('open')};

function addMsg(role,text){
  var d=document.createElement('div');d.className='ca-ki-msg '+(role==='user'?'ca-ki-user':'ca-ki-bot');
  d.innerHTML=md(text);msgs.appendChild(d);msgs.scrollTop=msgs.scrollHeight;return d}

function addChips(qs){
  if(!qs||!qs.length)return;
  var w=document.createElement('div');w.style.padding='0 0 4px';
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
  if(sending)return;if(!text)text=input.value.trim();if(!text)return;
  input.value='';input.style.height='auto';sending=true;sendBtn.disabled=true;
  addMsg('user',text);
  curBot=addMsg('bot','');curBot.classList.add('ca-ki-typing');curBot.innerHTML='\x3cdiv class="ca-ki-dots">\x3cspan>\x3c/span>\x3cspan>\x3c/span>\x3cspan>\x3c/span>\x3c/div>';curTxt='';

  fetch(API+'/chat',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({message:text,session_token:st,visitor_id:vid})
  }).then(function(r){
    if(!r.ok){
      if(r.status===429){curBot.innerHTML='\x3cspan class="ca-ki-err">Zu viele Anfragen. Bitte warten.\x3c/span>'}
      else{curBot.innerHTML='\x3cspan class="ca-ki-err">Fehler ('+r.status+')\x3c/span>'}
      sending=false;sendBtn.disabled=false;return}
    var reader=r.body.getReader(),dec=new TextDecoder(),buf='';
    curBot.classList.remove('ca-ki-typing');curBot.innerHTML='';

    function read(){
      reader.read().then(function(res){
        if(res.done){sending=false;sendBtn.disabled=false;return}
        buf+=dec.decode(res.value,{stream:true});
        var lines=buf.split('\n');buf=lines.pop()||'';
        lines.forEach(function(line){
          line=line.trim();if(!line.startsWith('data: '))return;
          try{var d=JSON.parse(line.substring(6));
            if(d.type==='token'&&d.content){curTxt+=d.content;curBot.innerHTML=md(curTxt);curBot.style.background='#f3f4f6';msgs.scrollTop=msgs.scrollHeight}
            if(d.type==='start'&&d.session_token){st=d.session_token;localStorage.setItem(SK,st)}
            if(d.type==='done'){if(d.session_token){st=d.session_token;localStorage.setItem(SK,st)}sending=false;sendBtn.disabled=false}
            if(d.error==='limit_reached'){curBot.innerHTML='\x3cspan class="ca-ki-err">Das monatliche Limit wurde erreicht.\x3c/span>';sending=false;sendBtn.disabled=false}
          }catch(e){}});
        read()}).catch(function(){
          if(!curTxt)curBot.innerHTML='\x3cspan class="ca-ki-err">Verbindungsfehler.\x3c/span>';
          sending=false;sendBtn.disabled=false})}
    read()
  }).catch(function(){curBot.innerHTML='\x3cspan class="ca-ki-err">Verbindungsfehler. Bitte versuchen Sie es erneut.\x3c/span>';sending=false;sendBtn.disabled=false})}

sendBtn.onclick=function(){send()};
input.addEventListener('keydown',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();send()}});
input.addEventListener('input',function(){this.style.height='auto';this.style.height=Math.min(this.scrollHeight,80)+'px'});
})();
</script>
WIDGET;

        return str_replace(
            ['__API_URL__', '__API_KEY__', '__COLOR__', '__POS_RIGHT__', '__POS_LEFT__', '__PANEL_RIGHT__', '__PANEL_LEFT__', '__ICON_SVG__', '__TITLE__', '__WELCOME__', '__CUSTOM_CSS__'],
            [$apiUrlJs, $apiKeyJs, $colorCss, $posRight, $posLeft, $panelRight, $panelLeft, $iconSvg, $titleJs, $welcomeJs, $customCss],
            $template,
        );
    }

    private function getCustomCss(): string
    {
        try {
            $settingsJson = Config::get('kiAssistentSettings');
        } catch (\Throwable) {
            return '';
        }

        if (empty($settingsJson)) {
            return '';
        }

        try {
            $settings = json_decode((string) $settingsJson, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '';
        }

        $css = '';
        $customCss = trim($settings['customCss'] ?? '');
        if ($customCss !== '') {
            // Sanitize: only allow safe CSS properties, strip anything dangerous
            $customCss = str_replace(['{', '}', '<', '>', '"', "'"], '', $customCss);
            $css = $customCss;
        }

        return $css;
    }
}
