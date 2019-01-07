<?php

declare(strict_types=1);

namespace BitWasp\Test\Wallet;

use BitWasp\Bitcoin\Chain\Params;
use BitWasp\Bitcoin\Math\Math;
use BitWasp\Bitcoin\Network\Networks\Bitcoin;
use BitWasp\Bitcoin\Network\Networks\BitcoinRegtest;
use BitWasp\Bitcoin\Network\Networks\BitcoinTestnet;
use BitWasp\Bitcoin\Network\Slip132\BitcoinRegistry;
use BitWasp\Bitcoin\Network\Slip132\BitcoinTestnetRegistry;
use BitWasp\Wallet\NetworkInfo;
use BitWasp\Wallet\NetworkName;
use BitWasp\Wallet\Params\RegtestParams;
use BitWasp\Wallet\Params\TestnetParams;

class NetworkInfoTest extends TestCase
{
    public function testGetNetwork()
    {
        $config = new NetworkInfo();
        $this->assertInstanceOf(Bitcoin::class, $config->getNetwork(NetworkName::BITCOIN));
        $this->assertInstanceOf(BitcoinTestnet::class, $config->getNetwork(NetworkName::BITCOIN_TESTNET3));
        $this->assertInstanceOf(BitcoinRegtest::class, $config->getNetwork(NetworkName::BITCOIN_REGTEST));
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage unknown network: abc
     */
    public function testUnknownNetwork()
    {
        (new NetworkInfo())->getNetwork("abc");
    }

    public function testP2pPort()
    {
        $config = new NetworkInfo();
        $this->assertEquals(8333, $config->getP2pPort(NetworkName::BITCOIN));
        $this->assertEquals(18333, $config->getP2pPort(NetworkName::BITCOIN_TESTNET3));
        $this->assertEquals(18444, $config->getP2pPort(NetworkName::BITCOIN_REGTEST));
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage unknown network: abc
     */
    public function testUnknownP2pPort()
    {
        (new NetworkInfo())->getP2pPort("abc");
    }

    public function testGetSlip132Registry()
    {
        $config = new NetworkInfo();
        $this->assertInstanceOf(BitcoinRegistry::class, $config->getSlip132Registry(NetworkName::BITCOIN));
        $this->assertInstanceOf(BitcoinTestnetRegistry::class, $config->getSlip132Registry(NetworkName::BITCOIN_TESTNET3));
        $this->assertInstanceOf(BitcoinTestnetRegistry::class, $config->getSlip132Registry(NetworkName::BITCOIN_REGTEST));
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage unknown network: abc
     */
    public function testUnknownSlip132Registry()
    {
        (new NetworkInfo())->getSlip132Registry("abc");
    }

    public function testParams()
    {
        $config = new NetworkInfo();
        $math = new Math();
        $this->assertInstanceOf(Params::class, $config->getParams(NetworkName::BITCOIN, $math));
        $this->assertInstanceOf(TestnetParams::class, $config->getParams(NetworkName::BITCOIN_TESTNET3, $math));
        $this->assertInstanceOf(RegtestParams::class, $config->getParams(NetworkName::BITCOIN_REGTEST, $math));
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage unknown network: abc
     */
    public function testUnknownParams()
    {
        (new NetworkInfo())->getParams("abc", new Math());
    }
}
