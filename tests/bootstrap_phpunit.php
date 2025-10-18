<?php

/*
|--------------------------------------------------------------------------
| PHPUnit Bootstrap File
|--------------------------------------------------------------------------
|
| This bootstrap file is specifically for PHPUnit testing framework.
| It loads PHPUnit classes and sets up the testing environment.
|
*/

// Include PHPUnit autoloader from PHAR
require_once __DIR__ . '/../phpunit.phar';

// Include the main bootstrap
require_once __DIR__ . '/bootstrap_simple.php';

// Ensure PHPUnit classes are available
if (!class_exists('PHPUnit\Framework\TestCase')) {
    throw new Exception('PHPUnit framework is not properly loaded');
}

// Include the TestCase base class
require_once __DIR__ . '/TestCase.php';

echo "PHPUnit bootstrap completed successfully\n";