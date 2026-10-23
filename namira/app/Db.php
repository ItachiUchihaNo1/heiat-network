<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class Db {
    public static function connect(): PDO {
        $dsn = Env::get('DB_DSN');
        if (!$dsn) throw new \RuntimeException('Database not configured');
        $db = new PDO($dsn, Env::get('DB_USER'), Env::get('DB_PASSWORD'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') { $db->exec('PRAGMA foreign_keys=ON'); $db->exec('PRAGMA busy_timeout=5000'); }
        return $db;
    }
    public static function query(PDO $db, string $sql, array $args = []): \PDOStatement { $q=$db->prepare($sql); $q->execute($args); return $q; }
    public static function insert(PDO $db, string $table, array $data): int {
        self::query($db, 'INSERT INTO '.$table.' ('.implode(',',array_keys($data)).') VALUES ('.implode(',',array_fill(0,count($data),'?')).')', array_values($data));
        return (int)$db->lastInsertId();
    }
    public static function transaction(PDO $db, callable $work): mixed {
        $db->beginTransaction();
        try { $r=$work(); $db->commit(); return $r; } catch (\Throwable $e) { if($db->inTransaction()) $db->rollBack(); throw $e; }
    }
    public static function audit(PDO $db, string $action, string $target): void { self::insert($db,'audit_logs',['user_id'=>$_SESSION['user_id']??null,'action'=>$action,'target'=>$target,'created_at'=>date('Y-m-d H:i:s')]); }
}
