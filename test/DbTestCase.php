<?php

declare(strict_types=1);

namespace BitWasp\Test\Wallet;

use BitWasp\Bitcoin\Chain\ParamsInterface;
use BitWasp\Bitcoin\Key\Deterministic\Slip132\PrefixRegistry;
use BitWasp\Bitcoin\Math\Math;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Wallet\DB\DBInterface;
use BitWasp\Wallet\DB\Initializer;
use BitWasp\Wallet\NetworkInfo;
use BitWasp\Wallet\NetworkName;

abstract class DbTestCase extends TestCase
{
    const FILE_DEFAULT = "walletman-test.sqlite";

    /**
     * @var bool
     */
    protected $regtest = false;

    /**
     * @var bool
     */
    protected $testnet = false;

    /**
     * Can be overridden to force the tests filename
     * @var null|string
     */
    protected $overrideTestFile;

    /**
     * @var ParamsInterface
     */
    protected $sessionChainParams;

    /**
     * @var NetworkInterface
     */
    protected $sessionNetwork;

    /**
     * @var string
     */
    protected $sessionNetworkName;

    /**
     * @var PrefixRegistry
     */
    protected $sessionPrefixRegistry;

    /**
     * @var string
     */
    protected $sessionDataDir;

    /**
     * @var DBInterface
     */
    protected $sessionDb;

    public function setUp()
    {
        $this->sessionDataDir = sys_get_temp_dir() . "/test.walletman." . bin2hex(random_bytes(4));
        if (file_exists($this->sessionDataDir)) {
            if (file_exists($this->sessionDataDir."/config.json")) {
                unlink($this->sessionDataDir."/config.json");
            }
            if (file_exists($this->sessionDataDir."/db.sqlite3")) {
                unlink($this->sessionDataDir."/db.sqlite3");
            }
        }
        mkdir($this->sessionDataDir);

        $netInfo = new NetworkInfo();
        if ($this->regtest) {
            $netName = NetworkName::BITCOIN_REGTEST;
        } else if ($this->testnet) {
            $netName = NetworkName::BITCOIN_TESTNET3;
        } else {
            $netName = NetworkName::BITCOIN;
        }

        $this->sessionChainParams = $netInfo->getParams($netName, new Math());
        $this->sessionNetworkName = $netName;
        $this->sessionNetwork = $netInfo->getNetwork($netName);
        $this->sessionPrefixRegistry = $netInfo->getSlip132Registry($netName);
        $initializer = new Initializer();
        $this->sessionDb = $initializer->setupDb($this->sessionDataDir);
        parent::setUp();
    }

    public function tearDown()
    {
        if (file_exists($this->sessionDataDir)) {
            if (file_exists($this->sessionDataDir."/config.json")) {
                unlink($this->sessionDataDir."/config.json");
            }
            if (file_exists($this->sessionDataDir."/db.sqlite3")) {
                unlink($this->sessionDataDir."/db.sqlite3");
            }
            rmdir($this->sessionDataDir);
        }
        parent::tearDown();
    }
}
