<?php

declare(strict_types=1);

namespace BitWasp\Wallet;

use BitWasp\Wallet\DB\DB;

class DbManager
{
    public function createDb(string $dsn) {

    }
    public function loadDb(string $dsn): DB {
        $db = new DB($dsn);
        $db->getPdo()->setAttribute(\PDO::ATTR_ERRMODE,
            \PDO::ERRMODE_EXCEPTION);
        return $db;
    }
}
