<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Console\Command\Wallet;

use BitWasp\Bitcoin\Address\AddressCreator;
use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Mnemonic\Bip39\Bip39SeedGenerator;
use BitWasp\Bitcoin\Mnemonic\MnemonicFactory;
use BitWasp\Bitcoin\Network\NetworkFactory;
use BitWasp\Bitcoin\Transaction\TransactionOutput;
use BitWasp\Wallet\Console\Command\Command;
use BitWasp\Wallet\DbManager;
use BitWasp\Wallet\Wallet\Bip44Wallet;
use BitWasp\Wallet\Wallet\Factory;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Send extends Command
{
    protected function configure()
    {
        $this
            // the name of the command (the part after "bin/console")
            ->setName('wallet:send')

            // the short description shown while running "php bin/console list"
            ->setDescription('Generate a transaction paying the specified destinations')

            // mandatory arguments
            ->addArgument('database', InputArgument::REQUIRED, "Database for wallet services")
            ->addArgument('identifier', InputArgument::REQUIRED, "Wallet identifier")

            ->addOption('destination', null, InputOption::VALUE_REQUIRED |InputOption::VALUE_IS_ARRAY, "Set one or many destinations, btcvalue,address")

            ->addOption('feerate-custom', null, InputOption::VALUE_REQUIRED, "Provide fee rate in satoshis per kilobyte")
            ->addOption('bip39-passphrase', 'p', InputOption::VALUE_NONE, "Prompt for a BIP39 passphrase")

            ->addOption('regtest', 'r', InputOption::VALUE_NONE, "Start wallet in regtest mode")
            ->addOption('testnet', 't', InputOption::VALUE_NONE, "Start wallet in testnet mode")

            ->setHelp('This command will create, sign, and broadcast a transaction emptying the wallet, sending all funds to the provided address');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $fIsRegtest = $input->getOption('regtest');
        $fIsTestnet = $input->getOption('testnet');
        $fBip39Pass = $input->getOption('bip39-passphrase');
        $path = $this->getStringArgument($input, 'database');
        $identifier = $this->getStringArgument($input, 'identifier');

        $ecAdapter = Bitcoin::getEcAdapter();
        $addrCreator = new AddressCreator();
        $dbMgr = new DbManager();

        if ($fIsRegtest) {
            $net = NetworkFactory::bitcoinRegtest();
        } else if ($fIsTestnet) {
            $net = NetworkFactory::bitcoinTestnet();
        } else {
            $net = NetworkFactory::bitcoin();
        }

        if (is_string($input->getOption('feerate-custom'))) {
            $customRate = $input->getOption('feerate-custom');
            if ($customRate !== (string)(int)$customRate) {
                throw new \RuntimeException("invalid fee rate provided");
            }
            $feeRate = (int)$customRate;
        } else {
            throw new \RuntimeException("must select a feerate option");
        }

        $outputs = [];
        foreach ($input->getOption("destination") as $destination) {
            $row = explode(",", $destination);
            if (count($row) !== 2) {
                throw new \RuntimeException("Destination should be in format: btcvalue,address");
            }
            list ($btcValue, $address) = $row;
            try {
                $addr = $addrCreator->fromString($address, $net);
            } catch (\Exception $e) {
                $output->write("Invalid address: {$address}");
                return -1;
            }
            $outputs[] = new TransactionOutput((int) ($btcValue * 1e8), $addr->getScriptPubKey());
        }

        if (count($outputs) < 1) {
            $output->write("A destination is required");
            return -1;
        }

        $db = $dbMgr->loadDb($path);
        $walletFactory = new Factory($db, $net, $ecAdapter);

        /** @var Bip44Wallet $bip44Wallet */
        $bip44Wallet = $walletFactory->loadWallet($identifier);

        $bip39 = MnemonicFactory::bip39();
        $mnemonic = $this->promptForMnemonic($bip39, $input, $output);

        $passphrase = '';
        if ($fBip39Pass) {
            $passphrase = $this->promptForPassphrase($input, $output);
        }

        $seed = (new Bip39SeedGenerator())->getSeed($mnemonic, $passphrase);
        $rootNode = HierarchicalKeyFactory::fromEntropy($seed, $ecAdapter);
        $accountNode = $rootNode->derivePath("44'/0'/0'");

        $bip44Wallet->unlockWithAccountKey($accountNode);

        $preparedTx = $bip44Wallet->send($outputs, $feeRate);
        $signedTx = $bip44Wallet->signTx($preparedTx);
        echo $signedTx->getHex().PHP_EOL;
        return 0;
    }
}
