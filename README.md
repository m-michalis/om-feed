# Feed generation

Gather products easily with performance in mind (mostly in a single query). To be used for fetching products only. 

Made for developers.

## Features

- Extends `catalog/product_collection` for built-in functionality
- Leaf category breadcrumbs
- Product Gallery
- Stock
- Configurable products
- Creates simple file (xml, csv etc)
  - Fires events for customizing start/end of file
  - Stream-writes to .tmp then moves to original file
  - optionally create zip file

I encourage you to check `app/code/local/InternetCode/Feed/Model/Feed.php`

## Installation

### Composer

```json
{
    "minimum-stability": "dev",
    "require": {
        "m-michalis/om-feed": "0.1.*"
    },
    "repositories": {
      "type": "vcs",
      "url": "https://github.com/m-michalis/om-feed.git"
    }
}
```

## Usage


### Example creation of XML file
```xml
<?xml version="1.0"?>
<!-- config.xml -->
<config>
    <global>
        ...
        <events>
            <omfeed_insert_headers_myhandler>
                <observers>
                    <company_module_feed>
                        <type>model</type>
                        <class>company_module/feed</class>
                        <method>insertHeaders</method>
                    </company_module_feed>
                </observers>
            </omfeed_insert_headers_myhandler>
            <omfeed_insert_footer_myhandler>
                <observers>
                    <company_module_feed>
                        <type>model</type>
                        <class>company_module/feed</class>
                        <method>insertFooter</method>
                    </company_module_feed>
                </observers>
            </omfeed_insert_footer_myhandler>
        </events>
        ...
    </global>
</config>
```


```php
// <your_module_dir>/Model/Feed.php

class Company_Module_Model_Feed 
{
    public $attributeCodesToSelect = [
        'name',
        'sku',
        'short_description',
        'image',
        'weight',
        'color',
        // freely select attributes
    ];
    
    public function generate()
    {

        // Optional emulation
        $appEmulation = Mage::getSingleton('core/app_emulation');
        $initialEnvironmentInfo = $appEmulation->startEnvironmentEmulation(1); //store id

        $feed = Mage::getModel('ic_feed/feed');
        
        // InternetCode_Feed_Model_Feed extends Mage_Catalog_Model_Resource_Product_Collection
        // so it is like calling Mage::getModel('catalog/product')->getCollection() but with extra stuff
        
        $feed->setFlag(InternetCode_Feed_Model_Feed::FLAG_ASSOCIATIONS, true); // removes children from collection and adds them to their respective parent's 'associated_products' index
        $feed->setFlag(InternetCode_Feed_Model_Feed::FLAG_LEAF_CATS, true); // Use leaf categories. Only products that exist in end categories (categories without other child categories)
        $feed->setVisibility(Mage::getSingleton('catalog/product_visibility')->getVisibleInSearchIds());
        $feed->setup([
            'bread_excl' => [9,76,386,3355] // these category ids are excluded for urls/breadcrumbs in case of products that exist in multiple categories
        ]);
        //$collection->addAttributeToFilter('entity_id','15606' );
        $feed->addAttributeToSelect($this->attributeCodesToSelect);
        $feed->addAttributeToFilter('products_xml_status', ['eq' => 1]);
        $feed->generate('myhandler', Mage::getBaseDir() . DS . 'xmlexport' . DS . 'products_2025.xml', [$this, 'writeProduct'], true);


        $appEmulation->stopEnvironmentEmulation($initialEnvironmentInfo);
        return 'Completed Successfully!';
    }
    
    
    public function writeProduct(Varien_Io_File $io, Mage_Catalog_Model_Product $product)
    {
        $io->streamWrite('<product>' . "\n");

        $mediaConfig = Mage::getSingleton('catalog/product_media_config');
        
        //example usage for writing XMLs 
        foreach ([
                     'sku',
                     'price',
                     'title',
                     'image',
                     'category'
                 ] as $xmlAttribute) {
                 
            switch ($xmlAttribute) {
                case 'sku':
                    $this->insertXMLEntry($io, 'sku', $product->getSku());
                    break;
                case 'image':
                    $this->insertXMLEntry($io, 'image', $mediaConfig->getMediaUrl($product->getImage()));
                    break;
                case 'additional_imageurl':
                    foreach ($product->getData('gallery') as $k => $img) { // gallery contains all images 
                        if($img !== $product->getData('image')) {
                            $this->insertXMLEntry($io, 'additional_imageurl', $mediaConfig->getMediaUrl($img));
                        }
                    }
                    break;
                case 'category':
                    $this->insertXMLEntry($io, 'category', $product->getCategory()); // contains the full breadcrumb
                    break;
                // rest of attributes...
            }
        }
        $io->streamWrite('</product>' . "\n");
    }
    
    public function insertXMLEntry($io, $attr, $value)
    {
        // for xml you may need to write it like this.
        $io->streamWrite(sprintf('<%s><![CDATA[%s]]></%s>', $attr, strip_tags($value), $attr) . "\n");
    }
    
    public function insertHeaders($event)
    {
        /** @var Varien_Io_File $io */
        $io = $event->getIo();

        $io->streamWrite('<?xml version="1.0" encoding="UTF-8"?>' . "\n");
        $io->streamWrite('<myfeed>' . "\n");
        $date = Mage::getSingleton('core/date')->gmtDate('Y-m-d h:i');
        $io->streamWrite(sprintf('<created_at>%s</created_at>', $date) . "\n");
        $io->streamWrite('<products>' . "\n");

    }

    public function insertFooter($event)
    {
        /** @var Varien_Io_File $io */
        $io = $event->getIo();

        $io->streamWrite('</products>' . "\n");
        $io->streamWrite('</myfeed>');
    }
}
```

### Removes children from collection and add them to their respective parent's 'associated_products' index
```php
$collection->setFlag(InternetCode_Feed_Model_Feed::FLAG_ASSOCIATIONS,true);
```
---

```php
InternetCode_Feed_Model_Feed extends Mage_Catalog_Model_Resource_Product_Collection
````
So it supports everything that is supported when using the conventional:
```php
Mage::getModel('catalog/product')->getCollection()
````



## Compatibility (tested with)
- OpenMage 20.0.x
- MariaDB 11.4
- Magento 1.9.x

## License
This module is released under the GPL-3.0 License.
