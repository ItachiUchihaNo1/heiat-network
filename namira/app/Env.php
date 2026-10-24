<?php
declare(strict_types=1);
namespace Namira;
final class Env {
    public static function load(string $path): void {
        if (!is_file($path)) return;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
            [$key, $value] = explode('=', $line, 2);
            if (preg_match('/^[A-Z_][A-Z0-9_]*$/', $key) && getenv($key) === false) putenv($key . '=' . trim($value, " \t\"'"));
        }
    }
    public static function loadJson(string $path): void {
        if(!is_file($path))return;
        $values=json_decode((string)file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
        if(!is_array($values))throw new \RuntimeException('Invalid private configuration');
        foreach($values as $key=>$value)if(is_string($key)&&preg_match('/^[A-Z_][A-Z0-9_]*$/D',$key)&&is_string($value)&&getenv($key)===false)putenv($key.'='.$value);
    }
    public static function get(string $key, string $default = ''): string { $v = getenv($key); return $v === false ? $default : $v; }
    public static function storage(): string {
        $path = self::get('STORAGE_PATH') ?: BASE_PATH . '/storage';
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) throw new \RuntimeException('Storage unavailable');
        return rtrim($path, '/\\');
    }
}

