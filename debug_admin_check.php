<?php
require_once __DIR__ . '/db.php';

$stmt = $conn->prepare('SELECT id, username, password_hash, role, status FROM admins ORDER BY id ASC');
$stmt->execute();
$result = $stmt->get_result();
$rows = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

var_dump($rows);

echo "\nUSERS\n";
$stmt2 = $conn->prepare('SELECT id, username, password_hash, role, status FROM users ORDER BY id ASC');
$stmt2->execute();
$result2 = $stmt2->get_result();
$rows2 = $result2->fetch_all(MYSQLI_ASSOC);
$stmt2->close();

var_dump($rows2);
