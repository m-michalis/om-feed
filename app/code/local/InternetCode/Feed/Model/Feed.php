<?php

class InternetCode_Feed_Model_Feed extends Mage_Catalog_Model_Resource_Product_Collection
{

    /**
     * removes children from collection and adds them to their respective parent's 'associated_products'
     */
    const FLAG_ASSOCIATIONS = 'configurable_associations';

    /**
     * Use leaf categories. Only products that exist in end categories (categories without other child categories)
     */
    const FLAG_LEAF_CATS = 'leaf_categories';
    /** @var array */
    private $_categoryCache = [];

    /** @var array */
    private $_mediaGallery = [];

    /** @var array */
    private $_attributeValues = [];
    /** @var array */
    private $_dropdownAttributes = [];

    /**
     *
     */
    const CONFIG_BREADCRUMBS_EXCL = 'bread_excl';
    /**
     *
     */
    const CONFIG_CATS_EXCL = 'cat_excl';
    /**
     *
     */
    const CONFIG_CUS_GROUP = 'customer_group';

    /**
     * @var true[]
     */
    protected $_flags = [
        'no_stock_data' => true,
        'leaf_categories' => true
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
     * [
     *      'cat_excl' => array        // products in these category ids are excluded
     *      'bread_excl' => array      // these category ids are excluded for urls/breadcrumbs
     * ]
     *
     *
     * @param array $config
     * @return $this
     */
    public function setup(array $config): InternetCode_Feed_Model_Feed
    {
        $this->_config = $config;
        return $this;
    }


    /**
     * @return $this
     * @throws Mage_Core_Exception
     * @throws Zend_Db_Exception
     * @throws Zend_Db_Select_Exception
     */
    protected function _beforeLoad()
    {
        $this->_gatherCategories();
        $this->_gatherAttributeOptions();

        $this->addAttributeToFilter('status',
            ['eq' => Mage_Catalog_Model_Product_Status::STATUS_ENABLED]);

        $this->_productLimitationFilters['customer_group_id'] = $this->_config[self::CONFIG_CUS_GROUP] ?? Mage_Customer_Model_Group::NOT_LOGGED_IN_ID;
        $this->_productLimitationFilters['website_id'] = $this->getWebsiteId();

        $this->_productLimitationPrice();


        /**
         *
         * [LEFT] JOIN PARENT ID OF CONFIGURABLE CHILDREN
         *
         */
        $this->joinField(
            'item_group_id',
            'catalog/product_super_link',
            'parent_id',
            'product_id = entity_id',
            null,
            'left');

        /**
         *
         * [LEFT] JOIN SUPER ATTRIBUTE IDS
         *
         */
        $superAttrConcatSelect = Mage::getResourceModel('catalog/product_type_configurable_attribute_collection')
            ->getSelect()
            ->reset(Varien_Db_Select::COLUMNS)
            ->columns([
                'product_id',
                'super_attribute_ids' => new Zend_Db_Expr('GROUP_CONCAT(attribute_id)')
            ])
            ->group('product_id');
        $this->joinTable(
            ['super_attr' => new Zend_Db_Expr(" ( $superAttrConcatSelect ) ")],
            'product_id = entity_id',
            'super_attribute_ids',
            null,
            'left');


        /**
         *
         * [INNER] JOIN URL
         *
         */
        $this->joinField(
            'request_path',
            'core/url_rewrite',
            'request_path',
            'product_id = entity_id',
            'at_request_path.category_id IN (' . implode(',', array_keys($this->_categoryCache)) . ')',
            'inner');


        /**
         *
         * [INNER] JOIN INSTOCK
         *
         */
        $this->joinTable(
            ['stock' => 'cataloginventory/stock_status'],
            'product_id=entity_id',
            ['quantity' => 'qty', 'stock_status' => 'stock_status'],
            'stock.stock_id=' . Mage_CatalogInventory_Model_Stock::DEFAULT_STOCK_ID . ' AND stock.website_id=' . $this->getWebsiteId(),
            'left'
        );

        /**
         *
         * [INNER] JOIN CATEGORIES
         *
         */
        $this->joinField(
            'category_ids',
            'catalog/category_product_index',
            new Zend_Db_Expr('GROUP_CONCAT(at_category_ids.category_id)'),
            'product_id = entity_id',
            'at_category_ids.category_id IN (' . implode(',', array_keys($this->_categoryCache)) . ')',
            'inner');

        $this->getSelect()->group('entity_id');
        return parent::_beforeLoad();
    }


    /**
     * @param $categoryId
     * @return $this|InternetCode_Feed_Model_Feed
     */
    public function addUrlRewrite($categoryId = '')
    {
        // disabled
        return $this;
    }


    /**
     * Generates category_id indexed array with values the full breadcrumb of each category.
     * Only returns leaf categories.
     *
     * @return void
     */
    private function _gatherCategories()
    {
        //todo: log for products that do not exist in some leaf category and only exist in parent
        $skipCategories = [];
        if (isset($this->_config[self::CONFIG_BREADCRUMBS_EXCL]) && is_array($this->_config[self::CONFIG_BREADCRUMBS_EXCL])) {
            $skipCategories = array_merge($skipCategories, $this->_config[self::CONFIG_BREADCRUMBS_EXCL]);
        }
        if (isset($this->_config[self::CONFIG_CATS_EXCL]) && is_array($this->_config[self::CONFIG_CATS_EXCL])) {
            $skipCategories = array_merge($skipCategories, $this->_config[self::CONFIG_CATS_EXCL]);
        }
        $skipCategories = array_unique($skipCategories);

        $deepestCategorySelect = $this->_newSelect('catalog/category', 'c1')
            ->joinLeft(
                ['c2' => $this->getTable('catalog/category')],
                'c1.entity_id = c2.parent_id'
            )
            ->reset(Zend_Db_Select::COLUMNS)
            ->columns(['c1.entity_id']);
        if (isset($this->_flags[self::FLAG_LEAF_CATS]) && $this->_flags[self::FLAG_LEAF_CATS]) {
            $deepestCategorySelect->where('c2.entity_id IS NULL');
        }
        if (count($skipCategories)) {
            $deepestCategorySelect->where('c1.entity_id NOT IN (' . implode(',', $skipCategories) . ')');
        }

        $nameID = Mage::getModel('catalog/category')->getResource()->getAttribute('name')->getId();
        $rootID = $this->getStore()->getRootCategoryId();

        $crumbsQuery = new Zend_Db_Expr("SELECT * FROM (
WITH RECURSIVE seq(n) AS (SELECT 1
                          UNION ALL
                          SELECT n + 1
                          FROM seq
                          WHERE n < 10)
SELECT ct.entity_id                                      as category_id,
       ct.level                                          as category_level,
       GROUP_CONCAT(cn.value ORDER BY n SEPARATOR ' > ') AS category_path
FROM catalog_category_entity ct
         JOIN seq ON CHAR_LENGTH(ct.path) - CHAR_LENGTH(REPLACE(ct.path, '/', '')) >= n - 1
         JOIN catalog_category_entity_varchar cn
              ON cn.entity_id = SUBSTRING_INDEX(SUBSTRING_INDEX(ct.path, '/', n), '/', -1) and
                 attribute_id = $nameID and cn.entity_id > $rootID
GROUP BY ct.entity_id, ct.path) categories WHERE category_id in ( $deepestCategorySelect )");

        $this->_categoryCache = $this->_getReadAdapter()->fetchAssoc($crumbsQuery);

    }

    /**
     * Processing collection items after loading
     *
     * @return $this
     */
    protected function _afterLoad()
    {
        $this->_loadMediaGallery();
        foreach ($this->getItems() as $item) {
            /**
             * Fill Gallery
             */
            if (isset($this->_mediaGallery[$item->getId()])) {
                $item->setData('gallery', $this->_mediaGallery[$item->getId()]);
            }


            /**
             * Fill Category
             */
            $commonCategoryIds = array_values(array_intersect(array_keys($this->_categoryCache), explode(',', $item->getData('category_ids'))));
            if (isset($commonCategoryIds[0])) {
                $item->setData('category', $this->_categoryCache[$commonCategoryIds[0]]['category_path']);
            }


            /**
             * Fill attribute values
             */
            foreach ($this->_dropdownAttributes as $attrCode) {
                if (isset($this->_attributeValues[$item->getData($attrCode)])) {
                    $item->setData($attrCode, $this->_attributeValues[$item->getData($attrCode)]);
                }
            }
        }

        if ($this->getFlag(self::FLAG_ASSOCIATIONS)) {
            foreach ($this->getItems() as $item) {
                if ($item->getData('item_group_id') && $item->getData('item_group_id') > 0) {
                    if ($parentItem = $this->getItemById($item->getItemGroupId())) {
                        $assoc = $parentItem->getData('associated_products');
                        if (is_null($assoc)) {
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
    private function getWebsiteId()
    {
        if (is_null($this->_websiteId)) {
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
     * @return void
     * @throws Mage_Core_Exception
     */
    private function _loadMediaGallery()
    {
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
            ->where('media_main.attribute_id = ?', $mediaGalleryId);


        $this->_mediaGallery = [];
        foreach ($this->_getReadAdapter()->fetchAll($allMediaSelect) as $image) {
            if (!isset($this->_mediaGallery[$image['entity_id']])) {
                $this->_mediaGallery[$image['entity_id']] = [];
            }
            $this->_mediaGallery[$image['entity_id']][] = $image['file'];
        }
    }

    /**
     * @return void
     */
    private function _gatherAttributeOptions()
    {

        $select = $this->_getReadAdapter()->select()
            ->from(['eao' => $this->getTable('eav/attribute_option')])
            ->reset(Zend_Db_Select::COLUMNS)
            ->joinInner(
                ['eaov_default' => $this->getTable('eav/attribute_option_value')],
                'eao.option_id = eaov_default.option_id and eaov_default.store_id = 0',
                []
            )
            ->joinLeft(
                ['eaov' => $this->getTable('eav/attribute_option_value')],
                'eao.option_id = eaov.option_id and eaov.store_id = ' . $this->getStoreId(),
                []
            )
            ->columns([
                'option_id' => 'eao.option_id',
                'value' => new Zend_Db_Expr('COALESCE(eaov.value, eaov_default.value)')
            ]);
        //
//        'attr.option_id=main.option_id AND attr.attribute_id IN (' . implode(',',
//            $this->_selectAttributes) . ') AND main.store_id=' . $this->getStoreId(),
        $this->_attributeValues = $this->_getReadAdapter()->fetchPairs($select);


        $select = $this->_getReadAdapter()->select()
            ->from(['eao' => $this->getTable('eav/attribute')])
            ->reset(Zend_Db_Select::COLUMNS)
            ->columns([
                'attribute_code'
            ])
            ->where('frontend_input = ?', 'select')
            ->where('is_user_defined = ?', 1);

        $this->_dropdownAttributes = $this->_getReadAdapter()->fetchCol($select);
    }

    /**
     * Get indexed array of either [`<attribute_code>` => '<attribute_id>`] or [`<attribute_id>` => `<attribute_code>`]
     *
     * @param $reverse
     * @return array
     */
    public function getAttributeCodeToId($reverse = false)
    {
        if (isset($this->_attributeCodeIdCache[$reverse ? 'reverse' : 'no_reverse']) && is_array($this->_attributeCodeIdCache[$reverse ? 'reverse' : 'no_reverse'])) {
            return $this->_attributeCodeIdCache[$reverse ? 'reverse' : 'no_reverse'];
        }
        $select = $this->_getReadAdapter()->select()
            ->from(['eao' => $this->getTable('eav/attribute')])
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
        $this->_attributeCodeIdCache[$reverse ? 'reverse' : 'no_reverse'] = $this->_getReadAdapter()->fetchPairs($select);
        return $this->_attributeCodeIdCache[$reverse ? 'reverse' : 'no_reverse'];

    }


    public function generate(string $handlerName, string $feedPath, callable $mappingCallBack, bool $zip = false)
    {
        if (!is_callable($mappingCallBack)) {
            throw new InvalidArgumentException('Third argument must be a valid callable');
        }

        /* ----------------------------------------------------------
         * 1.  I/O setup
         * -------------------------------------------------------- */
        $dir = dirname($feedPath);
        if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
            throw new RuntimeException("Cannot create directory $dir");
        }

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
         * 2.  Write header
         * -------------------------------------------------------- */

        Mage::dispatchEvent('omfeed_insert_headers_' . $handlerName, [
            'io' => $io
        ]);

        /* ----------------------------------------------------------
         * 3.  Iterate & delegate tag-writing to the callback
         * -------------------------------------------------------- */
        foreach ($this as $product) {
            /** @var Mage_Catalog_Model_Product $product */
            $mappingCallBack($io, $product);   // <-- your custom mapping here
        }

        /* ----------------------------------------------------------
         * 4.  Close XML and stream
         * -------------------------------------------------------- */
        Mage::dispatchEvent('omfeed_insert_footer_' . $handlerName, [
            'io' => $io
        ]);
        $io->streamClose();
        $io->mv($feedPath . '.tmp', $feedPath);

        if ($zip && class_exists('ZipArchive')) {
            $zip = new ZipArchive;
            if ($zip->open($feedPath . '.zip',
                    ZipArchive::OVERWRITE | ZipArchive::CREATE) === true) {
                // Add file to the zip file
                $zip->addFile($feedPath, basename($feedPath));
                // All files are added, so close the zip file.
                $zip->close();
            }
        }
    }
}
