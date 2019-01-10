<?php

declare(strict_types=1);

namespace BitWasp\Test\Wallet\Params;

use BitWasp\Bitcoin\Math\Math;
use BitWasp\Test\Wallet\TestCase;
use BitWasp\Wallet\Params\TestnetParams;

class TestnetParamsTest extends TestCase
{
    public function testGenesisBlock()
    {
        $regtest = new TestnetParams(new Math());
        $this->assertEquals("000000000933ea01ad0ee984209779baaec3ced90fa3f408719526f8d77f4943", $regtest->getGenesisBlockHeader()->getHash()->getHex());
        $this->assertEquals("000000000933ea01ad0ee984209779baaec3ced90fa3f408719526f8d77f4943", $regtest->getGenesisBlock()->getHeader()->getHash()->getHex());
    }
}
