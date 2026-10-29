<?php

$pdo = new PDO('mysql:host=127.0.0.1;dbname=eoms_test', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$sqlQualified = 'drop table `eoms_test`.`cache`, `eoms_test`.`cache_locks`';
$sqlPlain = 'drop table `cache`, `cache_locks`';

foreach ([$sqlQualified, $sqlPlain] as $sql) {
    try {
        $pdo->exec($sql);
        echo "OK: {$sql}".PHP_EOL;
    } catch (Throwable $e) {
        echo "FAIL: {$sql}".PHP_EOL;
        echo '  '.$e->getMessage().PHP_EOL;
    }
}

// Recreate emptied tables so we don't leave DB broken for the user more than needed.
$pdo->exec('CREATE TABLE IF NOT EXISTS cache (
  `key` varchar(191) not null primary key,
  `value` mediumtext not null,
  `expiration` bigint not null
) default character set utf8mb4 collate utf8mb4_unicode_ci');
$pdo->exec('CREATE TABLE IF NOT EXISTS cache_locks (
  `key` varchar(191) not null primary key,
  `owner` varchar(191) not null,
  `expiration` bigint not null
) default character set utf8mb4 collate utf8mb4_unicode_ci');
echo "restored stubs".PHP_EOL;
