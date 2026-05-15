<?php

/**
 * Tests feed collection loading, flags, and configuration.
 */
class CollectionTest extends FeedTestCase
{
    // ── Basic loading ──────────────────────────────────────

    public function testCollectionLoadsProducts(): void
    {
        $feed = $this->createAndLoadFeed();
        $this->assertGreaterThan(0, $feed->count(), 'Feed should load products from sample data');
    }

    public function testProductsHaveRequiredAttributes(): void
    {
        $feed = $this->createAndLoadFeed();
        $product = $feed->getFirstItem();

        $this->assertNotEmpty($product->getSku(), 'Product should have SKU');
        $this->assertNotEmpty($product->getName(), 'Product should have name');
    }

    public function testProductsHavePriceData(): void
    {
        $feed = $this->createAndLoadFeed();
        $product = $feed->getFirstItem();

        $this->assertNotNull($product->getPrice(), 'Product should have price');
        $this->assertIsNumeric($product->getPrice());
    }

    // ── FLAG_LEAF_CATS ────────────────────────────────────

    public function testLeafCatsEnabledReducesResults(): void
    {
        $feedLeaf = $this->createAndLoadFeed([], [
            InternetCode_Feed_Model_Feed::FLAG_LEAF_CATS => true,
        ]);

        $feedAll = $this->createAndLoadFeed([], [
            InternetCode_Feed_Model_Feed::FLAG_LEAF_CATS => false,
        ]);

        $this->assertGreaterThan(0, $feedLeaf->count());
        $this->assertGreaterThanOrEqual(
            $feedLeaf->count(),
            $feedAll->count(),
            'Disabling leaf cats should return same or more products'
        );
    }

    // ── FLAG_GALLERY ──────────────────────────────────────

    public function testGalleryEnabledLoadsImages(): void
    {
        $feed = $this->createAndLoadFeed([], [
            InternetCode_Feed_Model_Feed::FLAG_GALLERY => true,
        ]);

        $hasGallery = false;
        foreach ($feed as $product) {
            if (!empty($product->getData('gallery'))) {
                $hasGallery = true;
                $this->assertIsArray($product->getData('gallery'));
                break;
            }
        }
        $this->assertTrue($hasGallery, 'At least one product should have gallery images');
    }

    public function testGalleryDisabledSkipsImages(): void
    {
        $feed = $this->createAndLoadFeed([], [
            InternetCode_Feed_Model_Feed::FLAG_GALLERY => false,
        ]);

        foreach ($feed as $product) {
            $this->assertNull(
                $product->getData('gallery'),
                'Gallery should not be loaded when FLAG_GALLERY is false'
            );
        }
    }

    // ── FLAG_ASSOCIATIONS ─────────────────────────────────

    public function testAssociationsGroupChildrenUnderParent(): void
    {
        // No visibility filter — simple children are "Not Visible Individually" in sample data
        // but must be in the collection for associations to regroup them under parents.
        $feed = $this->createFeed([], [
            InternetCode_Feed_Model_Feed::FLAG_ASSOCIATIONS => true,
            InternetCode_Feed_Model_Feed::FLAG_REQUIRE_CAT => false,
        ]);
        $feed->addAttributeToSelect(['name', 'sku', 'price']);
        $feed->load();

        $hasAssociated = false;
        foreach ($feed as $product) {
            $associated = $product->getData('associated_products');
            if (!empty($associated)) {
                $hasAssociated = true;
                $this->assertIsArray($associated);
                $this->assertSame('configurable', $product->getTypeId());
                foreach ($associated as $child) {
                    $this->assertInstanceOf(Mage_Catalog_Model_Product::class, $child);
                }
                break;
            }
        }
        $this->assertTrue($hasAssociated, 'At least one configurable should have associated_products');
    }

    public function testAssociationsDisabledKeepsChildrenSeparate(): void
    {
        // No visibility filter — simple children must be in the collection to verify item_group_id
        $feed = $this->createFeed([], [
            InternetCode_Feed_Model_Feed::FLAG_ASSOCIATIONS => false,
            InternetCode_Feed_Model_Feed::FLAG_REQUIRE_CAT => false,
        ]);
        $feed->addAttributeToSelect(['name', 'sku']);
        $feed->load();

        $hasSimpleWithGroupId = false;
        foreach ($feed as $product) {
            if ($product->getTypeId() === 'simple' && $product->getData('item_group_id')) {
                $hasSimpleWithGroupId = true;
                break;
            }
        }
        $this->assertTrue($hasSimpleWithGroupId, 'Simple children should remain separate items without associations flag');
    }

    // ── FLAG_REQUIRE_CAT ──────────────────────────────────

    public function testRequireCatFiltersByCategory(): void
    {
        $feedRequired = $this->createAndLoadFeed([], [
            InternetCode_Feed_Model_Feed::FLAG_REQUIRE_CAT => true,
        ]);

        $feedOptional = $this->createAndLoadFeed([], [
            InternetCode_Feed_Model_Feed::FLAG_REQUIRE_CAT => false,
        ]);

        $this->assertGreaterThan(0, $feedRequired->count());
        $this->assertGreaterThanOrEqual(
            $feedRequired->count(),
            $feedOptional->count(),
            'Disabling require_cat should return same or more products'
        );
    }

    // ── Config: cat_excl ──────────────────────────────────

    public function testCatExclReducesProducts(): void
    {
        // Get a category ID that has products
        $db = Mage::getSingleton('core/resource')->getConnection('core_read');
        $catId = (int) $db->fetchOne(
            'SELECT category_id FROM catalog_category_product_index WHERE store_id = ? LIMIT 1',
            [self::$storeId]
        );
        $this->assertGreaterThan(0, $catId);

        $feedNormal = $this->createAndLoadFeed(['bread_excl' => []]);
        $feedExcluded = $this->createAndLoadFeed(['cat_excl' => [$catId]]);

        $this->assertLessThanOrEqual(
            $feedNormal->count(),
            $feedExcluded->count(),
            'Excluding a category should return same or fewer products'
        );
    }

    // ── Stock data ────────────────────────────────────────

    public function testStockDataPresent(): void
    {
        $feed = $this->createAndLoadFeed();
        $product = $feed->getFirstItem();

        // Stock is always joined (REQUIRE_STOCK is default in BC mode)
        $this->assertNotNull($product->getData('stock_status'), 'Product should have stock_status');
    }

    // ── Attribute options ─────────────────────────────────

    public function testAttributeCodeToIdMapping(): void
    {
        $feed = $this->createFeed();
        $map = $feed->getAttributeCodeToId();

        $this->assertIsArray($map);
        // Sample data has user-defined select attributes (color, manufacturer, etc.)
        $this->assertNotEmpty($map, 'Should have user-defined select attribute mappings');
    }

    public function testAttributeIdToCodeReverse(): void
    {
        $feed = $this->createFeed();
        $forward = $feed->getAttributeCodeToId();
        $reverse = $feed->getAttributeIdToCode();

        // Keys and values should be swapped
        foreach ($forward as $code => $id) {
            $this->assertArrayHasKey($id, $reverse);
            $this->assertSame($code, $reverse[$id]);
        }
    }
}
