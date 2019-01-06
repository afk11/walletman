<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Console\Command\Wallet;

use BitWasp\Bitcoin\Address\AddressCreator;
use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Key\Deterministic\HdPrefix\GlobalPrefixConfig;
use BitWasp\Bitcoin\Key\Deterministic\HdPrefix\NetworkConfig;
use BitWasp\Bitcoin\Key\Deterministic\Slip132\Slip132;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\Base58ExtendedKeySerializer;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\ExtendedKeySerializer;
use BitWasp\Wallet\Config;
use BitWasp\Wallet\Console\Command\Command;
use BitWasp\Wallet\DbManager;
use BitWasp\Wallet\NetworkInfo;
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
            ->setDescription('Generate new addresses')

            // An identifier is required for this wallet
            ->addArgument("identifier", InputArgument::REQUIRED, "Identifier for wallet")

            ->addOption("change", null, InputOption::VALUE_NONE, 'Returns an address to be used as change')
            ->addOption("datadir", "d", InputOption::VALUE_REQUIRED, 'Data directory, defaults to $HOME/.walletman')

            // the full command description shown when running the command with
            // the "--help" option
            ->setHelp('Generates derives the next to be used address');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $identifier = $this->getStringArgument($input, "identifier");
        $fChange = $input->getOption('change');
        $dataDir = $this->loadDataDir($input);

        $ecAdapter = Bitcoin::getEcAdapter();
        $dbMgr = new DbManager();
        $networkInfo = new NetworkInfo();

        $config = Config::fromDataDir($dataDir);
        $db = $dbMgr->loadDb($config->getDbPath($dataDir));

        $net = $networkInfo->getNetwork($config->getNetwork());
        $registry = $networkInfo->getSlip132Registry($config->getNetwork());

        // init with all prefixes we support
        $slip132 = new Slip132();
        $prefixConfig = new GlobalPrefixConfig([
            new NetworkConfig($net, [
                $slip132->p2pkh($registry),
                $slip132->p2wpkh($registry),
                $slip132->p2shP2wpkh($registry),
            ])
        ]);

        $hdSerializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ecAdapter, $prefixConfig));
        $walletFactory = new Factory($db, $net, $hdSerializer, $ecAdapter);
        $wallet = $walletFactory->loadWallet($identifier);
        if ($fChange) {
            $addrGen = $wallet->getChangeScriptGenerator();
        } else {
            $addrGen = $wallet->getScriptGenerator();
        }

        $dbScript = $addrGen->generate();
        $addrString = $dbScript->getAddress(new AddressCreator())->getAddress($net);
        echo "$addrString\n";
    }
}
