<?php
namespace App\Support;
use PDO;

class TracedPDO extends PDO {
    public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null) {
        parent::__construct($dsn, $username, $password, $options);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [TracedStatement::class, []]);
    }

    public function exec(string $statement): int|false {
        $start = microtime(true);
        try {
            return parent::exec($statement);
        } finally {
            $end = microtime(true);
            QuickTrace::log($statement, ($end - $start) * 1000);
        }
    }
}
