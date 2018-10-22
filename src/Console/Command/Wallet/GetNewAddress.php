<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Console\Command\Wallet;

use BitWasp\Bitcoin\Address\AddressCreator;
use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Network\NetworkFactory;
use BitWasp\Wallet\Console\Command\Command;
use BitWasp\Wallet\DbManager;
use BitWasp\Wallet\Wallet\Factory;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GetNewAddress extends Command
{
    protected function configure()
    {
        $this
            // the name of the command (the part after "bin/console")
            ->setName('wallet:getnewaddress')
            // the short description shown while running "php bin/console list"
            ->setDescription('Create a new wallet')

            // An identifier is required for this wallet
            ->addArgument("database", InputArgument::REQUIRED, "Database to use for wallet")
            ->addArgument("identifier", InputArgument::REQUIRED, "Identifier for wallet")

            ->addOption('regtest', 'r', InputOption::VALUE_NONE, "Initialize wallet for regtest network")
            ->addOption('testnet', 't', InputOption::VALUE_NONE, "Initialize wallet for testnet network")

            // the full command description shown when running the command with
            // the "--help" option
            ->setHelp('');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $path = $this->getStringArgument($input, "database");
        $identifier = $this->getStringArgument($input, "identifier");
        $fIsRegtest = $input->getOption('regtest');
        $fIsTestnet = $input->getOption('testnet');

        $ecAdapter = Bitcoin::getEcAdapter();
        $dbMgr = new DbManager();

        if ($fIsRegtest) {
            $net = NetworkFactory::bitcoinRegtest();
        } else if ($fIsTestnet) {
            $net = NetworkFactory::bitcoinTestnet();
        } else {
            $net = NetworkFactory::bitcoin();
        }

        $db = $dbMgr->loadDb($path);
        $walletFactory = new Factory($db, $net, $ecAdapter);
        $wallet = $walletFactory->loadWallet($identifier);
        $addrGen = $wallet->getScriptGenerator();
        $dbScript = $addrGen->generate();
        $addrString = $dbScript->getAddress(new AddressCreator())->getAddress($net);
        echo "$addrString\n";
    }
}
