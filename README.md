# Feed generation

Gather products easily with performance in mind. To be used for fetching products only.

## Features

- Leaf category breadcrumbs
- Product Gallery
- Stock
- Configurable products

I encourage you to check `app/code/local/InternetCode/Feed/Model/Feed.php`

## Installation

### Composer

```json
{
    "minimum-stability": "dev",
    "require": {
        "m-michalis/om-feed": "0.1.*"
    }
}
```

## Usage


### Start with
```php
/** @var InternetCode_Feed_Model_Feed $collection */
$collection = Mage::getModel('ic_feed/feed');
$collection->addAttributeToSelect([
    'name',
    'description',
    'manufacturer',
    'image',
    'weight'
]);
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
