<?php
echo "before\n";
require_once __DIR__ . '/db.php';
echo "after\n";
if (isset($conn)) { echo "conn-ok\n"; } else { echo "conn-missing\n"; }
