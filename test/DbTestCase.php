<?php

declare(strict_types=1);

namespace BitWasp\Test\Wallet;

use BitWasp\Bitcoin\Chain\Params;
use BitWasp\Bitcoin\Chain\ParamsInterface;
use BitWasp\Bitcoin\Math\Math;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Bitcoin\Network\Networks\Bitcoin;
use BitWasp\Bitcoin\Network\Networks\BitcoinRegtest;
use BitWasp\Bitcoin\Network\Networks\BitcoinTestnet;
use BitWasp\Wallet\DB\DB;
use BitWasp\Wallet\DB\Initializer;
use BitWasp\Wallet\Params\RegtestParams;
use BitWasp\Wallet\Params\TestnetParams;

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
    protected $sessionDbFile;

    /**
     * @var DB
     */
    protected $sessionDb;

    public function setUp()
    {
        $this->sessionDbFile = sprintf("%s/%s", sys_get_temp_dir(), $this->overrideTestFile ?: self::FILE_DEFAULT);
        if (file_exists($this->sessionDbFile)) {
            unlink($this->sessionDbFile);
        }
        if ($this->regtest) {
            $this->sessionChainParams = new RegtestParams(new Math());
            $this->sessionNetwork = new BitcoinRegtest();
        } else if ($this->testnet) {
            $this->sessionChainParams = new TestnetParams(new Math());
            $this->sessionNetwork = new BitcoinTestnet();
        } else {
            $this->sessionChainParams = new Params(new Math());
            $this->sessionNetwork = new Bitcoin();
        }

        $initializer = new Initializer();
        $this->sessionDb = $initializer->setup($this->sessionDbFile, $this->sessionChainParams);
        parent::setUp();
    }

    public function tearDown()
    {
        if (file_exists($this->sessionDbFile)) {
            unlink($this->sessionDbFile);
        }
        parent::tearDown();
    }
}
