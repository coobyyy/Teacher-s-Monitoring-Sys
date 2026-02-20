<?php
// Migration script: adds first_name, middle_name, last_name, email columns to teachers table if missing.
// Usage: run in browser or CLI: php migrate_add_name_email.php

include 'database.php';

$cols = [
    'first_name' => "VARCHAR(255) DEFAULT NULL",
    'middle_name' => "VARCHAR(255) DEFAULT NULL",
    'last_name' => "VARCHAR(255) DEFAULT NULL",
    'email' => "VARCHAR(255) DEFAULT NULL"
];

$messages = [];
foreach ($cols as $col => $definition) {
    $r = $connection->query("SHOW COLUMNS FROM teachers LIKE '" . $connection->real_escape_string($col) . "'");
    if ($r && $r->num_rows > 0) {
        $messages[] = "Column '$col' already exists.";
        continue;
    }
    $sql = "ALTER TABLE teachers ADD COLUMN `$col` $definition";
    if ($connection->query($sql) === TRUE) {
        $messages[] = "Added column '$col'.";
    } else {
        $messages[] = "Failed to add '$col': " . $connection->error;
    }
}

// Optionally populate fullName from name parts when empty (non-destructive)
$connection->query("UPDATE teachers SET fullName = CONCAT_WS(' ', COALESCE(first_name,''), COALESCE(middle_name,''), COALESCE(last_name,'')) WHERE (fullName IS NULL OR TRIM(fullName)='')");

header('Content-Type: text/plain');
foreach ($messages as $m) echo $m . "\n";

echo "\nMigration finished.\n";

?>