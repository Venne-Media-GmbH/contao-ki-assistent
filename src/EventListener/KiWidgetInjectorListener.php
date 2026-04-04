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
        $userAlign = 'flex-end';
        $botAlign = 'flex-start';

        $iconSvg = self::ICONS[$bubbleIcon] ?? self::ICONS['chat'];

        return <<<HTML
<!-- Contao KI Assistent Widget -->
<style>
#ca-ki-bubble{position:fixed;bottom:24px;right:{$posRight};left:{$posLeft};z-index:99999;width:56px;height:56px;border-radius:50%;background:{$colorCss};color:#fff;border:none;cursor:pointer;box-shadow:0 4px 20px rgba(0,0,0,.2);display:flex;align-items:center;justify-content:center;transition:transform .2s,box-shadow .2s}
#ca-ki-bubble:hover{transform:scale(1.08);box-shadow:0 6px 28px rgba(0,0,0,.25)}
#ca-ki-bubble svg{width:26px;height:26px}
#ca-ki-panel{position:fixed;bottom:92px;right:{$panelRight};left:{$panelLeft};z-index:99999;width:400px;max-width:calc(100vw - 32px);height:600px;max-height:calc(100vh - 120px);background:#fff;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.2);display:none;flex-direction:column;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif}
#ca-ki-panel.open{display:flex}
#ca-ki-header{background:linear-gradient(145deg,{$colorCss},#1f2937);color:#fff;padding:16px 20px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
#ca-ki-header h3{margin:0;font-size:15px;font-weight:700}
#ca-ki-close{background:none;border:none;color:rgba(255,255,255,.7);cursor:pointer;padding:4px;border-radius:6px;display:flex}
#ca-ki-close:hover{color:#fff;background:rgba(255,255,255,.15)}
#ca-ki-msgs{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:12px}
.ca-ki-msg{max-width:85%;padding:10px 14px;border-radius:12px;font-size:14px;line-height:1.55;word-wrap:break-word}
.ca-ki-msg a{color:inherit;text-decoration:underline}
.ca-ki-msg code{background:rgba(0,0,0,.06);padding:1px 5px;border-radius:4px;font-size:12px}
.ca-ki-user{align-self:{$userAlign};background:{$colorCss};color:#fff;border-bottom-right-radius:4px}
.ca-ki-bot{align-self:{$botAlign};background:#f3f4f6;color:#111;border-bottom-left-radius:4px}
.ca-ki-typing{font-style:italic;color:#888}
.ca-ki-chip{display:inline-block;padding:6px 14px;background:#f0fdf4;color:#059669;border:1px solid #bbf7d0;border-radius:20px;font-size:12px;cursor:pointer;margin:2px 4px 2px 0;transition:background .15s}
.ca-ki-chip:hover{background:#dcfce7}
.ca-ki-err{color:#dc2626;font-size:13px}
#ca-ki-input-area{border-top:1px solid #e5e7eb;padding:12px 16px;display:flex;gap:8px;flex-shrink:0;background:#fafafa}
#ca-ki-input{flex:1;border:1px solid #d1d5db;border-radius:10px;padding:10px 14px;font-size:14px;resize:none;max-height:80px;line-height:1.4;font-family:inherit;outline:none;transition:border-color .15s}
#ca-ki-input:focus{border-color:{$colorCss};box-shadow:0 0 0 3px {$colorCss}20}
#ca-ki-send{width:40px;height:40px;border-radius:10px;background:{$colorCss};color:#fff;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .15s}
#ca-ki-send:hover{opacity:.85}
#ca-ki-send:disabled{background:#d1d5db;cursor:not-allowed;opacity:1}
@media(max-width:640px){#ca-ki-panel{bottom:0;right:0;left:0;width:100vw;max-width:100vw;height:100vh;max-height:100vh;border-radius:0}#ca-ki-bubble{bottom:16px}}
</style>
<div id="ca-ki-bubble" title="Chat öffnen">
  <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">{$iconSvg}</svg>
</div>
<div id="ca-ki-panel">
  <div id="ca-ki-header">
    <h3>{$titleJs}</h3>
    <button id="ca-ki-close" title="Schließen"><svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
  </div>
  <div id="ca-ki-msgs"></div>
  <div id="ca-ki-input-area">
    <textarea id="ca-ki-input" rows="1" placeholder="Nachricht eingeben..." maxlength="5000"></textarea>
    <button id="ca-ki-send" title="Senden"><svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/></svg></button>
  </div>
</div>
<script>
(function(){
var API='{$apiUrlJs}',KEY='{$apiKeyJs}';
var SK='ca_ki_s_'+KEY,VK='ca_ki_v_'+KEY;
var st=localStorage.getItem(SK)||null;
var vid=localStorage.getItem(VK);
if(!vid){vid='v_'+Math.random().toString(36).substr(2)+Date.now().toString(36);localStorage.setItem(VK,vid)}
var bubble=document.getElementById('ca-ki-bubble'),panel=document.getElementById('ca-ki-panel'),msgs=document.getElementById('ca-ki-msgs'),input=document.getElementById('ca-ki-input'),sendBtn=document.getElementById('ca-ki-send'),closeBtn=document.getElementById('ca-ki-close');
var isOpen=false,welcomed=false,sending=false,curBot=null,curTxt='';

function toggle(){isOpen=!isOpen;panel.classList.toggle('open',isOpen);if(isOpen&&!welcomed){welcomed=true;addMsg('bot','{$welcomeJs}');loadConfig()}}
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
  t=t.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  t=t.replace(/\*\*(.+?)\*\*/g,'<strong>\$1</strong>');
  t=t.replace(/\*(.+?)\*/g,'<em>\$1</em>');
  t=t.replace(/`([^`]+)`/g,'<code>\$1</code>');
  t=t.replace(/\[([^\]]+)\]\(([^)]+)\)/g,'<a href="\$2" target="_blank" rel="noopener">\$1</a>');
  t=t.replace(/\n/g,'<br>');return t}

function loadConfig(){
  fetch(API+'/config').then(function(r){return r.json()}).then(function(d){
    if(d.suggestedQuestions&&d.suggestedQuestions.length)addChips(d.suggestedQuestions);
  }).catch(function(){});}

function send(text){
  if(sending)return;if(!text)text=input.value.trim();if(!text)return;
  input.value='';input.style.height='auto';sending=true;sendBtn.disabled=true;
  addMsg('user',text);
  curBot=addMsg('bot','');curBot.classList.add('ca-ki-typing');curBot.innerHTML='<em>Denke nach...</em>';curTxt='';

  fetch(API+'/chat',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({message:text,session_token:st,visitor_id:vid})
  }).then(function(r){
    if(!r.ok){
      if(r.status===429){curBot.innerHTML='<span class="ca-ki-err">Zu viele Anfragen. Bitte warten.</span>'}
      else{curBot.innerHTML='<span class="ca-ki-err">Fehler ('+r.status+')</span>'}
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
            if(d.error==='limit_reached'){curBot.innerHTML='<span class="ca-ki-err">Das monatliche Limit wurde erreicht.</span>';sending=false;sendBtn.disabled=false}
          }catch(e){}});
        read()}).catch(function(){
          if(!curTxt)curBot.innerHTML='<span class="ca-ki-err">Verbindungsfehler.</span>';
          sending=false;sendBtn.disabled=false})}
    read()
  }).catch(function(){curBot.innerHTML='<span class="ca-ki-err">Verbindungsfehler. Bitte versuchen Sie es erneut.</span>';sending=false;sendBtn.disabled=false})}

sendBtn.onclick=function(){send()};
input.addEventListener('keydown',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();send()}});
input.addEventListener('input',function(){this.style.height='auto';this.style.height=Math.min(this.scrollHeight,80)+'px'});
})();
</script>
HTML;
    }
}
