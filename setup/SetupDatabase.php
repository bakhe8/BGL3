<?php
/**
 * Setup Module - Database Helper
 * Isolated connection to import.sqlite
 */

class SetupDatabase
{
    private static ?PDO $connection = null;
    
    public static function connect(): PDO
    {
        if (self::$connection !== null) {
            return self::$connection;
        }
        
        $dbPath = __DIR__ . '/database/import.sqlite';
        $schemaPath = __DIR__ . '/database/schema.sql';
        
        // Create database if doesn't exist
        $newDb = !file_exists($dbPath);
        
        self::$connection = new PDO('sqlite:' . $dbPath);
        self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Initialize schema if new
        if ($newDb && file_exists($schemaPath)) {
            $schema = file_get_contents($schemaPath);
            self::$connection->exec($schema);
        }
        
        return self::$connection;
    }
    
    public static function reset(): void
    {
        $dbPath = __DIR__ . '/database/import.sqlite';
        if (file_exists($dbPath)) {
            unlink($dbPath);
        }
        self::$connection = null;
    }
}
