<?php

require_once __DIR__ . '/bootstrap.php';

use SecurityScanner\Core\Config;

$config = Config::getInstance();

echo "Debugging PDO options...\n\n";

$connectionConfig = $config->get('database.connections.mysql');
echo "Connection config:\n";
print_r($connectionConfig);

echo "\nPDO Options:\n";
foreach ($connectionConfig['options'] as $key => $value) {
    echo "Key: $key (type: " . gettype($key) . "), Value: ";
    var_export($value);
    echo " (type: " . gettype($value) . ")\n";
}