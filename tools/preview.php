<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCP { final class Util { public static function addScript(string $app, string $name): void {} public static function addStyle(string $app, string $name): void {} } }
namespace {
    require __DIR__ . '/../vereinsflieger_login/lib/Service/LoginProtection.php';
    $translations = json_decode(file_get_contents(__DIR__ . '/../vereinsflieger_login/l10n/de.json'), true)['translations'];
    $l = new class($translations) {
        public function __construct(private array $translations) {}
        public function t(string $text, array $parameters = []): string { return vsprintf($this->translations[$text] ?? $text, $parameters); }
    };
    function p(mixed $value): void { echo htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    function print_unescaped(string $value): void { echo $value; }
    $_ = ['settings' => ['enabled' => false, 'cid' => '', 'hasKey' => false, 'syncRoles' => false,
        'links' => [], 'roleMap' => [], 'snapshots' => [], 'syncWarnings' => [], 'knownRoles' => ['Mitglied', 'Technisches Personal'], 'dailyApiBudget' => 400,
        'defaultLogin' => false, 'createAccounts' => false, 'excludedAccounts' => [], 'provisionedLinks' => [], 'showLocalLoginLink' => true, 'linkExistingByEmail' => false, 'rememberMeDefault' => true] + \OCA\VereinsfliegerLogin\Service\LoginProtection::DEFAULTS, 'groups' => ['Mitglieder', 'Technik', 'Startschreiber'], 'missingGroups' => [],
        'accounts' => ['admin' => 'Lokaler Administrator', 'alice' => 'Alice Beispiel'], 'localLoginUrl' => '/login?direct=1',
        'usage' => ['dateUtc' => '2026-10-07', 'used' => 28, 'providerPaused' => false, 'hourUtc' => '2026-10-07 12:00', 'hourUsed' => 8, 'providerPauseRemaining' => 0],
        'listUrl' => '/demo/list', 'pauses' => [], 'pauseUrl' => '/demo/unpause', 'saveUrl' => '/demo/save', 'checkUrl' => '/demo/check'];
    echo '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Vereinsflieger – Adminvorschau</title><style>';
    echo ':root{--color-main-text:#182536;--color-primary-element:#007db5;--color-border:#dee5ec;--color-text-maxcontrast:#586779;--color-background-dark:#f1f5f8;--color-main-background:#fff}*{box-sizing:border-box}body{margin:0;background:#f5f7fa;font-family:system-ui,sans-serif}h2,h3,h4,p{margin:0}button,input,select{font:inherit}input,select{padding:11px 12px;border:1px solid #cad4df;border-radius:8px;background:white}button{padding:11px 16px;border:1px solid #cad4df;border-radius:8px;background:#fff;cursor:pointer}button.primary{background:#007db5;color:#fff;border-color:#007db5}a{color:#007db5;text-decoration:none}.vf-admin{margin:auto}.demo-banner{background:#20364b;color:#fff;padding:12px 32px;text-align:center;font-size:13px}';
    echo file_get_contents(__DIR__ . '/../vereinsflieger_login/css/style.css');
    echo '</style><body><div class="demo-banner">Offline-Vorschau · Beispieldaten · keine Verbindung zu Nextcloud oder Vereinsflieger</div>';
    require __DIR__ . '/../vereinsflieger_login/templates/admin.php';
    echo '<script>window.OC={requestToken:"offline-demo",L10N:{register:(app,strings)=>{window.__translations=strings;},translate:(app,text,args=[])=>{let i=0;return (window.__translations[text]||text).replace(/%s/g,()=>args[i++]);}}};window.__vfRequests=[];window.fetch=async(url,options)=>{if(url==="/demo/list")return {ok:true,json:async()=>({items:[],page:JSON.parse(options.body).page,hasMore:false})};window.__vfRequests.push({url,body:JSON.parse(options.body)});return {ok:true,json:async()=>({message:"Offline-Vorschau: keine Einstellungen gespeichert und keine API aufgerufen."})};};</script><script>';
    echo file_get_contents(__DIR__ . '/../vereinsflieger_login/l10n/de.js');
    echo file_get_contents(__DIR__ . '/../vereinsflieger_login/js/admin.js');
    echo '</script></body></html>';
}
