<?php

class InternetCode_FeedTest_Model_Generate
{
    /**
     * Attributes to include in the test feed
     */
    protected array $attributeCodesToSelect = [
        'name',
        'sku',
        'price',
        'special_price',
        'short_description',
        'description',
        'image',
        'small_image',
        'weight',
        'url_key',
    ];

    /**
     * Generate a test XML feed using sample data products
     *
     * @return string Path to generated file
     */
    public function run(string $outputPath = ''): string
    {
        if (empty($outputPath)) {
            $outputPath = Mage::getBaseDir('var') . DIRECTORY_SEPARATOR . 'export' . DIRECTORY_SEPARATOR . 'test_feed.xml';
        }

        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $storeId = (int) Mage::app()->getDefaultStoreView()->getId();

        $appEmulation = Mage::getSingleton('core/app_emulation');
        $initialEnvironmentInfo = $appEmulation->startEnvironmentEmulation($storeId);

        try {
            /** @var InternetCode_Feed_Model_Feed $feed */
            $feed = Mage::getModel('ic_feed/feed');
            $feed->setStoreId($storeId);

            $feed->setFlag(InternetCode_Feed_Model_Feed::FLAG_ASSOCIATIONS, true);
            $feed->setFlag(InternetCode_Feed_Model_Feed::FLAG_LEAF_CATS, true);
            $feed->setFlag(InternetCode_Feed_Model_Feed::FLAG_GALLERY, true);

            // addPriceData() must precede setVisibility(): the Feed model declares
            // use_price_index=true as a class default which survives construction,
            // so setVisibility() → _productLimitationPrice() runs and needs website_id.
            // addPriceData() populates website_id from the store before that chain fires.
            $feed->addPriceData(
                Mage_Customer_Model_Group::NOT_LOGGED_IN_ID,
                (int) Mage::app()->getStore($storeId)->getWebsiteId()
            );

            $feed->setVisibility(
                Mage::getSingleton('catalog/product_visibility')->getVisibleInSearchIds()
            );

            $feed->setup([
                'bread_excl' => [],
            ]);

            $feed->addAttributeToSelect($this->attributeCodesToSelect);

            $feed->generate(
                'test_feed',
                $outputPath,
                [$this, 'writeProduct'],
                false
            );
        } finally {
            $appEmulation->stopEnvironmentEmulation($initialEnvironmentInfo);
        }

        return $outputPath;
    }

    /**
     * Write a single product entry to the XML feed
     */
    public function writeProduct(Varien_Io_File $io, Mage_Catalog_Model_Product $product): void
    {
        $io->streamWrite('  <product>' . "\n");

        $mediaConfig = Mage::getSingleton('catalog/product_media_config');

        $fields = [
            'id'            => $product->getId(),
            'sku'           => $product->getSku(),
            'name'          => $product->getName(),
            'price'         => $product->getPrice(),
            'special_price' => $product->getSpecialPrice(),
            'type'          => $product->getTypeId(),
            'image'         => $product->getImage()
                ? $mediaConfig->getMediaUrl($product->getImage())
                : '',
            'category'      => $product->getCategory(),
            'description'   => $product->getShortDescription(),
            'weight'        => $product->getWeight(),
        ];

        foreach ($fields as $tag => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $this->writeCdata($io, $tag, (string) $value);
        }

        // Gallery images
        $gallery = $product->getData('gallery');
        if (!empty($gallery) && is_array($gallery)) {
            $io->streamWrite('    <gallery>' . "\n");
            foreach ($gallery as $img) {
                if ($img !== $product->getData('image')) {
                    $this->writeCdata($io, 'image', $mediaConfig->getMediaUrl($img), '      ');
                }
            }
            $io->streamWrite('    </gallery>' . "\n");
        }

        // Associated products (configurable variants)
        $associated = $product->getData('associated_products');
        if (!empty($associated) && is_array($associated)) {
            $io->streamWrite('    <variants>' . "\n");
            foreach ($associated as $child) {
                $io->streamWrite(sprintf(
                    '      <variant sku="%s"><price>%s</price></variant>',
                    htmlspecialchars($child->getSku()),
                    $child->getPrice()
                ) . "\n");
            }
            $io->streamWrite('    </variants>' . "\n");
        }

        $io->streamWrite('  </product>' . "\n");
    }

    /**
     * Event observer: write XML header
     */
    public function insertHeaders(Varien_Event_Observer $event): void
    {
        /** @var Varien_Io_File $io */
        $io = $event->getIo();
        $io->streamWrite('<?xml version="1.0" encoding="UTF-8"?>' . "\n");
        $io->streamWrite('<feed>' . "\n");
        $io->streamWrite(sprintf(
            '<generated_at>%s</generated_at>',
            Mage::getSingleton('core/date')->gmtDate('Y-m-d H:i:s')
        ) . "\n");
        $io->streamWrite('<products>' . "\n");
    }

    /**
     * Event observer: write XML footer
     */
    public function insertFooter(Varien_Event_Observer $event): void
    {
        /** @var Varien_Io_File $io */
        $io = $event->getIo();
        $io->streamWrite('</products>' . "\n");
        $io->streamWrite('</feed>' . "\n");
    }

    /**
     * Write a CDATA-wrapped XML element
     */
    protected function writeCdata(Varien_Io_File $io, string $tag, string $value, string $indent = '    '): void
    {
        $io->streamWrite(sprintf(
            '%s<%s><![CDATA[%s]]></%s>',
            $indent,
            $tag,
            strip_tags($value),
            $tag
        ) . "\n");
    }
}
