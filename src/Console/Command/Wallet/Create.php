<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Console\Command\Wallet;

use BitWasp\Bitcoin\Address\AddressCreator;
use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Chain\ProofOfWork;
use BitWasp\Bitcoin\Crypto\Random\Random;
use BitWasp\Bitcoin\Key\Deterministic\HdPrefix\GlobalPrefixConfig;
use BitWasp\Bitcoin\Key\Deterministic\HdPrefix\NetworkConfig;
use BitWasp\Bitcoin\Key\Deterministic\Slip132\Slip132;
use BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Math\Math;
use BitWasp\Bitcoin\Mnemonic\Bip39\Bip39SeedGenerator;
use BitWasp\Bitcoin\Mnemonic\Bip39\Bip39WordListInterface;
use BitWasp\Bitcoin\Mnemonic\Bip39\Wordlist\EnglishWordList;
use BitWasp\Bitcoin\Mnemonic\Bip39\Wordlist\JapaneseWordList;
use BitWasp\Bitcoin\Mnemonic\MnemonicFactory;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\Base58ExtendedKeySerializer;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\ExtendedKeySerializer;
use BitWasp\Buffertools\Buffer;
use BitWasp\PinEntry\PinEntry;
use BitWasp\PinEntry\PinRequest;
use BitWasp\PinEntry\Process\Process;
use BitWasp\Wallet\BlockRef;
use BitWasp\Wallet\Chain;
use BitWasp\Wallet\Config;
use BitWasp\Wallet\Console\Command\Command;
use BitWasp\Wallet\DbManager;
use BitWasp\Wallet\NetworkInfo;
use BitWasp\Wallet\Validation\Base58ExtendedKeyValidator;
use BitWasp\Wallet\Wallet\Factory;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class Create extends Command
{
    protected function configure()
    {
        $this
            // the name of the command (the part after "bin/console")
            ->setName('wallet:create')
            // the short description shown while running "php bin/console list"
            ->setDescription('Create a new wallet')

            // An identifier is required for this wallet
            ->addArgument("identifier", InputArgument::REQUIRED, "Identifier for wallet")

            // wallet setup options
            ->addOption('birthday', null, InputOption::VALUE_REQUIRED, "Initialize wallet with a birthday block hash")

            ->addOption('from-public', null, InputOption::VALUE_NONE, "Setup from public data only")

            // key derivation options
            ->addOption('bip44', null, InputOption::VALUE_NONE, "Setup a bip44 wallet account")
            ->addOption('bip44-account', null, InputOption::VALUE_REQUIRED, "BIP44 'account' value when creating from a seed", '0')
            ->addOption('bip44-cointype', null, InputOption::VALUE_REQUIRED, "BIP44 'cointype' value when creating from a seed", '0')

            ->addOption('bip49', null, InputOption::VALUE_NONE, "Setup a bip49 wallet account")
            ->addOption('bip49-account', null, InputOption::VALUE_REQUIRED, "BIP49 'account' value when creating from a seed", '0')
            ->addOption('bip49-cointype', null, InputOption::VALUE_REQUIRED, "BIP49 'cointype' value when creating from a seed", '0')

            ->addOption('bip84', null, InputOption::VALUE_NONE, "Setup a BIP84 wallet account")
            ->addOption('bip84-account', null, InputOption::VALUE_REQUIRED, "BIP84 'account' value when creating from a seed", '0')
            ->addOption('bip84-cointype', null, InputOption::VALUE_REQUIRED, "BIP84 'cointype' value when creating from a seed", '0')

            // settings for bip39 seed generation
            ->addOption('bip39-en', null, InputOption::VALUE_NONE, "Use the english wordlist for BIP39 (default)")
            ->addOption('bip39-jp', null, InputOption::VALUE_NONE, "Use the japanese wordlist for BIP39")
            ->addOption('bip39-recovery', 'm', InputOption::VALUE_NONE, "Prompt for a BIP39 seed and recover this wallet")
            ->addOption('bip39-passphrase', 'p', InputOption::VALUE_NONE, "Prompt for a BIP39 passphrase")

            // settings for bip32 derived wallets
            ->addOption('bip32-gaplimit', null, InputOption::VALUE_REQUIRED, "Set a custom gap limit for the wallet", "100")
            ->addOption('bip32-public', null, InputOption::VALUE_REQUIRED, "Initialize from a public key")

            // Data directory
            ->addOption("datadir", "d", InputOption::VALUE_REQUIRED, 'Data directory, defaults to $HOME/.walletman')

            // the full command description shown when running the command with
            // the "--help" option
            ->setHelp('');
    }

    private function getBip39Wordlist(InputInterface $input): Bip39WordListInterface
    {
        if ($input->getOption('bip39-jp')) {
            return new JapaneseWordList();
        } else {
            return new EnglishWordList();
        }
    }

    private function getBip39Mnemonic(InputInterface $input, OutputInterface $output, Bip39WordListInterface $wordList): string
    {
        $bip39 = MnemonicFactory::bip39($wordList);
        if ($input->getOption('bip39-recovery')) {
            return $this->promptForMnemonic($bip39, $input, $output);
        }

        $random = new Random();
        $entropy = $random->bytes(16);
        $mnemonic = $bip39->entropyToMnemonic($entropy);
        $output->write("<info>Your mnemonic is: $mnemonic</info>\n");
        $output->write("<comment>It is vital that you record your seed now so your wallet can\n" .
            "be recovered in case of hardware failure.</comment>\n");
        return $mnemonic;
    }

    private function getAccountKey(InputInterface $input, OutputInterface $output, Base58ExtendedKeySerializer $hdSerializer, NetworkInterface $net): string
    {
        $validator = new Base58ExtendedKeyValidator($hdSerializer, $net);

        if (file_exists("/usr/bin/pinentry")) {
            $request = new PinRequest();
            $request->withTitle("Import BIP32 Key");
            $request->withDesc("Enter your xpub to continue");

            $pinEntry = new PinEntry(new Process("/usr/bin/pinentry"));

            $mnemonic = $pinEntry->getPin($request, $validator);
        } else {
            $helper = $this->getHelper('question');
            $question = new Question('Enter you xpub to continue:');
            $question->setHiddenFallback(false);
            $question->setValidator(function ($answer) use ($validator) {
                $error = "";
                if (!$validator->validate($answer, $error)) {
                    throw new \RuntimeException($error);
                }
                return $answer;
            });
            $question->setMaxAttempts(2);
            $mnemonic = $helper->ask($input, $output, $question);
        }

        return $mnemonic;
    }

    private function parseHardenedBip32Index(InputInterface $input, string $optionName): int
    {
        $indexValue = $input->getOption($optionName);
        if (!\is_string($indexValue)) {
            throw new \RuntimeException("Invalid value provided for {$optionName}");
        }
        if ($indexValue != (string)(int)$indexValue) {
            throw new \RuntimeException("invalid value for hardened index: $optionName");
        }
        $index = (int) $indexValue;
        if ($index < 0 || ($index & (1 << 31)) != 0) {
            throw new \RuntimeException("invalid value for hardened index: $optionName");
        }
        return $index;
    }

    private function parseBirthday(InputInterface $input): ?BlockRef
    {
        $birthdayValue = $input->getOption('birthday');
        if (!\is_string($birthdayValue)) {
            return null;
        }

        if (\substr_count($birthdayValue, ",") !== 1) {
            throw new \RuntimeException("Invalid birthday, should be [height],[hash]");
        }

        list ($height, $hash) = \explode(",", $birthdayValue);
        if ($height !== (string)(int)$height) {
            throw new \RuntimeException("Invalid height");
        }

        if (!\is_string($hash) || \strlen($hash) !== 64) {
            throw new \RuntimeException("Invalid hash for birthday");
        }

        return new BlockRef((int) $height, Buffer::hex($hash, 32));
    }

    private function parseGapLimit(InputInterface $input): int
    {
        /** @var string $birthdayValue */
        $birthdayValue = $input->getOption('bip32-gaplimit');
        return (int) $birthdayValue;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $identifier = $this->getStringArgument($input, 'identifier');
        $fPublic = $input->getOption('from-public');
        $fUseBip44 = $input->getOption('bip44');
        $fUseBip49 = $input->getOption('bip49');
        $fUseBip84 = $input->getOption('bip84');
        $fBip39Pass = $input->getOption('bip39-passphrase');
        $fGapLimit = $this->parseGapLimit($input);
        $wordlist = $this->getBip39Wordlist($input);
        $birthday = $this->parseBirthday($input);

        // simple deps
        $math = new Math();
        $networkInfo = new NetworkInfo();
        $dbMgr = new DbManager();
        $ecAdapter = Bitcoin::getEcAdapter();

        // load config
        $dataDir = $this->loadDataDir($input);
        $config = Config::fromDataDir($dataDir);
        $net = $networkInfo->getNetwork($config->getNetwork());
        $registry = $networkInfo->getSlip132Registry($config->getNetwork());
        $db = $dbMgr->loadDb($config->getDbPath($dataDir));

        $params = $networkInfo->getParams($config->getNetwork(), $math);
        if ($birthday === null) {
            $birthday = new BlockRef(0, $params->getGenesisBlockHeader()->getHash());
        }

        // check user input
        if ($db->checkWalletExists($identifier)) {
            throw new \RuntimeException("Wallet already exists");
        }

        $slip132 = new Slip132();
        if ($fUseBip44) {
            $coinType = $this->parseHardenedBip32Index($input, "bip44-cointype");
            $account = $this->parseHardenedBip32Index($input, "bip44-account");
            $prefix = $slip132->p2pkh($registry);
            $bip44Purpose = 44;
        } else if ($fUseBip49) {
            $coinType = $this->parseHardenedBip32Index($input, "bip49-cointype");
            $account = $this->parseHardenedBip32Index($input, "bip49-account");
            $prefix = $slip132->p2shP2wpkh($registry);
            $bip44Purpose = 49;
        } else if ($fUseBip84) {
            $coinType = $this->parseHardenedBip32Index($input, "bip84-cointype");
            $account = $this->parseHardenedBip32Index($input, "bip84-account");
            $prefix = $slip132->p2wpkh($registry);
            $bip44Purpose = 84;
        } else {
            throw new \RuntimeException("A wallet type is required");
        }

        // this config only needs one prefix, ours
        $prefixConfig = new GlobalPrefixConfig([
            new NetworkConfig($net, [
                $prefix,
            ])
        ]);

        $chain = new Chain(new ProofOfWork($math, $params));
        $chain->init($db, $params);

        $bestBlock = $chain->getBestBlock();
        if ($birthday->getHeight() < $bestBlock->getHeight()) {
            throw new \RuntimeException("Best block is greater than birthday, need to start syncing from scratch");
        }

        $hdSerializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ecAdapter, $prefixConfig));
        $hdFactory = new HierarchicalKeyFactory($ecAdapter, $hdSerializer);
        $walletFactory = new Factory($db, $net, $hdSerializer, $ecAdapter);
        if ($fPublic) {
            $path = "M/{$bip44Purpose}'/{$coinType}'/{$account}'";
            $accountXpub = $this->getAccountKey($input, $output, $hdSerializer, $net);
            $accountKey = $hdFactory->fromExtended($accountXpub, $net);
            $wallet = $walletFactory->createBip44WalletFromAccountKey($identifier, $accountKey, $path, $fGapLimit, $birthday);
        } else {
            $path = "M/{$bip44Purpose}'/{$coinType}'/{$account}'";
            $mnemonic = $this->getBip39Mnemonic($input, $output, $wordlist);
            $passphrase = '';
            if ($fBip39Pass) {
                $passphrase = $this->promptForPassphrase($input, $output);
            }

            $seedGenerator = new Bip39SeedGenerator();
            $seed = $seedGenerator->getSeed($mnemonic, $passphrase);
            $rootKey = $hdFactory->fromEntropy($seed, $prefix->getScriptDataFactory());

            $wallet = $walletFactory->createBip44WalletFromRootKey($identifier, $rootKey, $path, $fGapLimit, $birthday);
        }

        // don't forget to prime with change addresses
        $wallet->getChangeScriptGenerator()->generate();

        $dbScript = $wallet->getScriptGenerator()->generate();
        $addrCreator = new AddressCreator();
        $addrString = $dbScript->getAddress($addrCreator)->getAddress($net);
        echo "$addrString\n";
    }
}
