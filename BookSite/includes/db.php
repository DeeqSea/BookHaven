<?php
/**
 * db.php - Database connection for BookHaven
 */

// Include configuration
require_once 'config.php';

// Establish database connection
$connection = null;

try {
    $connection = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
    
    if ($connection->connect_error) {
        throw new Exception("Connection failed: " . $connection->connect_error);
    }
    
    // Set charset to UTF-8
    $connection->set_charset('utf8mb4');
} catch (Exception $e) {
    error_log($e->getMessage());
    die("Database connection error. Please try again later.");
}

/**
 * Get database connection
 * @return mysqli Database connection
 */
function getDb() {
    global $connection;
    return $connection;
}

/**
 * Close database connection
 */
function closeDb() {
    global $connection;
    if ($connection) {
        $connection->close();
    }
}

// Register shutdown function to ensure connection is closed
register_shutdown_function('closeDb');