<?php
require_once 'config/database.php';

try {
    $conn = getDBConnection();

    // Read the SQL file
    $sql = file_get_contents('maintenance_scheduling_schema.sql');

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

    echo "Schema execution completed\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
