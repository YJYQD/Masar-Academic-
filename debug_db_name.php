<?php
require_once __DIR__ . '/db.php';

echo 'DB_NAME=' . DB_NAME . PHP_EOL;
$stmt = $conn->query('SELECT DATABASE() AS db_name');
$row = $stmt->fetch_assoc();
echo 'ACTIVE_DB=' . ($row['db_name'] ?? 'unknown') . PHP_EOL;
