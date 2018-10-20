<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Console\Command\Wallet;

use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Network\NetworkFactory;
use BitWasp\Wallet\Console\Command\Command;
use BitWasp\Wallet\DbManager;
use BitWasp\Wallet\Wallet\Factory;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GetBalance extends Command
{
    protected function configure()
    {
        $this
            // the name of the command (the part after "bin/console")
            ->setName('wallet:getbalance')

            // the short description shown while running "php bin/console list"
            ->setDescription('Query balance of the specified wallet')

            // mandatory arguments
            ->addArgument('database', InputArgument::REQUIRED, "Database for wallet services")
            ->addArgument('identifier', InputArgument::REQUIRED, "Search for wallet by identifier")

            ->addOption('regtest', 'r', InputOption::VALUE_NONE, "Start wallet in regtest mode")

            // the full command description shown when running the command with
            // the "--help" option
            ->setHelp('This command returns the wallets balance');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $path = $input->getArgument('database');
        $identifier = $input->getArgument('identifier');
        $fIsRegtest = (bool) $input->getOption('regtest');

        $dbMgr = new DbManager();
        if ($fIsRegtest) {
            $net = NetworkFactory::bitcoinRegtest();
        } else {
            $net = NetworkFactory::bitcoin();
        }

        $db = $dbMgr->loadDb($path);
        $ecAdapter = Bitcoin::getEcAdapter();
        $factory = new Factory($db, $net, $ecAdapter);
        $wallet = $factory->loadWallet($identifier);
        $balance = $wallet->getConfirmedBalance();

        echo "Confirmed balance: $balance\n";
    }
}
