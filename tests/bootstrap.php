<?php
/**
 * PHPUnit bootstrap — loads OpenMage from the DDEV instance.
 *
 * Run tests: ddev test
 */

$openmageRoot = getenv('OPENMAGE_ROOT') ?: (dirname(__DIR__) . '/openmage');

if (!file_exists($openmageRoot . '/app/Mage.php')) {
    fwrite(STDERR, "OpenMage not found at {$openmageRoot}. Run 'ddev setup-openmage' first.\n");
    exit(1);
}

require_once $openmageRoot . '/app/Mage.php';
Mage::app('admin');

$defaultStore = Mage::app()->getDefaultStoreView();
if (!$defaultStore) {
    fwrite(STDERR, "No default store view found. Is sample data installed?\n");
    exit(1);
}

// Load the base test case
require_once __DIR__ . '/Integration/FeedTestCase.php';
