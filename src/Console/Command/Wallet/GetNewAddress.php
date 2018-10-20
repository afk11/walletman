<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Console\Command\Wallet;

use BitWasp\Bitcoin\Address\AddressCreator;
use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Chain\Params;
use BitWasp\Bitcoin\Math\Math;
use BitWasp\Bitcoin\Network\NetworkFactory;
use BitWasp\Wallet\Console\Command\Command;
use BitWasp\Wallet\DbManager;
use BitWasp\Wallet\Params\RegtestParams;
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

            // the full command description shown when running the command with
            // the "--help" option
            ->setHelp('');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $path = $input->getArgument('database');
        $identifier = $input->getArgument('identifier');
        $fIsRegtest = (bool) $input->getOption('regtest');

        if ($fIsRegtest) {
            $params = new RegtestParams(new Math());
            $net = NetworkFactory::bitcoinRegtest();
        } else {
            $params = new Params(new Math());
            $net = NetworkFactory::bitcoin();
        }
        $ecAdapter = Bitcoin::getEcAdapter();
        $dbMgr = new DbManager();
        $db = $dbMgr->loadDb($path);
        $walletFactory = new Factory($db, $net, $ecAdapter);

        $wallet = $walletFactory->loadWallet($identifier);
        $addrGen = $wallet->getScriptGenerator();
        $dbScript = $addrGen->generate();
        $addrString = $dbScript->getAddress(new AddressCreator())->getAddress($net);
        echo "$addrString\n";
    }
}