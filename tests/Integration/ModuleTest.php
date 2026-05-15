<?php

/**
 * Tests that the module is correctly registered and resolvable.
 */
class ModuleTest extends FeedTestCase
{
    public function testModuleIsActive(): void
    {
        $modules = Mage::getConfig()->getNode('modules')->children();
        $this->assertObjectHasProperty('InternetCode_Feed', $modules);
        $this->assertSame('true', (string) $modules->InternetCode_Feed->active);
    }

    public function testModelAliasResolves(): void
    {
        $model = Mage::getModel('ic_feed/feed');
        $this->assertInstanceOf(InternetCode_Feed_Model_Feed::class, $model);
    }

    public function testHelperAliasResolves(): void
    {
        $helper = Mage::helper('feed');
        $this->assertInstanceOf(InternetCode_Feed_Helper_Data::class, $helper);
    }

    public function testFeedExtendsProductCollection(): void
    {
        $feed = Mage::getModel('ic_feed/feed');
        $this->assertInstanceOf(Mage_Catalog_Model_Resource_Product_Collection::class, $feed);
    }

    public function testFlagConstantsExist(): void
    {
        $this->assertSame('configurable_associations', InternetCode_Feed_Model_Feed::FLAG_ASSOCIATIONS);
        $this->assertSame('leaf_categories', InternetCode_Feed_Model_Feed::FLAG_LEAF_CATS);
        $this->assertSame('require_category', InternetCode_Feed_Model_Feed::FLAG_REQUIRE_CAT);
        $this->assertSame('media_gallery', InternetCode_Feed_Model_Feed::FLAG_GALLERY);
    }

    public function testDefaultFlags(): void
    {
        $feed = Mage::getModel('ic_feed/feed');

        // Default: leaf cats ON, require cat ON, gallery ON, associations OFF
        $this->assertTrue($feed->getFlag(InternetCode_Feed_Model_Feed::FLAG_LEAF_CATS));
        $this->assertTrue($feed->getFlag(InternetCode_Feed_Model_Feed::FLAG_REQUIRE_CAT));
        $this->assertTrue($feed->getFlag(InternetCode_Feed_Model_Feed::FLAG_GALLERY));
        $this->assertFalse((bool) $feed->getFlag(InternetCode_Feed_Model_Feed::FLAG_ASSOCIATIONS));
    }

    public function testSetupReturnsThis(): void
    {
        $feed = Mage::getModel('ic_feed/feed');
        $result = $feed->setup(['bread_excl' => []]);
        $this->assertSame($feed, $result);
    }

    public function testAddUrlRewriteIsNoop(): void
    {
        $feed = Mage::getModel('ic_feed/feed');
        $result = $feed->addUrlRewrite(99);
        $this->assertSame($feed, $result, 'addUrlRewrite() should return $this (no-op)');
    }
}
