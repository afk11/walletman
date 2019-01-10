<?php

declare(strict_types=1);

namespace BitWasp\Test\Wallet\Params;

use BitWasp\Bitcoin\Math\Math;
use BitWasp\Test\Wallet\TestCase;
use BitWasp\Wallet\Params\RegtestParams;

class RegtestParamsTest extends TestCase
{
    public function testGenesisBlock()
    {
        $regtest = new RegtestParams(new Math());
        $this->assertEquals("0f9188f13cb7b2c71f2a335e3a4fc328bf5beb436012afca590b1a11466e2206", $regtest->getGenesisBlockHeader()->getHash()->getHex());
        $this->assertEquals("0f9188f13cb7b2c71f2a335e3a4fc328bf5beb436012afca590b1a11466e2206", $regtest->getGenesisBlock()->getHeader()->getHash()->getHex());
    }
}
