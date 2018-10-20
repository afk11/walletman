<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Console\Command;

use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Chain\Params;
use BitWasp\Bitcoin\Math\Math;
use BitWasp\Bitcoin\Network\NetworkFactory;
use BitWasp\Wallet\DbManager;
use BitWasp\Wallet\P2pSyncDaemon;
use BitWasp\Wallet\Params\RegtestParams;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RunWallet extends Command
{
    protected function configure()
    {
        $this
            // the name of the command (the part after "bin/console")
            ->setName('wallet:run')

            // the short description shown while running "php bin/console list"
            ->setDescription('Starts the wallet')

            // the full command description shown when running the command with
            // the "--help" option
            ->setHelp('This command allows you to create a user...');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $loop = \React\EventLoop\Factory::create();
        $dbMgr = new DbManager();
        if (getenv("REGTEST")) {
            $db = $dbMgr->loadDb("sqlite:wallet-regtest.sqlite3");
            $port = 18444;
            $params = new RegtestParams(new Math());
            $net = NetworkFactory::bitcoinRegtest();
        } else {
            $db = $dbMgr->loadDb("sqlite:wallet.sqlite3");
            $port = 8333;
            $params = new Params(new Math());
            $net = NetworkFactory::bitcoin();
        }

        if (getenv("SYNCIP")) {
            $ip = getenv("SYNCIP");
        } else {
            $ip = "127.0.0.1";
        }
        $ecAdapter = Bitcoin::getEcAdapter();
        $daemon = new P2pSyncDaemon($ip, $port, $ecAdapter, $net, $params, $db);
        $daemon->sync($loop);
        $loop->run();
    }
}
