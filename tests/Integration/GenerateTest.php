<?php

/**
 * Tests feed file generation, events, and output.
 */
class GenerateTest extends FeedTestCase
{
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
            @unlink($path . '.tmp');
            @unlink($path . '.zip');
        }
        parent::tearDown();
    }

    private function trackFile(string $path): string
    {
        $this->tempFiles[] = $path;
        return $path;
    }

    // ── File creation ─────────────────────────────────────

    public function testGenerateCreatesFile(): void
    {
        $path = $this->trackFile($this->getTempFeedPath());

        $feed = $this->createFeed();
        $feed->setVisibility(
            Mage::getSingleton('catalog/product_visibility')->getVisibleInSearchIds()
        );
        $feed->addAttributeToSelect(['name', 'sku']);
        $feed->generate(
            'phpunit_gen',
            $path,
            function (Varien_Io_File $io, Mage_Catalog_Model_Product $product) {
                $io->streamWrite($product->getSku() . "\n");
            }
        );

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));
    }

    public function testGenerateTmpFileIsRemoved(): void
    {
        $path = $this->trackFile($this->getTempFeedPath());

        $feed = $this->createFeed();
        $feed->setVisibility(
            Mage::getSingleton('catalog/product_visibility')->getVisibleInSearchIds()
        );
        $feed->addAttributeToSelect(['sku']);
        $feed->generate('phpunit_tmp', $path, function (Varien_Io_File $io, Mage_Catalog_Model_Product $p) {
            $io->streamWrite("ok\n");
        });

        $this->assertFileDoesNotExist($path . '.tmp', 'Temp file should be removed after atomic move');
    }

    // ── Events ────────────────────────────────────────────

    public function testGenerateFiresHeaderAndFooterEvents(): void
    {
        $path = $this->trackFile($this->getTempFeedPath());

        GenerateTestEventTracker::$headerFired = false;
        GenerateTestEventTracker::$footerFired = false;

        // Register observers via config (string class name — SimpleXML can't hold objects)
        $cfg = Mage::app()->getConfig();
        $cfg->setNode('global/events/omfeed_insert_headers_phpunit_evt/observers/test/type', 'model');
        $cfg->setNode('global/events/omfeed_insert_headers_phpunit_evt/observers/test/model', 'GenerateTestEventTracker');
        $cfg->setNode('global/events/omfeed_insert_headers_phpunit_evt/observers/test/method', 'onHeader');

        $cfg->setNode('global/events/omfeed_insert_footer_phpunit_evt/observers/test/type', 'model');
        $cfg->setNode('global/events/omfeed_insert_footer_phpunit_evt/observers/test/model', 'GenerateTestEventTracker');
        $cfg->setNode('global/events/omfeed_insert_footer_phpunit_evt/observers/test/method', 'onFooter');

        $feed = $this->createFeed();
        $feed->setVisibility(
            Mage::getSingleton('catalog/product_visibility')->getVisibleInSearchIds()
        );
        $feed->addAttributeToSelect(['sku']);
        $feed->generate('phpunit_evt', $path, function (Varien_Io_File $io, Mage_Catalog_Model_Product $p) {
            $io->streamWrite("product\n");
        });

        $this->assertTrue(GenerateTestEventTracker::$headerFired, 'Header event should fire');
        $this->assertTrue(GenerateTestEventTracker::$footerFired, 'Footer event should fire');

        $content = file_get_contents($path);
        $this->assertStringContainsString('<header/>', $content);
        $this->assertStringContainsString('<footer/>', $content);
    }

    // ── Callback ──────────────────────────────────────────

    public function testGenerateCallsCallbackForEachProduct(): void
    {
        $path = $this->trackFile($this->getTempFeedPath());
        $callbackCount = 0;

        $feed = $this->createFeed();
        $feed->setVisibility(
            Mage::getSingleton('catalog/product_visibility')->getVisibleInSearchIds()
        );
        $feed->addAttributeToSelect(['sku']);
        $feed->generate('phpunit_cb', $path, function (Varien_Io_File $io, Mage_Catalog_Model_Product $p) use (&$callbackCount) {
            $callbackCount++;
            $io->streamWrite($p->getSku() . "\n");
        });

        $this->assertSame($feed->count(), $callbackCount, 'Callback should be called for each product');
    }

    // ── Zip ───────────────────────────────────────────────

    public function testGenerateWithZipCreatesArchive(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $path = $this->trackFile($this->getTempFeedPath());

        $feed = $this->createFeed();
        $feed->setVisibility(
            Mage::getSingleton('catalog/product_visibility')->getVisibleInSearchIds()
        );
        $feed->addAttributeToSelect(['sku']);
        $feed->generate('phpunit_zip', $path, function (Varien_Io_File $io, Mage_Catalog_Model_Product $p) {
            $io->streamWrite($p->getSku() . "\n");
        }, true);

        $this->assertFileExists($path . '.zip');
        $this->assertGreaterThan(0, filesize($path . '.zip'));
    }

    // ── Error handling ────────────────────────────────────

    public function testGenerateCallbackExceptionCleansUpTmp(): void
    {
        $path = $this->trackFile($this->getTempFeedPath());

        $feed = $this->createFeed();
        $feed->setVisibility(
            Mage::getSingleton('catalog/product_visibility')->getVisibleInSearchIds()
        );
        $feed->addAttributeToSelect(['sku']);

        try {
            $feed->generate('phpunit_err', $path, function () {
                throw new RuntimeException('Test exception');
            });
            $this->fail('Exception should propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('Test exception', $e->getMessage());
        }

        $this->assertFileDoesNotExist($path . '.tmp', 'Tmp file should be cleaned up on exception');
        $this->assertFileDoesNotExist($path, 'Final file should not be created on exception');
    }
}

/**
 * Stateful event tracker for testing generate() event dispatch.
 * Mage::getModel() resolves this by class name (no '/' = raw class name).
 */
class GenerateTestEventTracker
{
    public static bool $headerFired = false;
    public static bool $footerFired = false;

    public function onHeader(Varien_Event_Observer $event): void
    {
        self::$headerFired = true;
        $event->getIo()->streamWrite("<header/>\n");
    }

    public function onFooter(Varien_Event_Observer $event): void
    {
        self::$footerFired = true;
        $event->getIo()->streamWrite("<footer/>\n");
    }
}
