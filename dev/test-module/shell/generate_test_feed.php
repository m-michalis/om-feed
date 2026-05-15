<?php
/**
 * Generate test feed using om-feed with sample data.
 *
 * Usage (from OpenMage root):
 *   php shell/generate_test_feed.php [--output <path>]
 *
 * Via DDEV:
 *   ddev exec 'cd openmage && php shell/generate_test_feed.php'
 */

// Bootstrap OpenMage — try CWD first (expected), then fall back to script parent
$mageRoot = getcwd();
if (!file_exists($mageRoot . '/app/Mage.php')) {
    $mageRoot = dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__, 2);
}
if (!file_exists($mageRoot . '/app/Mage.php')) {
    fwrite(STDERR, "Error: Cannot find app/Mage.php. Run from the OpenMage root directory.\n");
    fwrite(STDERR, "  cd openmage && php shell/generate_test_feed.php\n");
    exit(1);
}

require_once $mageRoot . '/app/Mage.php';
Mage::app('admin');

// Parse arguments
$outputPath = '';
foreach ($argv as $i => $arg) {
    if (($arg === '--output' || $arg === '-o') && isset($argv[$i + 1])) {
        $outputPath = $argv[$i + 1];
    }
    if ($arg === '--help' || $arg === '-h') {
        echo "Usage: php shell/generate_test_feed.php [options]\n";
        echo "\n";
        echo "Options:\n";
        echo "  --output, -o <path>  Output file path (default: var/export/test_feed.xml)\n";
        echo "  --help, -h           Show this help\n";
        exit(0);
    }
}

echo "Generating test feed...\n";

$startTime = microtime(true);

/** @var InternetCode_FeedTest_Model_Generate $generator */
$generator = Mage::getModel('ic_feed_test/generate');
$result = $generator->run($outputPath);

$elapsed = round(microtime(true) - $startTime, 2);

echo "Feed generated: {$result}\n";

if (file_exists($result)) {
    $size = filesize($result);
    echo sprintf("Size: %.1f KB\n", $size / 1024);

    $content = file_get_contents($result);
    $count = substr_count($content, '<product>');
    echo "Products: {$count}\n";
}

echo "Time: {$elapsed}s\n";
