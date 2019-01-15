<?php

declare(strict_types=1);

namespace BitWasp\Test\Wallet;

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

    public function testFromDataDir()
    {
        $tmpDir = sys_get_temp_dir();
        $dataDir = "$tmpDir/testdatdir";
        mkdir($dataDir);
        file_put_contents($dataDir . "/config.json", '{"network":"bitcoin"}');
        try {
            $config = Config::fromDataDir($dataDir);
            $this->assertEquals("bitcoin", $config->getNetwork());
        } finally {
            unlink($dataDir . "/config.json");
            rmdir($dataDir);
        }
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Config file does not exist in directory
     */
    public function testFromDataDirConfigFileShouldExist()
    {
        $tmpDir = sys_get_temp_dir();
        $dataDir = "$tmpDir/testdatdir";
        mkdir($dataDir);

        try {
            Config::fromDataDir($dataDir);
        } finally {
            rmdir($dataDir);
        }
    }


    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Failed to read config file - check permissions
     */
    public function testFromDataDirConfigFileShouldBeReadable()
    {
        $tmpDir = sys_get_temp_dir();
        $dataDir = "$tmpDir/testdatdir";
        mkdir($dataDir);
        mkdir($dataDir . "/config.json");
        try {
            Config::fromDataDir($dataDir);
        } finally {
            rmdir($dataDir . "/config.json");
            rmdir($dataDir);
        }
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage config file contained invalid JSON
     */
    public function testFromDataDirConfigFileShouldContainValidJson()
    {
        $tmpDir = sys_get_temp_dir();
        $dataDir = "$tmpDir/testdatdir";
        mkdir($dataDir);
        file_put_contents($dataDir . "/config.json", "{");
        try {
            Config::fromDataDir($dataDir);
        } finally {
            unlink($dataDir . "/config.json");
            rmdir($dataDir);
        }
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
