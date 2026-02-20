<?php
// run_database_setup.php - Create database and run setup

try {
    // Connect to MySQL without specifying a database
    $conn = new mysqli('localhost', 'root', '');

    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    // Create the database if it doesn't exist
    $createDbSql = "CREATE DATABASE IF NOT EXISTS bec_equipment_db";
    if ($conn->query($createDbSql) === TRUE) {
        echo "Database 'bec_equipment_db' created successfully or already exists.\n";
    } else {
        echo "Error creating database: " . $conn->error . "\n";
    }

    // Select the database
    $conn->select_db('bec_equipment_db');

    // Read the SQL file
    $sql = file_get_contents('database_setup.sql');

    // Remove the CREATE DATABASE statement from the SQL file since we already created it
    $sql = preg_replace('/CREATE DATABASE IF NOT EXISTS bec_equipment_db;/i', '', $sql);
    $sql = preg_replace('/USE bec_equipment_db;/i', '', $sql);

    // Remove comments and split by CREATE TABLE or INSERT statements
    $sql = preg_replace('/--.*$/m', '', $sql); // Remove single-line comments
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql); // Remove multi-line comments

    // Split by semicolons, but handle multi-line statements
    $statements = [];
    $currentStatement = '';
    $inString = false;
    $stringChar = '';

    for ($i = 0; $i < strlen($sql); $i++) {
        $char = $sql[$i];

        if (!$inString && ($char === '"' || $char === "'")) {
            $inString = true;
            $stringChar = $char;
        } elseif ($inString && $char === $stringChar && $sql[$i-1] !== '\\') {
            $inString = false;
        } elseif (!$inString && $char === ';') {
            $currentStatement = trim($currentStatement);
            if (!empty($currentStatement)) {
                $statements[] = $currentStatement;
            }
            $currentStatement = '';
            continue;
        }

        if ($char !== "\n" && $char !== "\r" && $char !== "\t") {
            $currentStatement .= $char;
        } elseif (!empty($currentStatement)) {
            $currentStatement .= ' ';
        }
    }

    // Add the last statement if it doesn't end with semicolon
    $currentStatement = trim($currentStatement);
    if (!empty($currentStatement)) {
        $statements[] = $currentStatement;
    }

    echo "Found " . count($statements) . " statements to execute\n";

    foreach ($statements as $i => $statement) {
        if (!empty($statement)) {
            echo "Executing statement " . ($i + 1) . ": " . substr($statement, 0, 50) . "...\n";
            $result = $conn->query($statement);
            if ($conn->error) {
                echo "Error executing statement: " . $conn->error . "\n";
                echo "Statement: " . $statement . "\n";
            } else {
                echo "Executed statement successfully\n";
            }
        }
    }

    echo "Database setup completed\n";
    $conn->close();

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
