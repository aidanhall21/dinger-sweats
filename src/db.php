<?php

function getDbConnection() {
    // Example path: /var/www/myproject/database/mydatabase.db
    $databasePath = __DIR__ . '/../database/data.db'; 
    // If db.php is in /var/www/myproject, then we can do __DIR__ . '/database'

    try {
        $pdo = new PDO('sqlite:' . $databasePath);
        // Recommended: set error mode to exceptions
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Enable Write-Ahead Logging mode for better performance and concurrency
        $pdo->exec('PRAGMA journal_mode=WAL');
        
        return $pdo;
    } catch (PDOException $e) {
        echo 'Connection failed: ' . $e->getMessage();
        exit;
    }
} 