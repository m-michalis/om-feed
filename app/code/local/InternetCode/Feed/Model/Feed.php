<?php

declare(strict_types=1);

/**
 * Product feed collection for generating XML/CSV feeds with optimized SQL.
 *
 * Extends the product collection to build a single performant query with
 * category breadcrumbs, media gallery, stock status, configurable associations,
 * and URL rewrites pre-joined.
 *
 * Requirements:
 *  - PHP 8.2+
 *  - MySQL 8.0+ or MariaDB 10.2+ (uses WITH RECURSIVE CTE)
 *
 * @see README.md for usage examples
 */
class InternetCode_Feed_Model_Feed extends Mage_Catalog_Model_Resource_Product_Collection
{

    /**
     * Removes children from collection and adds them to their respective parent's 'associated_products'
     */
    const FLAG_ASSOCIATIONS = 'configurable_associations';

    /**
     * Use leaf categories. Only products that exist in end categories (categories without other child categories)
     */
    const FLAG_LEAF_CATS = 'leaf_categories';

    /**
     * If enabled, only products that exist in a category will be returned.
     */
    const FLAG_REQUIRE_CAT = 'require_category';

    /**
     * If enabled, media gallery images are loaded and attached to each product's 'gallery' data key.
     * Disable to skip the gallery query when images are not needed in the feed.
     */
    const FLAG_GALLERY = 'media_gallery';

    // ── Section requirement constants ──────────────────────
    const REQUIRE_STOCK         = 'stock';
    const REQUIRE_CATEGORIES    = 'categories';
    const REQUIRE_GALLERY       = 'gallery';
    const REQUIRE_URL           = 'url';
    const REQUIRE_CONFIGURABLES = 'configurables';
    const REQUIRE_ATTRIBUTES    = 'attributes';
    const REQUIRE_PRICE         = 'price';

    // ── Requirement modes ──────────────────────────────────
    /** Join table AND populate data on product objects */
    const DATA   = 1;
    /** Join table for WHERE/HAVING only — data NOT populated on products */
    const FILTER = 2;

    // ── Category format (which categories, how many) ───────
    /** Deepest-level category the product is assigned to */
    const CAT_SINGLE_DEEPEST = 'single_deepest';
    /** All deepest-per-branch categories (ancestors filtered out) */
    const CAT_MULTI_DEEPEST  = 'multi_deepest';
    /** ALL categories the product is in, including parents */
    const CAT_MULTI_ALL      = 'multi_all';

    // ── Category display (how each category looks) ─────────
    /** Full breadcrumb path, e.g. "A > B > C" */
    const CAT_FULLPATH  = 'fullpath';
    /** Category's own name only, e.g. "C" */
    const CAT_NAME_ONLY = 'name_only';

    // ── Category rule constants ────────────────────────────
    const RULE_INCLUDE = '+';
    const RULE_EXCLUDE = '-';

    /** @var array<int, array{category_id: int, category_level: int, category_path: string, raw_path: string}> */
    private array $_categoryCache = [];

    /** @var array<int, string[]> product_id => [file1, file2, ...] */
    private array $_mediaGallery = [];

    /** @var array<int, string> option_id => option_value */
    private array $_attributeValues = [];

    /** @var string[] attribute codes of user-defined select attributes */
    private array $_dropdownAttributes = [];

    /**
     * Feed configuration array.
     *
     * @var array{bread_excl?: int[], cat_excl?: int[], customer_group?: int, require?: array, cat_format?: string, cat_display?: string, cat_separator?: string, cat_join?: string, cat_rules?: array, bread_rules?: array}
     */
    protected array $_config = [];

    /**
     * Normalized section requirements.
     * null = BC mode (everything loads). When set, only listed sections are loaded.
     *
     * @var array<string, int>|null  section => DATA|FILTER
     */
    private ?array $_requirements = null;

    /** @var int|null */
    protected ?int $_websiteId = null;

    /** @var array<string, array<string|int, int|string>> */
    private array $_attributeCodeIdCache = [];

    /**
     * Categories excluded from breadcrumb path building.
     * Products may still appear in the feed if they belong to other non-excluded categories.
     */
    const CONFIG_BREADCRUMBS_EXCL = 'bread_excl';

    /**
     * Categories excluded from the feed entirely.
     * Products that exist ONLY in these categories will not appear when FLAG_REQUIRE_CAT is enabled.
     */
    const CONFIG_CATS_EXCL = 'cat_excl';

    /**
     * Customer group ID for price index filtering.
     */
    const CONFIG_CUS_GROUP = 'customer_group';

    // ── New config keys ────────────────────────────────────
    const CONFIG_REQUIRE       = 'require';
    const CONFIG_CAT_FORMAT    = 'cat_format';
    const CONFIG_CAT_DISPLAY   = 'cat_display';
    const CONFIG_CAT_SEPARATOR = 'cat_separator';
    const CONFIG_CAT_JOIN      = 'cat_join';
    const CONFIG_CAT_RULES     = 'cat_rules';
    const CONFIG_BREAD_RULES   = 'bread_rules';

    /**
     * @var array<string, bool>
     */
    protected $_flags = [
        'no_stock_data' => true, // Suppresses parent's automatic stock join; stock is joined manually in _beforeLoad()
        self::FLAG_LEAF_CATS => true,
        self::FLAG_REQUIRE_CAT => true,
        self::FLAG_GALLERY => true,
    ];

    /**
     * Product limitation filters
     * Allowed filters
     *  store_id                int;
     *  category_id             int;
     *  category_is_anchor      int;
     *  visibility              array|int;
     *  website_ids             array|int;
     *  store_table             string;
     *  use_price_index         bool;   join price index table flag
     *  customer_group_id       int;    required for price; customer group limitation for price
     *  website_id              int;    required for price; website limitation for price
     *
     * @var array
     */
    protected $_productLimitationFilters = [
        'use_price_index' => true
    ];


    /**
     * Configure the feed collection.
     *
     * Config keys:
     *   'cat_excl'       int[]   Category IDs excluded entirely (BC)
     *   'bread_excl'     int[]   Category IDs excluded from breadcrumbs (BC)
     *   'customer_group' int     Customer group ID for price index
     *   'require'        array   Section whitelist — see REQUIRE_* constants. Mixed format accepted:
     *                            [Feed::REQUIRE_STOCK, Feed::REQUIRE_CATEGORIES => Feed::FILTER]
     *   'cat_format'     string  CAT_SINGLE_DEEPEST | CAT_MULTI_DEEPEST | CAT_MULTI_ALL
     *   'cat_display'    string  CAT_FULLPATH | CAT_NAME_ONLY
     *   'cat_separator'  string  Separator within breadcrumb paths (default ' > ')
     *   'cat_join'       string  Separator between categories in multi modes (default ', ')
     *   'cat_rules'      array   Ordered rules [[RULE_INCLUDE|RULE_EXCLUDE, int[]|'*'], ...]
     *   'bread_rules'    array   Ordered rules for breadcrumb inclusion (same format as cat_rules)
     *
     * @param array $config
     * @return $this
     */
    public function setup(array $config): InternetCode_Feed_Model_Feed
    {
        $this->_config = $config;

        // Normalize require sections: mixed array → section => mode map
        if (isset($config[self::CONFIG_REQUIRE]) && is_array($config[self::CONFIG_REQUIRE])) {
            $this->_requirements = [];
            foreach ($config[self::CONFIG_REQUIRE] as $key => $value) {
                if (is_int($key)) {
                    // Shorthand: REQUIRE_STOCK (value only) → DATA mode
                    $this->_requirements[$value] = self::DATA;
                } else {
                    // Explicit: REQUIRE_CATEGORIES => FILTER
                    $this->_requirements[$key] = $value;
                }
            }
        }

        return $this;
    }

    /**
     * Check whether a section should be loaded (joined/queried).
     * Returns true when require whitelist is absent (BC) or section is listed.
     */
    private function _isRequired(string $section): bool
    {
        return $this->_requirements === null || isset($this->_requirements[$section]);
    }

    /**
     * Check whether a section's data should be populated on product objects.
     * Returns true in BC mode or when the section is required with DATA mode.
     */
    private function _requiresData(string $section): bool
    {
        if ($this->_requirements === null) {
            return true;
        }
        return ($this->_requirements[$section] ?? 0) === self::DATA;
    }

    /**
     * Compile rsync-style ordered rules into include/exclude ID sets for SQL.
     *
     * Rules are evaluated in order — first match wins. Wildcard '*' matches all remaining.
     * Returns ['include' => int[], 'exclude' => int[], 'default_include' => bool].
     *
     * @param array $rules [[RULE_INCLUDE|RULE_EXCLUDE, int|int[]|'*'], ...]
     * @return array{include: int[], exclude: int[], default_include: bool}
     */
    private function _compileCategoryRules(array $rules): array
    {
        $resolved = []; // id => action — first match wins (rsync semantics)
        $defaultInclude = true; // BC: include all if no wildcard rule

        foreach ($rules as $rule) {
            if (!is_array($rule) || count($rule) < 2) {
                continue;
            }
            [$action, $target] = $rule;

            if ($target === '*') {
                $defaultInclude = ($action === self::RULE_INCLUDE);
                break; // wildcard terminates rule evaluation
            }

            $ids = is_array($target) ? array_map('intval', $target) : [(int)$target];
            foreach ($ids as $id) {
                if (!isset($resolved[$id])) {
                    $resolved[$id] = $action;
                }
            }
        }

        $include = [];
        $exclude = [];
        foreach ($resolved as $id => $action) {
            if ($action === self::RULE_INCLUDE) {
                $include[] = $id;
            } else {
                $exclude[] = $id;
            }
        }

        return [
            'include'         => $include,
            'exclude'         => $exclude,
            'default_include' => $defaultInclude,
        ];
    }


    /**
     * @return $this
     * @throws Mage_Core_Exception
     * @throws Zend_Db_Exception
     * @throws Zend_Db_Select_Exception
     */
    protected function _beforeLoad()
    {
        $urlRequired = $this->_isRequired(self::REQUIRE_URL);
        $catRequired = $this->_isRequired(self::REQUIRE_CATEGORIES);

        // Category cache is needed for URL joins (category-filtered URLs) and category data
        if ($catRequired || $urlRequired) {
            $this->_gatherCategories();
        }

        if ($this->_isRequired(self::REQUIRE_ATTRIBUTES)) {
            $this->_gatherAttributeOptions();
        }

        // Status filter always applies — products must be enabled regardless of require config
        $this->addAttributeToFilter('status',
            ['eq' => Mage_Catalog_Model_Product_Status::STATUS_ENABLED]);

        // ── Price index ──
        // use_price_index is silently wiped by parent constructor (_initSelect → _initLimitationFilters).
        // Must be set explicitly here before calling _productLimitationPrice().
        if ($this->_isRequired(self::REQUIRE_PRICE)) {
            $this->_productLimitationFilters['use_price_index'] = true;
            $this->_productLimitationFilters['customer_group_id'] = $this->_config[self::CONFIG_CUS_GROUP] ?? Mage_Customer_Model_Group::NOT_LOGGED_IN_ID;
            $this->_productLimitationFilters['website_id'] = $this->getWebsiteId();
            $this->_productLimitationPrice();
        } else {
            $this->_productLimitationFilters['use_price_index'] = false;
        }

        // Increase GROUP_CONCAT limit when aggregate joins are active
        if ($catRequired || $this->_isRequired(self::REQUIRE_CONFIGURABLES)) {
            $this->_getReadAdapter()->query('SET SESSION group_concat_max_len = 65536');
        }

        // ── Configurable joins ──
        if ($this->_isRequired(self::REQUIRE_CONFIGURABLES)) {
            $this->joinField(
                'item_group_id',
                'catalog/product_super_link',
                'parent_id',
                'product_id = entity_id',
                null,
                'left');

            $this->joinField(
                'super_attribute_ids',
                'catalog/product_super_attribute',
                new Zend_Db_Expr('GROUP_CONCAT(DISTINCT at_super_attribute_ids.attribute_id)'),
                'product_id = item_group_id',
                null,
                'left');
        }

        // ── URL + Category joins (decoupled) ──
        $joinType = $this->getFlag(self::FLAG_REQUIRE_CAT) ? 'inner' : 'left';

        if ($urlRequired && $catRequired) {
            // Case 1: Both — URL filtered by category, category index refs URL rewrite
            $categoryInClause = $this->_getCategoryInClause();

            $this->joinField(
                'request_path_full',
                'core/url_rewrite',
                null,
                'product_id = entity_id',
                'at_request_path_full.store_id = ' . $this->getStoreId() . ' AND at_request_path_full.category_id IN (' . $categoryInClause . ')',
                $joinType);

            $this->joinField(
                'request_path',
                'core/url_rewrite',
                new Zend_Db_Expr('COALESCE(`at_request_path_full`.`request_path`,`at_request_path`.`request_path`)'),
                'product_id = entity_id',
                'at_request_path.store_id = ' . $this->getStoreId() . ' AND at_request_path.category_id IS NULL',
                'left');

            $this->joinField(
                'category_ids',
                'catalog/category_product_index',
                new Zend_Db_Expr('GROUP_CONCAT(DISTINCT at_category_ids.category_id)'),
                'product_id = entity_id',
                'at_category_ids.category_id = at_request_path_full.category_id',
                $joinType);

        } elseif ($urlRequired) {
            // Case 2: URL only — product-only URL without category context
            $this->joinField(
                'request_path',
                'core/url_rewrite',
                'request_path',
                'product_id = entity_id',
                'at_request_path.store_id = ' . $this->getStoreId() . ' AND at_request_path.category_id IS NULL',
                'left');

        } elseif ($catRequired) {
            // Case 3: Categories only — direct category index join (no URL rewrite dependency)
            $categoryInClause = $this->_getCategoryInClause();

            $this->joinField(
                'category_ids',
                'catalog/category_product_index',
                new Zend_Db_Expr('GROUP_CONCAT(DISTINCT at_category_ids.category_id)'),
                'product_id = entity_id',
                'at_category_ids.category_id IN (' . $categoryInClause . ')',
                $joinType);
        }
        // Case 4: Neither — skip both

        // ── Stock join ──
        if ($this->_isRequired(self::REQUIRE_STOCK)) {
            $this->joinTable(
                ['stock' => 'cataloginventory/stock_status'],
                'product_id=entity_id',
                ['quantity' => 'qty', 'stock_status' => 'stock_status'],
                'stock.stock_id=' . Mage_CatalogInventory_Model_Stock::DEFAULT_STOCK_ID . ' AND stock.website_id=' . $this->getWebsiteId(),
                'left'
            );
        }

        $this->getSelect()->group('entity_id');
        return parent::_beforeLoad();
    }

    /**
     * Build a safe SQL IN clause from the category cache keys.
     * Returns '0' when the cache is empty to prevent empty IN() syntax error.
     */
    private function _getCategoryInClause(): string
    {
        $categoryIds = array_keys($this->_categoryCache);
        return empty($categoryIds) ? '0' : implode(',', $categoryIds);
    }


    /**
     * Disabled: URL rewrites are joined manually in _beforeLoad() with category-aware logic.
     * Suppresses parent's addUrlRewrite() which would add conflicting joins.
     *
     * @param $categoryId
     * @return $this
     */
    public function addUrlRewrite($categoryId = '')
    {
        return $this;
    }


    /**
     * Generates category_id indexed array with values the full breadcrumb of each category.
     * Only returns leaf categories when FLAG_LEAF_CATS is enabled.
     *
     * Uses a WITH RECURSIVE CTE - requires MySQL 8.0+ or MariaDB 10.2+.
     *
     * @return void
     */
    private function _gatherCategories()
    {
        if (!empty($this->_categoryCache)) {
            return;
        }

        // ── Build category exclusion/inclusion from rules or BC fallback ──
        $skipCategories = [];
        $onlyCategories = [];

        if (isset($this->_config[self::CONFIG_CAT_RULES]) || isset($this->_config[self::CONFIG_BREAD_RULES])) {
            $catCompiled = isset($this->_config[self::CONFIG_CAT_RULES])
                ? $this->_compileCategoryRules($this->_config[self::CONFIG_CAT_RULES])
                : ['include' => [], 'exclude' => [], 'default_include' => true];
            $breadCompiled = isset($this->_config[self::CONFIG_BREAD_RULES])
                ? $this->_compileCategoryRules($this->_config[self::CONFIG_BREAD_RULES])
                : ['include' => [], 'exclude' => [], 'default_include' => true];

            // Exclude: union of both exclude lists
            $skipCategories = array_unique(array_merge($catCompiled['exclude'], $breadCompiled['exclude']));

            // Include: most restrictive wins
            if (!$catCompiled['default_include'] && !$breadCompiled['default_include']) {
                $onlyCategories = array_intersect($catCompiled['include'], $breadCompiled['include']);
            } elseif (!$catCompiled['default_include']) {
                $onlyCategories = $catCompiled['include'];
            } elseif (!$breadCompiled['default_include']) {
                $onlyCategories = $breadCompiled['include'];
            }

            // Restrictive mode with no matching IDs → force empty result
            $needsInclusion = !$catCompiled['default_include'] || !$breadCompiled['default_include'];
            if ($needsInclusion && empty($onlyCategories)) {
                $onlyCategories = [0];
            }
        } else {
            // BC fallback: merge cat_excl + bread_excl
            if (isset($this->_config[self::CONFIG_BREADCRUMBS_EXCL]) && is_array($this->_config[self::CONFIG_BREADCRUMBS_EXCL])) {
                $skipCategories = array_merge($skipCategories, $this->_config[self::CONFIG_BREADCRUMBS_EXCL]);
            }
            if (isset($this->_config[self::CONFIG_CATS_EXCL]) && is_array($this->_config[self::CONFIG_CATS_EXCL])) {
                $skipCategories = array_merge($skipCategories, $this->_config[self::CONFIG_CATS_EXCL]);
            }
            $skipCategories = array_unique(array_map('intval', $skipCategories));
        }

        // ── Category subquery: eligible category IDs ──
        $deepestCategorySelect = $this->_newSelect('catalog/category', 'c1')
            ->joinLeft(
                ['c2' => $this->getTable('catalog/category')],
                'c1.entity_id = c2.parent_id'
            )
            ->reset(Zend_Db_Select::COLUMNS)
            ->columns(['c1.entity_id']);

        // Leaf filter: only when no explicit cat_format is set (BC) and FLAG_LEAF_CATS is on.
        // All new format modes need non-leaf categories for per-product deepest-level logic.
        $catFormat = $this->_config[self::CONFIG_CAT_FORMAT] ?? null;
        if ($catFormat === null && $this->getFlag(self::FLAG_LEAF_CATS)) {
            $deepestCategorySelect->where('c2.entity_id IS NULL');
        }

        if (!empty($skipCategories)) {
            $deepestCategorySelect->where('c1.entity_id NOT IN (?)', $skipCategories);
        }
        if (!empty($onlyCategories)) {
            $deepestCategorySelect->where('c1.entity_id IN (?)', $onlyCategories);
        }

        // ── CTE: build breadcrumb paths with configurable separator ──
        $nameID = (int) Mage::getModel('catalog/category')->getResource()->getAttribute('name')->getId();
        $storeID = (int) $this->getStore()->getId();

        $categoryTable = $this->getTable('catalog/category');
        $categoryVarcharTable = $categoryTable . '_varchar';
        $separator = str_replace("'", "\\'", $this->_config[self::CONFIG_CAT_SEPARATOR] ?? ' > ');

        $crumbsQuery = new Zend_Db_Expr("WITH RECURSIVE
    seq(n) AS (
        SELECT 3
        UNION ALL
        SELECT n + 1
        FROM seq
        WHERE n < 10)
SELECT ct.entity_id AS category_id,
       ct.level     AS category_level,
       ct.path      AS raw_path,

       GROUP_CONCAT(
               COALESCE(cn_store.value, cn_default.value)
               ORDER BY n SEPARATOR '{$separator}'
       )            AS category_path

FROM {$categoryTable} ct
         JOIN seq ON CHAR_LENGTH(ct.path) - CHAR_LENGTH(REPLACE(ct.path, '/', '')) >= n - 1
         LEFT JOIN {$categoryVarcharTable} cn_store
                   ON cn_store.entity_id = SUBSTRING_INDEX(SUBSTRING_INDEX(ct.path, '/', n), '/', -1)
                       AND cn_store.attribute_id = {$nameID}
                       AND cn_store.store_id = {$storeID}
         LEFT JOIN {$categoryVarcharTable} cn_default
                   ON cn_default.entity_id = SUBSTRING_INDEX(SUBSTRING_INDEX(ct.path, '/', n), '/', -1)
                       AND cn_default.attribute_id = {$nameID}
                       AND cn_default.store_id = 0

WHERE ct.entity_id IN ($deepestCategorySelect)
GROUP BY ct.entity_id, ct.path;");

        $this->_categoryCache = $this->_getReadAdapter()->fetchAssoc($crumbsQuery);
    }

    /**
     * Processing collection items after loading
     *
     * @return $this
     */
    protected function _afterLoad()
    {
        // Determine what to process — require takes precedence over flags when set
        $loadGallery = $this->_requirements !== null
            ? $this->_requiresData(self::REQUIRE_GALLERY)
            : $this->getFlag(self::FLAG_GALLERY);

        if ($loadGallery) {
            $this->_loadMediaGallery();
        }

        $fillCategories = $this->_requiresData(self::REQUIRE_CATEGORIES);
        $fillAttributes = $this->_requiresData(self::REQUIRE_ATTRIBUTES);

        foreach ($this->getItems() as $item) {
            if ($loadGallery && isset($this->_mediaGallery[$item->getId()])) {
                $item->setData('gallery', $this->_mediaGallery[$item->getId()]);
            }

            if ($fillCategories) {
                $this->_resolveCategories($item);
            }

            if ($fillAttributes) {
                foreach ($this->_dropdownAttributes as $attrCode) {
                    if (isset($this->_attributeValues[$item->getData($attrCode)])) {
                        $item->setData($attrCode, $this->_attributeValues[$item->getData($attrCode)]);
                    }
                }
            }
        }

        // Configurable association regrouping — requires both CONFIGURABLES data and FLAG_ASSOCIATIONS
        $doAssociations = $this->_requiresData(self::REQUIRE_CONFIGURABLES) && $this->getFlag(self::FLAG_ASSOCIATIONS);
        if ($doAssociations) {
            foreach ($this->getItems() as $item) {
                if ($item->getData('item_group_id') && $item->getData('item_group_id') > 0) {
                    if ($parentItem = $this->getItemById($item->getItemGroupId())) {
                        $assoc = $parentItem->getData('associated_products');
                        if ($assoc === null) {
                            $assoc = [];
                        }
                        $assoc[] = $item;
                        $parentItem->setData('associated_products', $assoc);
                        $parentItem->unsetData('quantity');
                    }
                    $this->removeItemByKey($item->getId());
                }
            }
        }

        if (count($this) > 0) {
            Mage::dispatchEvent('catalog_product_collection_load_after', ['collection' => $this]);
        }
        return $this;
    }

    /**
     * Resolve category data for a product based on cat_format and cat_display config.
     *
     * Format controls WHICH categories:
     *   single_deepest — deepest-level category the product is assigned to
     *   multi_deepest  — all deepest per branch (ancestors filtered via raw_path)
     *   multi_all      — all categories the product is in
     *   (default)      — first matching category (BC)
     *
     * Display controls HOW each category looks:
     *   fullpath  — full breadcrumb path "A / B / C"
     *   name_only — leaf segment only "C"
     */
    private function _resolveCategories(Varien_Object $item): void
    {
        $format    = $this->_config[self::CONFIG_CAT_FORMAT] ?? null;
        $display   = $this->_config[self::CONFIG_CAT_DISPLAY] ?? self::CAT_FULLPATH;
        $separator = $this->_config[self::CONFIG_CAT_SEPARATOR] ?? ' > ';
        $join      = $this->_config[self::CONFIG_CAT_JOIN] ?? ', ';

        $rawCatIds = (string)$item->getData('category_ids');
        if ($rawCatIds === '') {
            return;
        }

        $categoryIds = array_filter(explode(',', $rawCatIds), 'strlen');
        $matches = array_intersect_key($this->_categoryCache, array_flip($categoryIds));

        if (empty($matches)) {
            return;
        }

        // ── Format: select which categories ──
        switch ($format) {
            case self::CAT_SINGLE_DEEPEST:
                uasort($matches, fn($a, $b) => $b['category_level'] <=> $a['category_level']);
                $firstKey = array_key_first($matches);
                $matches = [$firstKey => $matches[$firstKey]];
                break;

            case self::CAT_MULTI_DEEPEST:
                $filtered = [];
                foreach ($matches as $id => $cat) {
                    $isAncestor = false;
                    foreach ($matches as $otherId => $otherCat) {
                        if ($id !== $otherId
                            && str_starts_with($otherCat['raw_path'], $cat['raw_path'] . '/')) {
                            $isAncestor = true;
                            break;
                        }
                    }
                    if (!$isAncestor) {
                        $filtered[$id] = $cat;
                    }
                }
                $matches = $filtered;
                break;

            case self::CAT_MULTI_ALL:
                break; // keep all

            default:
                // BC: first match
                $firstKey = array_key_first($matches);
                $matches = [$firstKey => $matches[$firstKey]];
                break;
        }

        // ── Display: format each category value ──
        $values = [];
        foreach ($matches as $cat) {
            if ($display === self::CAT_NAME_ONLY) {
                $lastPos = strrpos($cat['category_path'], $separator);
                $values[] = $lastPos !== false
                    ? substr($cat['category_path'], $lastPos + strlen($separator))
                    : $cat['category_path'];
            } else {
                $values[] = $cat['category_path'];
            }
        }

        $isMulti = in_array($format, [self::CAT_MULTI_DEEPEST, self::CAT_MULTI_ALL], true);
        $item->setData('category', $isMulti ? implode($join, $values) : ($values[0] ?? null));
    }


    /**
     * @return Varien_Db_Adapter_Interface
     */
    private function _getReadAdapter(): Varien_Db_Adapter_Interface
    {
        return Mage::getSingleton('core/resource')->getConnection('core_read');
    }

    /**
     * @param string $tableFrom
     * @param string|null $alias
     * @return Varien_Db_Select
     */
    private function _newSelect(string $tableFrom, string $alias = null): Varien_Db_Select
    {
        return $this->_getReadAdapter()->select()
            ->from([$alias ?? "main_table" => $this->getTable($tableFrom)]);
    }


    /**
     * @param $websiteId
     * @return $this
     */
    public function setWebsiteId($websiteId)
    {
        if ($websiteId instanceof Mage_Core_Model_Website) {
            $websiteId = $websiteId->getId();
        }
        $this->_websiteId = (int)$websiteId;
        return $this;
    }

    /**
     * @return int
     */
    private function getWebsiteId(): int
    {
        if ($this->_websiteId === null) {
            $this->setWebsiteId($this->getStore()->getWebsiteId());
        }
        return $this->_websiteId;
    }

    /**
     * @return Mage_Core_Model_Store|null
     * @throws Mage_Core_Model_Store_Exception
     */
    public function getStore()
    {
        return Mage::app()->getStore($this->getStoreId());
    }

    /**
     * Load media gallery images scoped to the current collection's product IDs.
     *
     * @return void
     * @throws Mage_Core_Exception
     */
    private function _loadMediaGallery()
    {
        $productIds = array_keys($this->_items);
        if (empty($productIds)) {
            return;
        }

        $mediaGalleryId = Mage::getModel('catalog/resource_eav_attribute')
            ->loadByCode(Mage_Catalog_Model_Product::ENTITY, 'media_gallery')
            ->getBackend()
            ->getAttribute()
            ->getId();

        $allMediaSelect = $this->_newSelect(Mage_Catalog_Model_Resource_Product_Attribute_Backend_Media::GALLERY_TABLE, 'media_main')
            ->reset(Zend_Db_Select::COLUMNS)
            ->columns([
                'entity_id',
                'file' => 'value',
            ])
            ->joinLeft(
                ['media_value' => $this->getTable(Mage_Catalog_Model_Resource_Product_Attribute_Backend_Media::GALLERY_VALUE_TABLE)],
                $this->_getReadAdapter()->quoteInto('media_main.value_id = media_value.value_id AND media_value.store_id = ?',
                    $this->getStoreId()),
                []
            )
            ->joinLeft( // Joining default values
                ['media_default' => $this->getTable(Mage_Catalog_Model_Resource_Product_Attribute_Backend_Media::GALLERY_VALUE_TABLE)],
                'media_main.value_id = media_default.value_id AND media_default.store_id = 0',
                []
            )
            ->where('media_main.attribute_id = ?', $mediaGalleryId)
            ->where('media_main.entity_id IN (?)', $productIds);


        $this->_mediaGallery = [];
        foreach ($this->_getReadAdapter()->fetchAll($allMediaSelect) as $image) {
            if (!isset($this->_mediaGallery[$image['entity_id']])) {
                $this->_mediaGallery[$image['entity_id']] = [];
            }
            $this->_mediaGallery[$image['entity_id']][] = $image['file'];
        }
    }

    /**
     * Load option values for user-defined select attributes, scoped to their attribute IDs.
     *
     * @return void
     */
    private function _gatherAttributeOptions()
    {
        $select = $this->_getReadAdapter()->select()
            ->from(['ea' => $this->getTable('eav/attribute')])
            ->reset(Zend_Db_Select::COLUMNS)
            ->columns(['attribute_id', 'attribute_code'])
            ->where('frontend_input = ?', 'select')
            ->where('is_user_defined = ?', 1);

        $dropdownAttrs = $this->_getReadAdapter()->fetchPairs($select);
        $this->_dropdownAttributes = array_values($dropdownAttrs);

        if (empty($dropdownAttrs)) {
            return;
        }

        $attributeIds = array_keys($dropdownAttrs);

        $select = $this->_getReadAdapter()->select()
            ->from(['eao' => $this->getTable('eav/attribute_option')])
            ->reset(Zend_Db_Select::COLUMNS)
            ->joinInner(
                ['eaov_default' => $this->getTable('eav/attribute_option_value')],
                'eao.option_id = eaov_default.option_id AND eaov_default.store_id = 0',
                []
            )
            ->joinLeft(
                ['eaov' => $this->getTable('eav/attribute_option_value')],
                $this->_getReadAdapter()->quoteInto(
                    'eao.option_id = eaov.option_id AND eaov.store_id = ?',
                    $this->getStoreId()
                ),
                []
            )
            ->columns([
                'option_id' => 'eao.option_id',
                'value' => new Zend_Db_Expr('COALESCE(eaov.value, eaov_default.value)')
            ])
            ->where('eao.attribute_id IN (?)', $attributeIds);

        $this->_attributeValues = $this->_getReadAdapter()->fetchPairs($select);
    }

    /**
     * Get indexed array of attribute_code => attribute_id for user-defined select attributes
     *
     * @param bool $reverse If true, returns attribute_id => attribute_code instead
     * @return array
     */
    public function getAttributeCodeToId($reverse = false)
    {
        return $this->_fetchAttributeCodeIdMap($reverse);
    }

    /**
     * Get indexed array of attribute_id => attribute_code for user-defined select attributes
     *
     * @return array<int, string>
     */
    public function getAttributeIdToCode(): array
    {
        return $this->_fetchAttributeCodeIdMap(true);
    }

    /**
     * @param bool $reverse If true, returns attribute_id => attribute_code
     * @return array
     */
    private function _fetchAttributeCodeIdMap(bool $reverse = false): array
    {
        $cacheKey = $reverse ? 'reverse' : 'no_reverse';
        if (isset($this->_attributeCodeIdCache[$cacheKey])) {
            return $this->_attributeCodeIdCache[$cacheKey];
        }

        $select = $this->_getReadAdapter()->select()
            ->from(['ea' => $this->getTable('eav/attribute')])
            ->reset(Zend_Db_Select::COLUMNS)
            ->where('frontend_input = ?', 'select')
            ->where('is_user_defined = ?', 1);

        if ($reverse) {
            $select->columns([
                'attribute_id',
                'attribute_code'
            ]);
        } else {
            $select->columns([
                'attribute_code',
                'attribute_id'
            ]);
        }

        $this->_attributeCodeIdCache[$cacheKey] = $this->_getReadAdapter()->fetchPairs($select);
        return $this->_attributeCodeIdCache[$cacheKey];
    }


    /**
     * Generate a feed file by iterating the collection and writing via the provided callback.
     *
     * Writes to a .tmp file first, then atomically moves to the final path.
     * Dispatches omfeed_insert_headers_{handlerName} and omfeed_insert_footer_{handlerName} events.
     *
     * WARNING: $feedPath is used as-is with no path traversal protection.
     * Callers MUST ensure the path is safe and within the intended directory.
     *
     * @param string $handlerName Event handler suffix (must be a developer-controlled constant)
     * @param string $feedPath Absolute path for the output file
     * @param callable $mappingCallBack function(Varien_Io_File $io, Mage_Catalog_Model_Product $product)
     * @param bool $zip Whether to also create a .zip archive of the feed
     * @throws Mage_Core_Exception
     * @throws Throwable Re-thrown from callback failures after .tmp cleanup
     */
    public function generate(string $handlerName, string $feedPath, callable $mappingCallBack, bool $zip = false)
    {
        /* ----------------------------------------------------------
         * 1.  I/O setup
         * -------------------------------------------------------- */
        $dir = dirname($feedPath);
        $io = new Varien_Io_File();
        $io->open(['path' => $dir]);
        $io->setAllowCreateFolders(true);
        $io->streamOpen($feedPath . '.tmp', 'w+');

        if ($io->fileExists($feedPath) && !$io->isWriteable($feedPath)) {
            Mage::throwException(sprintf(
                'File cannot be saved. Please, make sure the directory "%s" is writeable by web server.',
                $feedPath
            ));
        }
        if ($io->fileExists($feedPath . '.tmp') && !$io->isWriteable($feedPath . '.tmp')) {
            Mage::throwException(sprintf(
                'File cannot be saved. Please, make sure the directory "%s" is writeable by web server.',
                $feedPath . '.tmp'
            ));
        }

        /* ----------------------------------------------------------
         * 2.  Write header + body + footer with cleanup on failure
         * -------------------------------------------------------- */
        try {
            Mage::dispatchEvent('omfeed_insert_headers_' . $handlerName, [
                'io' => $io
            ]);

            foreach ($this as $product) {
                /** @var Mage_Catalog_Model_Product $product */
                $mappingCallBack($io, $product);
            }

            Mage::dispatchEvent('omfeed_insert_footer_' . $handlerName, [
                'io' => $io
            ]);
        } catch (Throwable $e) {
            $io->streamClose();
            @unlink($feedPath . '.tmp');
            throw $e;
        }

        /* ----------------------------------------------------------
         * 3.  Close stream and atomically move to final path
         * -------------------------------------------------------- */
        $io->streamClose();
        $io->mv($feedPath . '.tmp', $feedPath);

        /* ----------------------------------------------------------
         * 4.  Optional zip archive
         * -------------------------------------------------------- */
        if ($zip && class_exists('ZipArchive')) {
            $zipArchive = new ZipArchive();
            $zipResult = $zipArchive->open(
                $feedPath . '.zip',
                ZipArchive::OVERWRITE | ZipArchive::CREATE
            );
            if ($zipResult === true) {
                $zipArchive->addFile($feedPath, basename($feedPath));
                $zipArchive->close();
            } else {
                Mage::log(
                    sprintf('om-feed: Failed to create zip archive for %s (ZipArchive error code: %s)', $feedPath, $zipResult),
                    Zend_Log::WARN
                );
            }
        }
    }
}
