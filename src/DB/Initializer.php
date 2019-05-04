<?php

declare(strict_types=1);

namespace BitWasp\Wallet\DB;

class Initializer
{
    /**
     * must not be called with a datadir
     * containing a db.sqlite3 file.
     * @param string $dataDir
     * @return DB
     */
    public function setupDb(string $dataDir): DB
    {
        $dbPath = "$dataDir/db.sqlite3";
        if (file_exists($dbPath)) {
            throw new \LogicException("DB filename already exists");
        }

        $pdo = new DB("sqlite:$dbPath");
        $pdo->createHeaderTable();
        $pdo->createWalletTable();
        $pdo->createKeyTable();
        $pdo->createScriptTable();
        $pdo->createTxTable();
        $pdo->createRawBlockTable();
        $pdo->createUtxoTable();

        return $pdo;
    }

    /**
     * must not be called with a datadir already
     * containing a config.json file
     * @param string $dataDir
     * @param string $networkName
     * @return string
     */
    public function setupConfig(string $dataDir, string $networkName)
    {
        $configPath = "$dataDir/config.json";
        if (file_exists($configPath)) {
            throw new \LogicException("Config file already exists");
        }

        $fh = fopen($configPath, "w+");
        if (false === $fh) {
            throw new \RuntimeException("Failed to create config file handle");
        }

        $config = json_encode([
            "network" => $networkName,
        ], JSON_PRETTY_PRINT);
        if (!$config) {
            throw new \RuntimeException("failed to encode to json");
        }
        fwrite($fh, $config);
        fclose($fh);
        return $config;
    }
}
