<?php

use PHPUnit\Framework\TestCase;

/**
 * Base test case for om-feed integration tests.
 *
 * Provides helper methods for creating pre-configured feed collections
 * that work in the DDEV/admin bootstrap context.
 */
abstract class FeedTestCase extends TestCase
{
    protected static int $storeId;
    protected static int $websiteId;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$storeId = (int) Mage::app()->getDefaultStoreView()->getId();
        self::$websiteId = (int) Mage::app()->getStore(self::$storeId)->getWebsiteId();
    }

    /**
     * Create a feed collection pre-configured with store context and price data.
     *
     * The addPriceData() call is required before setVisibility() because the Feed model
     * declares use_price_index=true as a class property default which survives construction.
     */
    protected function createFeed(array $setupConfig = [], array $flags = []): InternetCode_Feed_Model_Feed
    {
        /** @var InternetCode_Feed_Model_Feed $feed */
        $feed = Mage::getModel('ic_feed/feed');
        $feed->setStoreId(self::$storeId);

        $feed->addPriceData(
            Mage_Customer_Model_Group::NOT_LOGGED_IN_ID,
            self::$websiteId
        );

        foreach ($flags as $flag => $value) {
            $feed->setFlag($flag, $value);
        }

        $feed->setup($setupConfig);

        return $feed;
    }

    /**
     * Create a feed, add common attributes, set visibility, and load it.
     */
    protected function createAndLoadFeed(
        array $setupConfig = [],
        array $flags = [],
        array $attributes = ['name', 'sku', 'price', 'image', 'url_key']
    ): InternetCode_Feed_Model_Feed {
        $feed = $this->createFeed($setupConfig, $flags);

        $feed->setVisibility(
            Mage::getSingleton('catalog/product_visibility')->getVisibleInSearchIds()
        );

        $feed->addAttributeToSelect($attributes);
        $feed->load();

        return $feed;
    }

    /**
     * Get a temporary file path for generate() tests, cleaned up in tearDown.
     */
    protected function getTempFeedPath(string $suffix = '.xml'): string
    {
        $path = Mage::getBaseDir('var') . '/export/test_' . uniqid() . $suffix;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $path;
    }
}
