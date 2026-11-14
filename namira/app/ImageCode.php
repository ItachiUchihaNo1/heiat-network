<?php
declare(strict_types=1);
namespace Namira;
/** The database allocates IDs atomically; formatting never renumbers existing media. */
final class ImageCode {
 public static function format(int $id):string {if($id<1)throw new \InvalidArgumentException('Invalid image ID');return str_pad((string)$id,6,'0',STR_PAD_LEFT);}
}
