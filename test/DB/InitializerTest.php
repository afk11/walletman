<?php

declare(strict_types=1);

namespace BitWasp\Test\DB;

use BitWasp\Bitcoin\Chain\Params;
use BitWasp\Bitcoin\Chain\ParamsInterface;
use BitWasp\Bitcoin\Math\Math;
use BitWasp\Test\Wallet\TestCase;
use BitWasp\Wallet\DB\DbHeader;
use BitWasp\Wallet\DB\Initializer;
use BitWasp\Wallet\DbManager;
use BitWasp\Wallet\Params\RegtestParams;
use BitWasp\Wallet\Params\TestnetParams;

class InitializerTest extends TestCase
{
    /**
     * @var string
     */
    private $sessionDbFile;

    public function setUp()/* The :void return type declaration that should be here would cause a BC issue */
    {
        $this->sessionDbFile = sys_get_temp_dir()."/walletman-unittest-dbinit.sqlite";
        if (file_exists($this->sessionDbFile)) {
            unlink($this->sessionDbFile);
        }
        parent::setUp();
    }

    public function tearDown()/* The :void return type declaration that should be here would cause a BC issue */
    {
        if (file_exists($this->sessionDbFile)) {
            unlink($this->sessionDbFile);
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

    public function getParams(): array
    {
        $math = new Math();
        $mainnet = new Params($math);
        $testnet = new TestnetParams($math);
        $regtest = new RegtestParams($math);

        return [
            [$mainnet],
            [$testnet],
            [$regtest],
        ];
    }

    /**
     * @dataProvider getParams
     * @param ParamsInterface $params
     */
    public function testInitializer(ParamsInterface $params)
    {
        $initializer = new Initializer();
        $db = $initializer->setup($this->sessionDbFile, $params);

        // the output of the command in the console
        $dbMan = new DbManager();
        $db = $dbMan->loadDb($this->sessionDbFile);
        $pdo = $db->getPdo();
        $this->assertTableExists($pdo, "header");
        $this->assertTableExists($pdo, "wallet");
        $this->assertTableExists($pdo, "key");
        $this->assertTableExists($pdo, "script");
        $this->assertTableExists($pdo, "tx");
        $this->assertTableExists($pdo, "utxo");

        $header = $db->getHeader($params->getGenesisBlockHeader()->getHash());
        $this->assertInstanceOf(DbHeader::class, $header);
    }
}
