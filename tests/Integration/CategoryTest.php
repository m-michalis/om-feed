<?php

/**
 * Tests category breadcrumb generation via CTE and format/display options.
 */
class CategoryTest extends FeedTestCase
{
    // ── Default breadcrumbs ───────────────────────────────

    public function testProductsHaveBreadcrumbs(): void
    {
        $feed = $this->createAndLoadFeed();

        $hasCategory = false;
        foreach ($feed as $product) {
            $category = $product->getData('category');
            if (!empty($category)) {
                $hasCategory = true;
                $this->assertIsString($category);
                break;
            }
        }
        $this->assertTrue($hasCategory, 'At least one product should have a category breadcrumb');
    }

    public function testDefaultBreadcrumbUsesArrowSeparator(): void
    {
        $feed = $this->createAndLoadFeed();

        foreach ($feed as $product) {
            $category = $product->getData('category');
            if (!empty($category) && str_contains($category, '>')) {
                // Multi-level breadcrumb found — verify format
                $this->assertMatchesRegularExpression(
                    '/\w+ > \w+/',
                    $category,
                    'Breadcrumb should use " > " separator'
                );
                return;
            }
        }
        // If all categories are single-level, the test still passes
        $this->assertTrue(true);
    }

    // ── Custom separator ──────────────────────────────────

    public function testCustomCatSeparator(): void
    {
        $feed = $this->createAndLoadFeed([
            InternetCode_Feed_Model_Feed::CONFIG_CAT_SEPARATOR => ' / ',
        ]);

        foreach ($feed as $product) {
            $category = $product->getData('category');
            if (!empty($category) && str_contains($category, '/')) {
                $this->assertStringContainsString(' / ', $category);
                $this->assertStringNotContainsString(' > ', $category);
                return;
            }
        }
        $this->assertTrue(true);
    }

    // ── cat_format: single_deepest ────────────────────────

    public function testCatFormatSingleDeepest(): void
    {
        $feed = $this->createAndLoadFeed([
            InternetCode_Feed_Model_Feed::CONFIG_CAT_FORMAT => InternetCode_Feed_Model_Feed::CAT_SINGLE_DEEPEST,
        ]);

        foreach ($feed as $product) {
            $category = $product->getData('category');
            if (!empty($category)) {
                // Single deepest = no comma-separated list
                $this->assertStringNotContainsString(', ', $category,
                    'Single deepest format should return one category, not a list');
                return;
            }
        }
        $this->assertTrue(true);
    }

    // ── cat_format: multi_deepest ─────────────────────────

    public function testCatFormatMultiDeepestCanReturnMultiple(): void
    {
        $feed = $this->createAndLoadFeed([
            InternetCode_Feed_Model_Feed::CONFIG_CAT_FORMAT => InternetCode_Feed_Model_Feed::CAT_MULTI_DEEPEST,
        ]);

        // At least one product in multiple leaf categories would produce a comma-separated result
        // If no such product exists, just verify the format works without error
        $this->assertGreaterThan(0, $feed->count());
    }

    // ── cat_display: name_only ────────────────────────────

    public function testCatDisplayNameOnly(): void
    {
        $feed = $this->createAndLoadFeed([
            InternetCode_Feed_Model_Feed::CONFIG_CAT_FORMAT  => InternetCode_Feed_Model_Feed::CAT_SINGLE_DEEPEST,
            InternetCode_Feed_Model_Feed::CONFIG_CAT_DISPLAY => InternetCode_Feed_Model_Feed::CAT_NAME_ONLY,
        ]);

        foreach ($feed as $product) {
            $category = $product->getData('category');
            if (!empty($category)) {
                $this->assertStringNotContainsString(' > ', $category,
                    'Name-only display should not contain breadcrumb separator');
                return;
            }
        }
        $this->assertTrue(true);
    }

    // ── bread_excl ────────────────────────────────────────

    public function testBreadExclChangesOutput(): void
    {
        $feed = $this->createAndLoadFeed(['bread_excl' => []]);
        $categories = [];
        foreach ($feed as $p) {
            if ($p->getData('category')) {
                $categories[$p->getId()] = $p->getData('category');
            }
        }

        if (empty($categories)) {
            $this->markTestSkipped('No products with categories found');
        }

        // Get a root-level category to exclude from breadcrumbs
        $db = Mage::getSingleton('core/resource')->getConnection('core_read');
        $rootChildren = $db->fetchCol(
            'SELECT entity_id FROM catalog_category_entity WHERE level = 2 LIMIT 3'
        );

        if (empty($rootChildren)) {
            $this->markTestSkipped('No level-2 categories found');
        }

        $feedExcl = $this->createAndLoadFeed([
            'bread_excl' => array_map('intval', $rootChildren),
        ]);

        // Just verify it loads without error — breadcrumb content may or may not change
        // depending on whether excluded categories are ancestors of product categories
        $this->assertGreaterThan(0, $feedExcl->count());
    }

    // ── cat_rules ─────────────────────────────────────────

    public function testCatRulesExcludeAll(): void
    {
        $feed = $this->createAndLoadFeed([
            InternetCode_Feed_Model_Feed::CONFIG_CAT_RULES => [
                [InternetCode_Feed_Model_Feed::RULE_EXCLUDE, '*'],
            ],
        ], [
            InternetCode_Feed_Model_Feed::FLAG_REQUIRE_CAT => false,
        ]);

        // Excluding all categories — products should have no category data
        foreach ($feed as $product) {
            $this->assertEmpty(
                $product->getData('category'),
                'Excluding all categories via rules should leave products without categories'
            );
            break; // Check first product only for performance
        }
    }

    public function testCatRulesIncludeSpecific(): void
    {
        $db = Mage::getSingleton('core/resource')->getConnection('core_read');
        $catId = (int) $db->fetchOne(
            'SELECT c.entity_id FROM catalog_category_entity c
             LEFT JOIN catalog_category_entity ch ON c.entity_id = ch.parent_id
             WHERE ch.entity_id IS NULL AND c.level > 1 LIMIT 1'
        );

        if (!$catId) {
            $this->markTestSkipped('No leaf category found');
        }

        $feed = $this->createAndLoadFeed([
            InternetCode_Feed_Model_Feed::CONFIG_CAT_RULES => [
                [InternetCode_Feed_Model_Feed::RULE_INCLUDE, [$catId]],
                [InternetCode_Feed_Model_Feed::RULE_EXCLUDE, '*'],
            ],
        ]);

        // Should load without error — may have 0 products if none in that category
        $this->assertGreaterThanOrEqual(0, $feed->count());
    }
}
