<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Console\Command\Wallet;

use BitWasp\Bitcoin\Address\AddressCreator;
use BitWasp\Bitcoin\Amount;
use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Key\Deterministic\HdPrefix\GlobalPrefixConfig;
use BitWasp\Bitcoin\Key\Deterministic\HdPrefix\NetworkConfig;
use BitWasp\Bitcoin\Key\Deterministic\Slip132\Slip132;
use BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Key\KeyToScript\KeyToScriptHelper;
use BitWasp\Bitcoin\Mnemonic\Bip39\Bip39SeedGenerator;
use BitWasp\Bitcoin\Mnemonic\MnemonicFactory;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\Base58ExtendedKeySerializer;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\ExtendedKeySerializer;
use BitWasp\Bitcoin\Transaction\TransactionOutput;
use BitWasp\Wallet\Config;
use BitWasp\Wallet\Console\Command\Command;
use BitWasp\Wallet\DbManager;
use BitWasp\Wallet\NetworkInfo;
use BitWasp\Wallet\Wallet\HdWallet\Bip44Wallet;
use BitWasp\Wallet\Wallet\Factory;
use BitWasp\Wallet\Wallet\WalletInterface;
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

            ->addOption("datadir", "d", InputOption::VALUE_REQUIRED, 'Data directory, defaults to $HOME/.walletman')

            ->setHelp('This command will create, sign, and broadcast a transaction emptying the wallet, sending all funds to the provided address');
    }

    private function parseFeeRate(InputInterface $input): int
    {
        $feeRateCustom = $input->getOption('feerate-custom');
        if (is_string($feeRateCustom)) {
            if ($feeRateCustom != (int)$feeRateCustom) {
                throw new \RuntimeException("invalid fee rate provided");
            }
            return (int) $feeRateCustom;
        }
        throw new \RuntimeException("must select a feerate option");
    }

    private function parseOutputs(InputInterface $input, AddressCreator $addrCreator, NetworkInterface $net): array
    {
        $amount = new Amount();
        $outputs = [];
        /** @var array $destinations */
        $destinations = $input->getOption("destination");
        foreach ($destinations as $destination) {
            $row = explode(",", $destination);
            if (count($row) !== 2) {
                throw new \RuntimeException("Destination should be in format: btcvalue,address");
            }
            list ($btcValue, $address) = $row;

            $addr = $addrCreator->fromString($address, $net);
            $satoshi = $amount->toSatoshis($btcValue);
            $outputs[] = new TransactionOutput($satoshi, $addr->getScriptPubKey());
        }
        return $outputs;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $fBip39Pass = $input->getOption('bip39-passphrase');
        $dataDir = $this->loadDataDir($input);
        $identifier = $this->getStringArgument($input, 'identifier');

        $ecAdapter = Bitcoin::getEcAdapter();
        $addrCreator = new AddressCreator();
        $dbMgr = new DbManager();
        $networkInfo = new NetworkInfo();

        $config = Config::fromDataDir($dataDir);
        $net = $networkInfo->getNetwork($config->getNetwork());
        $registry = $networkInfo->getSlip132Registry($config->getNetwork());

        $feeRate = $this->parseFeeRate($input);
        try {
            $outputs = $this->parseOutputs($input, $addrCreator, $net);
        } catch (\Exception $e) {
            $output->write("Failed to parse destinations: {$e->getMessage()}");
            return -1;
        }

        if (count($outputs) < 1) {
            $output->write("A destination is required");
            return -1;
        }

        $db = $dbMgr->loadDb($config->getDbPath($dataDir));

        $slip132 = new Slip132(new KeyToScriptHelper($ecAdapter));
        $hdSerializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ecAdapter, new GlobalPrefixConfig([
            new NetworkConfig($net, [
                $slip132->p2pkh($registry),
                $slip132->p2wpkh($registry),
                $slip132->p2shP2wpkh($registry),
            ])
        ])));

        $walletFactory = new Factory($db, $net, $hdSerializer, $ecAdapter);

        /** @var WalletInterface $wallet */
        $wallet = $walletFactory->loadWallet($identifier);

        if ($wallet instanceof Bip44Wallet) {
            $bip39 = MnemonicFactory::bip39();
            $mnemonic = $this->promptForMnemonic($bip39, $input, $output);
            $passphrase = '';
            if ($fBip39Pass) {
                $passphrase = $this->promptForPassphrase($input, $output);
            }

            $seed = (new Bip39SeedGenerator())->getSeed($mnemonic, $passphrase);
            $hdFactory = new HierarchicalKeyFactory($ecAdapter);
            $rootNode = $hdFactory->fromEntropy($seed);

            $accountNode = $rootNode->derivePath("44'/0'/0'");
            $wallet->unlockWithAccountKey($accountNode);
        } else {
            throw new \RuntimeException("Unsupported wallet type");
        }

        $preparedTx = $wallet->send($outputs, $feeRate);
        $signedTx = $wallet->signTx($preparedTx);
        echo $signedTx->getHex().PHP_EOL;
        return 0;
    }
}
