<?php

declare(strict_types=1);

namespace BitWasp\Test\DB;

use BitWasp\Test\Wallet\TestCase;
use BitWasp\Wallet\DB\Initializer;
use BitWasp\Wallet\DbManager;

class InitializerTest extends TestCase
{
    /**
     * @var string
     */
    private $sessionDbDir;

    public function setUp()/* The :void return type declaration that should be here would cause a BC issue */
    {
        $this->sessionDbDir = sys_get_temp_dir() . "/test.walletman." . bin2hex(random_bytes(4));
        if (file_exists($this->sessionDbDir)) {
            if (file_exists($this->sessionDbDir."/db.sqlite3")) {
                unlink($this->sessionDbDir."/db.sqlite3");
            }
        }
        mkdir($this->sessionDbDir);
        parent::setUp();
    }

    public function tearDown()/* The :void return type declaration that should be here would cause a BC issue */
    {
        if (file_exists($this->sessionDbDir)) {
            if (file_exists($this->sessionDbDir."/db.sqlite3")) {
                unlink($this->sessionDbDir."/db.sqlite3");
            }
        }
        parent::tearDown();
    }

    private function assertTableExists(\PDO $pdo, string $table)
    {
        $checkTableStmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
        $checkTableStmt->execute([
            $table,
        ]);
        $result = $checkTableStmt->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $result, "should find table");
        $this->assertEquals($table, $result[0]["name"], "table name should match expected value");
    }

    public function testInitializer()
    {
        $initializer = new Initializer();
        $initializer->setupDb($this->sessionDbDir);

        // the output of the command in the console
        $dbMan = new DbManager();
        $db = $dbMan->loadDb($this->sessionDbDir."/db.sqlite3");
        $pdo = $db->getPdo();
        $this->assertTableExists($pdo, "header");
        $this->assertTableExists($pdo, "wallet");
        $this->assertTableExists($pdo, "key");
        $this->assertTableExists($pdo, "script");
        $this->assertTableExists($pdo, "tx");
        $this->assertTableExists($pdo, "utxo");
    }
}
