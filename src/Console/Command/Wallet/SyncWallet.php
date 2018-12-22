<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Console\Command\Wallet;

use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Chain\Params;
use BitWasp\Bitcoin\Chain\ProofOfWork;
use BitWasp\Bitcoin\Crypto\Random\Random;
use BitWasp\Bitcoin\Math\Math;
use BitWasp\Bitcoin\Network\NetworkFactory;
use BitWasp\Wallet\Chain;
use BitWasp\Wallet\Console\Command\Command;
use BitWasp\Wallet\DbManager;
use BitWasp\Wallet\P2pSyncDaemon;
use BitWasp\Wallet\Params\RegtestParams;
use BitWasp\Wallet\Params\TestnetParams;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SyncWallet extends Command
{
    protected function configure()
    {
        $this
            // the name of the command (the part after "bin/console")
            ->setName('wallet:sync')

            // the short description shown while running "php bin/console list"
            ->setDescription('Synchronize the wallet against a trusted node')

            // mandatory arguments
            ->addArgument('database', InputArgument::REQUIRED, "Database for wallet services")

            ->addOption('ip', null, InputOption::VALUE_REQUIRED, "Provide a trusted nodes hostname", "127.0.0.1")
            ->addOption('regtest', 'r', InputOption::VALUE_NONE, "Start wallet in regtest mode")
            ->addOption('testnet', 't', InputOption::VALUE_NONE, "Start wallet in testnet mode")

            // the full command description shown when running the command with
            // the "--help" option
            ->setHelp('This command starts the wallet. Some configuration parameters can be provided as options, overriding default or configuration file values');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $fIsRegtest = $input->getOption('regtest');
        $fIsTestnet = $input->getOption('testnet');
        $ip = $this->getStringOption($input, 'ip');
        $path = $this->getStringArgument($input, "database");

        $dbMgr = new DbManager();
        $db = $dbMgr->loadDb($path);

        $ecAdapter = Bitcoin::getEcAdapter();
        $random = new Random();
        $loop = \React\EventLoop\Factory::create();
        if ($fIsRegtest) {
            $port = 18444;
            $params = new RegtestParams(new Math());
            $net = NetworkFactory::bitcoinRegtest();
        } else if ($fIsTestnet) {
            $port = 18333;
            $params = new TestnetParams(new Math());
            $net = NetworkFactory::bitcoinTestnet();
        } else {
            $port = 8333;
            $params = new Params(new Math());
            $net = NetworkFactory::bitcoin();
        }

        $pow = new ProofOfWork(new Math(), $params);
        $chain = new Chain($pow);
        $daemon = new P2pSyncDaemon($ip, $port, $ecAdapter, $net, $params, $db, $random, $chain);
        $daemon->init();
        $daemon->sync($loop);
        $loop->run();
    }
}
