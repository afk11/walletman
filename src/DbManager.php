<?php

declare(strict_types=1);

namespace BitWasp\Wallet;

use BitWasp\Wallet\DB\DB;

class DbManager
{
    public function createDb(string $dsn)
    {
    }
    public function loadDb(string $path): DB
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("database file does not exist, initialize first");
        }
        $db = new DB("sqlite:$path");
        $db->getPdo()->setAttribute(
            \PDO::ATTR_ERRMODE,
            \PDO::ERRMODE_EXCEPTION
        );
        return $db;
    }
}
