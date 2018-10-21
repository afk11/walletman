<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Console\Command\Wallet;

use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Network\NetworkFactory;
use BitWasp\Wallet\Console\Command\Command;
use BitWasp\Wallet\DB\DbWalletTx;
use BitWasp\Wallet\DbManager;
use BitWasp\Wallet\Wallet\Factory;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListTransactions extends Command
{
    protected function configure()
    {
        $this
            // the name of the command (the part after "bin/console")
            ->setName('wallet:listtransactions')

            // the short description shown while running "php bin/console list"
            ->setDescription('List transactions in database')

            // mandatory arguments
            ->addArgument('database', InputArgument::REQUIRED, "Database for wallet services")
            ->addArgument('identifier', InputArgument::REQUIRED, "Wallet identifier")

            ->addOption('regtest', 'r', InputOption::VALUE_NONE, "Initialize wallet for regtest network")
            ->addOption('testnet', 't', InputOption::VALUE_NONE, "Initialize wallet for testnet network")

            // the full command description shown when running the command with
            // the "--help" option
            ->setHelp('Lists transactions');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dbMgr = new DbManager();
        $db = $dbMgr->loadDb($this->getStringArgument($input, "database"));

        if ($input->getOption('regtest')) {
            $net = NetworkFactory::bitcoinRegtest();
        } else if ($input->getOption('testnet')) {
            $net = NetworkFactory::bitcoinTestnet();
        } else {
            $net = NetworkFactory::bitcoin();
        }

        $ec = Bitcoin::getEcAdapter();
        $factory = new Factory($db, $net, $ec);
        $wallet = $factory->loadWallet($input->getArgument("identifier"));
        $stmt = $db->getTransactions($wallet->getDbWallet()->getId());
        while ($row = $stmt->fetchObject(DbWalletTx::class)) {
            /** @var DbWalletTx $row */
            echo $row->getTxId()->getHex() . "    value: {$row->getValueChange()}\n";
        }
    }
}
