<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
spl_autoload_register(function(string $class): void {
    if (str_starts_with($class, 'Namira\\')) {
        $path = __DIR__ . '/' . substr($class, 7) . '.php';
        if (is_file($path)) require $path;
    }
});
if (!defined('NAMIRA_SHARED_PATH') && basename(dirname(BASE_PATH)) === 'releases') {
    $shared = dirname(BASE_PATH, 2);
    if (is_file($shared.'/.namira-shared-root') && trim((string)file_get_contents($shared.'/.namira-shared-root')) === 'namira-private-shared-v1') define('NAMIRA_SHARED_PATH', $shared);
}
Namira\Env::load(Namira\Env::privateRoot() . '/.env');
Namira\Env::loadJson(Namira\Env::privateRoot() . '/config.local.json');
date_default_timezone_set('Asia/Tehran');
function e(mixed $v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $page = 'home', array $params = []): string { return '/index.php?' . http_build_query(['page' => $page] + $params); }
function redirect(string $page, array $params = []): never { header('Location: ' . url($page, $params), true, 303); exit; }
function fa(mixed $v): string { return strtr((string)$v, array_combine(str_split('0123456789'), ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'])); }
function view(string $name, array $data = []): void { extract($data, EXTR_SKIP); require BASE_PATH . '/views/' . $name . '.php'; }

