<?php

declare(strict_types=1);

namespace BitWasp\Wallet;

class Config
{
    /**
     * @var string
     */
    private $network;

    public function __construct(string $network)
    {
        $this->network = $network;
    }

    public static function fromDataDir(string $dataDir): Config
    {
        $configPath = "$dataDir/config.json";
        if (!file_exists($configPath)) {
            throw new \RuntimeException("Config file does not exist in directory");
        }

        $contents = file_get_contents($configPath);
        if (!$contents) {
            throw new \RuntimeException("Failed to read config file - check permissions");
        }
        $decoded = json_decode($contents, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("config file contained invalid JSON");
        }
        $config = self::fromArray($decoded);
        return $config;
    }

    public static function fromArray(array $config): Config
    {
        if (!array_key_exists('network', $config)) {
            throw new \InvalidArgumentException("Config array missing network");
        }
        return new self($config['network']);
    }

    public function getNetwork(): string
    {
        return $this->network;
    }

    public function getDbPath(string $dataDir): string
    {
        if (substr($dataDir, -1) == "/") {
            $dataDir = substr($dataDir, 0, -1);
        }
        return "{$dataDir}/db.sqlite3";
    }
}
