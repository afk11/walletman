<?php

declare(strict_types=1);

namespace BitWasp\Test\Wallet\Console\Command\Db;

use BitWasp\Test\Wallet\TestCase;
use BitWasp\Wallet\Console\Command\Db\Init;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class InitTest extends TestCase
{
    /**
     * @var CommandTester
     */
    private $commandTester;

    /**
     * @var string
     */
    private $sessionDbDir;

    public function setUp()/* The :void return type declaration that should be here would cause a BC issue */
    {
        $application = new Application();
        $application->add(new Init());
        $command = $application->find('db:init');
        $this->commandTester = new CommandTester($command);
        $this->sessionDbDir = sys_get_temp_dir() . "/test.walletman." . bin2hex(random_bytes(4));
        if (file_exists($this->sessionDbDir)) {
            if (file_exists($this->sessionDbDir."/db.sqlite3")) {
                unlink($this->sessionDbDir."/db.sqlite3");
            }
            if (file_exists($this->sessionDbDir."/config.json")) {
                unlink($this->sessionDbDir."/config.json");
            }
            rmdir($this->sessionDbDir);
        }
        parent::setUp();
    }

    public function tearDown()/* The :void return type declaration that should be here would cause a BC issue */
    {
        unset($this->commandTester);
        if (file_exists($this->sessionDbDir)) {
            if (file_exists($this->sessionDbDir."/db.sqlite3")) {
                unlink($this->sessionDbDir."/db.sqlite3");
            }
            if (file_exists($this->sessionDbDir."/config.json")) {
                unlink($this->sessionDbDir."/config.json");
            }
            rmdir($this->sessionDbDir);
        }
        parent::tearDown();
    }

    public function testInitBitcoinTestnet()
    {
        $input = [
            'command'  => "db:init",
            '--datadir' => $this->sessionDbDir,
        ];
        $this->assertEquals(0, $this->commandTester->execute($input));

        // the output of the command in the console
        $output = $this->commandTester->getDisplay();
        $this->assertContains("Initialized bitcoin database: {$this->sessionDbDir}", $output);
        $this->assertFileExists($this->sessionDbDir);
    }
}
