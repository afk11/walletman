<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Console\Command\Wallet;

use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Wallet\Console\Command\Command;
use BitWasp\Wallet\DbManager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListWallets extends Command
{
    protected function configure()
    {
        $this
            // the name of the command (the part after "bin/console")
            ->setName('wallet:list')

            // the short description shown while running "php bin/console list"
            ->setDescription('List wallets in database')

            // mandatory arguments
            ->addArgument('database', InputArgument::REQUIRED, "Database for wallet services")

            ->addOption('regtest', 'r', InputOption::VALUE_NONE, "Start wallet in regtest mode")

            // the full command description shown when running the command with
            // the "--help" option
            ->setHelp('This command starts the wallet. Some configuration parameters can be provided as options, overriding default or configuration file values');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dbMgr = new DbManager();
        $db = $dbMgr->loadDb($input->getArgument("database"));

        $ecAdapter = Bitcoin::getEcAdapter();
        $wallets = $db->loadAllWallets();
        foreach ($wallets as $wallet) {
            echo "{$wallet->getIdentifier()}\n";
        }
    }
}
