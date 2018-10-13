<?php

namespace BitWasp\Wallet\Wallet;

use BitWasp\Bitcoin\Script\ScriptInterface;
use BitWasp\Wallet\DB\DB;
use BitWasp\Wallet\DB\DbKey;
use BitWasp\Wallet\DB\DbScript;

class Bip32ScriptStorage implements ScriptStorage
{
    private $db;
    private $dbKey;
    public function __construct(DB $db, DbKey $dbKey)
    {
        $this->db = $db;
        $this->dbKey = $dbKey;
    }

    public function searchScript(ScriptInterface $script): ?DbScript
    {
        if ($script = $this->db->loadScriptByScriptPubKey(
            $this->dbKey->getWalletId(),
            $script
        )) {
            return $script;
        }
        return null;
    }
}
