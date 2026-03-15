<?php
// db.php - Database connection using PDO

// Enable error reporting for debugging (Remove this after fix)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// InfinityFree Database Credentials
// Find these in your InfinityFree Control Panel -> MySQL Databases
$host = 'localhost'; // Replace with your "DB Hostname"
$db   = 'infocrop';          // Replace with your "Database Name"
$user = 'root';             // Replace with your "vPanel Username"
$pass = '2550';         // Replace with your "Hosting Account Password"
$charset = 'utf8mb4';

// ── Database Connection Function ──────────────────────────────
function get_pdo_connection() {
    global $host, $db, $user, $pass, $charset, $options;
    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    try {
        return new PDO($dsn, $user, $pass, $options);
    } catch (\PDOException $e) {
        throw new \PDOException($e->getMessage(), (int)$e->getCode());
    }
}

// ── Connection PING/RECONNECT ────────────────────────────────
/**
 * Ensures the PDO connection is still alive.
 * If "server has gone away", it re-establishes the connection.
 * Use this after long-running tasks like Gemini AI calls.
 */
function pdo_ping() {
    global $pdo;
    try {
        // Attempt a simple query to see if connection is alive
        $pdo->query('SELECT 1');
    } catch (Exception $e) {
        // If it fails, try to reconnect
        $pdo = get_pdo_connection();
    }
}

// Initial connection
$pdo = get_pdo_connection();
