<?php

declare(strict_types=1);

namespace BitWasp\Test\Wallet;

use BitWasp\Test\Wallet\TestCase;
use BitWasp\Wallet\Config;
use BitWasp\Wallet\NetworkName;

class ConfigTest extends TestCase
{
    public function testGetNetwork()
    {
        $config = new Config(NetworkName::BITCOIN);
        $this->assertEquals(NetworkName::BITCOIN, $config->getNetwork());
    }

    public function testDbPath()
    {
        $config = new Config(NetworkName::BITCOIN);
        $this->assertEquals("/db.sqlite3", $config->getDbPath("/"));
        $this->assertEquals("/home/user/db.sqlite3", $config->getDbPath("/home/user"));
        $this->assertEquals("/home/user/db.sqlite3", $config->getDbPath("/home/user/"));
    }

    public function testFromArray()
    {
        $config = Config::fromArray([
            'network' => NetworkName::BITCOIN,
        ]);
        $this->assertEquals(NetworkName::BITCOIN, $config->getNetwork());
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Config array missing network
     */
    public function testFromArrayRequiresNetwork()
    {
        Config::fromArray([]);
    }
}
