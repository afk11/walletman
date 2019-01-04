<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Console\Command\Wallet;

use BitWasp\Wallet\Config;
use BitWasp\Wallet\Console\Command\Command;
use BitWasp\Wallet\DbManager;
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

            ->addOption("datadir", "d", InputOption::VALUE_REQUIRED, 'Data directory, defaults to $HOME/.walletman')

            // the full command description shown when running the command with
            // the "--help" option
            ->setHelp('This command displays all wallet identifiers stored in the database');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dataDir = $this->loadDataDir($input);

        $dbMgr = new DbManager();
        $config = Config::fromDataDir($dataDir);
        $db = $dbMgr->loadDb($config->getDbPath($dataDir));

        $wallets = $db->loadAllWallets();
        foreach ($wallets as $wallet) {
            echo "{$wallet->getIdentifier()}\n";
        }
    }
}
