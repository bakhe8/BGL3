<?php
namespace App\Support;
use PDOStatement;

class TracedStatement extends PDOStatement {
    protected function __construct() {
        // Required by PDO
    }

    public function execute(?array $params = null): bool {
        $start = microtime(true);
        try {
            return parent::execute($params);
        } finally {
            $end = microtime(true);
            QuickTrace::log($this->queryString, ($end - $start) * 1000);
        }
    }
}
