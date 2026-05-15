# Usage Guide

## Quick Start

```php
$feed = Mage::getModel('ic_feed/feed');

$feed->setup([
    'require' => [
        InternetCode_Feed_Model_Feed::REQUIRE_STOCK,
        InternetCode_Feed_Model_Feed::REQUIRE_CATEGORIES,
        InternetCode_Feed_Model_Feed::REQUIRE_GALLERY,
        InternetCode_Feed_Model_Feed::REQUIRE_URL,
        InternetCode_Feed_Model_Feed::REQUIRE_PRICE,
    ],
    'cat_format'  => InternetCode_Feed_Model_Feed::CAT_SINGLE_DEEPEST,
    'cat_display' => InternetCode_Feed_Model_Feed::CAT_FULLPATH,
]);

$feed->addAttributeToSelect(['sku', 'name', 'price', 'image']);
$feed->setVisibility(Mage::getSingleton('catalog/product_visibility')->getVisibleInSearchIds());
$feed->generate('myhandler', $path, [$this, 'writeProduct']);
```

## Section Gating

The `require` config key controls which data sections are loaded. When present, only
listed sections fire their SQL joins and post-processing. When absent, everything loads (backward compatible).

### Available sections

| Constant                | Table(s) joined                                                  | SQL alias(es)                                | Product data keys (DATA mode)                                                                   |
|-------------------------|------------------------------------------------------------------|----------------------------------------------|-------------------------------------------------------------------------------------------------|
| `REQUIRE_STOCK`         | `cataloginventory_stock_status`                                  | `stock`                                      | `quantity` *(float)*, `stock_status` *(int, 1 = in stock)*                                      |
| `REQUIRE_CATEGORIES`    | CTE query + `catalog_category_product_index`                     | `at_category_ids`                            | `category` *(string, formatted breadcrumb)*, `category_ids` *(string, comma-separated IDs)*     |
| `REQUIRE_GALLERY`       | *(separate query — not a SQL join)*                              | —                                            | `gallery` *(string[], image filenames)*                                                         |
| `REQUIRE_URL`           | `core_url_rewrite` (×2 when categories also required)            | `at_request_path_full`, `at_request_path`    | `request_path` *(string, URL path)*                                                             |
| `REQUIRE_CONFIGURABLES` | `catalog_product_super_link` + `catalog_product_super_attribute` | `at_item_group_id`, `at_super_attribute_ids` | `item_group_id` *(int, parent product ID)*, `super_attribute_ids` *(string, comma-separated)*   |
| `REQUIRE_ATTRIBUTES`    | *(separate query — not a SQL join)*                              | —                                            | Replaces dropdown option IDs with their labels on every product                                 |
| `REQUIRE_PRICE`         | `catalog_product_index_price` *(via parent)*                     | `price_index`                                | `price`, `final_price`, `min_price`, `max_price`, `minimal_price`, `tier_price`, `tax_class_id` |

> **SQL aliases matter** — use them with `getSelect()->columns()` or `joinLeft()` to access
> additional columns from already-joined tables.
> See [Appending custom fields](#appending-custom-fields-from-joined-tables) below.

### DATA vs FILTER modes

Each section can be required in two modes:

```php
use InternetCode_Feed_Model_Feed as Feed;

$feed->setup([
    'require' => [
        Feed::REQUIRE_STOCK,                        // DATA mode (default)
        Feed::REQUIRE_CATEGORIES => Feed::FILTER,   // FILTER mode
    ],
]);
```

- **DATA** (default) — join the table AND populate data on product objects.
- **FILTER** — join the table for WHERE/HAVING clauses but do NOT populate data.
  Use this when you need to filter by a section without paying the post-processing cost.

Example: get products from a specific category with qty > 0, but only need SKU + stock data:

```php
$feed->setup([
    'require' => [
        Feed::REQUIRE_STOCK,
        Feed::REQUIRE_CATEGORIES => Feed::FILTER,
        Feed::REQUIRE_PRICE,
    ],
    'cat_rules' => [
        [Feed::RULE_INCLUDE, [42]],
        [Feed::RULE_EXCLUDE, '*'],
    ],
]);

$feed->addAttributeToSelect(['sku']);
$feed->addFieldToFilter('quantity', ['gt' => 0]);
```

Categories are joined (so products are filtered to category 42) but breadcrumbs are not computed.

### Appending custom fields from joined tables

Since the collection builds a single SQL query, you can reference any already-joined table
by its alias to pull additional columns — or join new tables against them.

**Alias convention:** `joinField('name', ...)` creates alias `at_name`.
`joinTable(['alias' => ...], ...)` uses the alias you provide.

#### Add a column from an already-joined table

```php
// Stock table is joined as 'stock' — grab the raw qty column under a custom key
$feed->getSelect()->columns([
    'raw_qty' => 'stock.qty',
]);

// URL rewrite table joined as 'at_request_path_full' — grab the category_id used for the URL
$feed->getSelect()->columns([
    'url_category_id' => 'at_request_path_full.category_id',
]);
```

#### Join a new table using an existing alias

```php
// Join the category entity table to access level, position, etc.
// Requires REQUIRE_URL + REQUIRE_CATEGORIES (which join at_request_path_full)
$feed->getSelect()->joinLeft(
    ['cat_entity' => $feed->getTable('catalog/category')],
    'cat_entity.entity_id = at_request_path_full.category_id',
    ['category_level' => 'cat_entity.level', 'category_position' => 'cat_entity.position']
);
```

> **Note:** The query is grouped by `entity_id`. Columns from 1:N joins (like categories)
> return the value matching the first grouped row unless wrapped in an aggregate
> (`GROUP_CONCAT`, `MIN`, `MAX`, etc.).

#### Aggregate example

```php
// Get all category positions as a comma-separated list
// Requires REQUIRE_CATEGORIES (which joins at_category_ids)
$feed->getSelect()->columns([
    'all_category_positions' => new Zend_Db_Expr('GROUP_CONCAT(DISTINCT at_category_ids.position)')
]);
```

## Category Modes

### Format — which categories per product

```php
$feed->setup([
    'cat_format' => Feed::CAT_SINGLE_DEEPEST,
]);
```

| Constant             | Behavior                                                            |
|----------------------|---------------------------------------------------------------------|
| `CAT_SINGLE_DEEPEST` | Deepest-level category the product is assigned to                   |
| `CAT_MULTI_DEEPEST`  | All deepest per branch — ancestors filtered out via path comparison |
| `CAT_MULTI_ALL`      | Every category the product appears in, including parent categories  |
| *(not set)*          | First matching category (backward compatible)                       |

**Deepest vs leaf:** the original `FLAG_LEAF_CATS` filters by tree structure (categories with no children).
The new `single_deepest` and `multi_deepest` formats filter per product — if a product is assigned to
category B but not its child C, B is returned. `FLAG_LEAF_CATS` would skip B because C exists below it.

### Display — how each category looks

```php
$feed->setup([
    'cat_display' => Feed::CAT_NAME_ONLY,
]);
```

| Constant        | Output for category `Electronics > Phones > Smartphones` |
|-----------------|----------------------------------------------------------|
| `CAT_FULLPATH`  | `Electronics > Phones > Smartphones`                     |
| `CAT_NAME_ONLY` | `Smartphones`                                            |

### Separators

```php
$feed->setup([
    'cat_separator' => ' > ',   // within breadcrumb paths (default: ' > ')
    'cat_join'      => ', ',    // between categories in multi modes (default: ', ')
]);
```

### Output examples

Product assigned to `Phones` (level 3) and `Accessories` (level 2) in separate branches:

| Format + Display               | Separator | Join | Output                                                        |
|--------------------------------|-----------|------|---------------------------------------------------------------|
| `single_deepest` + `fullpath`  | ` > `     | —    | `Electronics > Phones`                                        |
| `single_deepest` + `name_only` | —         | —    | `Phones`                                                      |
| `multi_deepest` + `fullpath`   | ` > `     | `, ` | `Electronics > Phones, Shop > Accessories`                    |
| `multi_deepest` + `name_only`  | —         | `, ` | `Phones, Accessories`                                         |
| `multi_all` + `fullpath`       | ` > `     | `, ` | `Electronics, Electronics > Phones, Shop, Shop > Accessories` |
| `multi_all` + `name_only`      | —         | `, ` | `Electronics, Phones, Shop, Accessories`                      |

## Category Rules

Rules replace `cat_excl` and `bread_excl` with an ordered, rsync-style system.
Rules are evaluated in order — first match wins. Wildcard `'*'` matches all remaining categories.

### Syntax

```php
$feed->setup([
    'cat_rules' => [
        [Feed::RULE_EXCLUDE, [99, 101]],    // exclude Promotional, Clearance
        [Feed::RULE_INCLUDE, '*'],           // include everything else
    ],
    'bread_rules' => [
        [Feed::RULE_EXCLUDE, [99]],          // hide Promotional from breadcrumbs
        [Feed::RULE_INCLUDE, '*'],           // keep rest in breadcrumbs
    ],
]);
```

Each rule is `[action, target]` where:

- **action**: `Feed::RULE_INCLUDE` (`'+'`) or `Feed::RULE_EXCLUDE` (`'-'`)
- **target**: `int`, `int[]`, or `'*'` (wildcard)

### Common patterns

**Exclude specific categories, include rest:**

```php
'cat_rules' => [
    [Feed::RULE_EXCLUDE, [99, 101]],
    [Feed::RULE_INCLUDE, '*'],
],
```

**Include only specific categories:**

```php
'cat_rules' => [
    [Feed::RULE_INCLUDE, [42, 43, 44]],
    [Feed::RULE_EXCLUDE, '*'],
],
```

**Exclude one, but include its sibling:**

```php
'cat_rules' => [
    [Feed::RULE_EXCLUDE, [45]],      // exclude first (first match wins)
    [Feed::RULE_INCLUDE, [42, 43]],
    [Feed::RULE_EXCLUDE, '*'],
],
```

### Backward compatibility

When `cat_rules`/`bread_rules` are not set, the module falls back to `cat_excl`/`bread_excl`:

```php
$feed->setup([
    'cat_excl'   => [99],       // still works
    'bread_excl' => [99, 101],  // still works
]);
```

When rules ARE set, old keys are ignored.

## Practical Examples

### Lightweight stock feed (SKU + quantity only)

```php
$feed = Mage::getModel('ic_feed/feed');
$feed->setup([
    'require' => [Feed::REQUIRE_STOCK, Feed::REQUIRE_PRICE],
]);
$feed->addAttributeToSelect(['sku']);
$feed->addFieldToFilter('quantity', ['gt' => 0]);
$feed->generate('stock', $path, function ($io, $product) {
    $io->streamWrite($product->getSku() . ',' . $product->getData('quantity') . "\n");
});
```

No categories, no gallery, no URLs, no configurables, no attribute resolution.

### Marketplace feed with category exclusions

```php
$feed = Mage::getModel('ic_feed/feed');
$feed->setup([
    'require' => [
        Feed::REQUIRE_STOCK,
        Feed::REQUIRE_CATEGORIES,
        Feed::REQUIRE_GALLERY,
        Feed::REQUIRE_URL,
        Feed::REQUIRE_CONFIGURABLES,
        Feed::REQUIRE_ATTRIBUTES,
        Feed::REQUIRE_PRICE,
    ],
    'cat_format'  => Feed::CAT_MULTI_DEEPEST,
    'cat_display' => Feed::CAT_NAME_ONLY,
    'cat_rules' => [
        [Feed::RULE_EXCLUDE, [99]],     // skip "Promotional"
        [Feed::RULE_INCLUDE, '*'],
    ],
    'bread_rules' => [
        [Feed::RULE_EXCLUDE, [99]],
        [Feed::RULE_INCLUDE, '*'],
    ],
    'customer_group' => Mage_Customer_Model_Group::NOT_LOGGED_IN_ID,
]);

$feed->setFlag(Feed::FLAG_ASSOCIATIONS, true);
$feed->addAttributeToSelect(['sku', 'name', 'description', 'image', 'price', 'color', 'size']);
$feed->setVisibility(Mage::getSingleton('catalog/product_visibility')->getVisibleInSearchIds());
$feed->generate('marketplace', $path, [$this, 'writeProduct'], true);
```

### Category-filtered feed without breadcrumbs

Products from category 42 with stock, no breadcrumb computation:

```php
$feed = Mage::getModel('ic_feed/feed');
$feed->setup([
    'require' => [
        Feed::REQUIRE_STOCK,
        Feed::REQUIRE_CATEGORIES => Feed::FILTER,
        Feed::REQUIRE_PRICE,
    ],
    'cat_rules' => [
        [Feed::RULE_INCLUDE, [42]],
        [Feed::RULE_EXCLUDE, '*'],
    ],
]);

$feed->addAttributeToSelect(['sku', 'name']);
$feed->addFieldToFilter('quantity', ['gt' => 0]);
$feed->generate('cat42_stock', $path, $callback);
```

### Full legacy usage (no require — backward compatible)

```php
$feed = Mage::getModel('ic_feed/feed');
$feed->setFlag(Feed::FLAG_ASSOCIATIONS, true);
$feed->setFlag(Feed::FLAG_LEAF_CATS, true);
$feed->setup([
    'bread_excl' => [9, 76, 386],
    'cat_separator' => ' > ',       // default separator (same as pre-0.2.0)
]);
$feed->addAttributeToSelect(['sku', 'name', 'price', 'image']);
$feed->generate('legacy', $path, [$this, 'writeProduct']);
```

## Flags Reference

Flags control behavior within loaded sections. They work alongside `require` —
when `require` is set, it takes precedence for section loading; flags fine-tune behavior.

| Flag                | Default | Effect                                                               |
|---------------------|---------|----------------------------------------------------------------------|
| `FLAG_ASSOCIATIONS` | `false` | Regroup configurable children into parent's `associated_products`    |
| `FLAG_LEAF_CATS`    | `true`  | Only leaf categories (no children). Ignored when `cat_format` is set |
| `FLAG_REQUIRE_CAT`  | `true`  | INNER join on categories (products must exist in a category)         |
| `FLAG_GALLERY`      | `true`  | Load media gallery. Ignored when `require` is set                    |

## Config Reference

All keys passed to `setup()`:

| Key              | Type     | Default                      | Description                                 |
|------------------|----------|------------------------------|---------------------------------------------|
| `require`        | `array`  | *(not set — loads all)*      | Section whitelist with DATA/FILTER modes    |
| `cat_format`     | `string` | *(not set — first match)*    | Category selection strategy                 |
| `cat_display`    | `string` | `'fullpath'`                 | Category display mode                       |
| `cat_separator`  | `string` | `' > '`                      | Separator within breadcrumb paths           |
| `cat_join`       | `string` | `', '`                       | Separator between categories in multi modes |
| `cat_rules`      | `array`  | *(not set — use cat_excl)*   | Ordered category rules                      |
| `bread_rules`    | `array`  | *(not set — use bread_excl)* | Ordered breadcrumb rules                    |
| `cat_excl`       | `int[]`  | `[]`                         | Category IDs excluded entirely (BC)         |
| `bread_excl`     | `int[]`  | `[]`                         | Category IDs excluded from breadcrumbs (BC) |
| `customer_group` | `int`    | `NOT_LOGGED_IN_ID`           | Customer group for price index              |

## Data Available on Products

After loading, products have these data keys depending on which sections were required:

| Key                     | Section                                       | Type       | Description                                    |
|-------------------------|-----------------------------------------------|------------|------------------------------------------------|
| `quantity`              | `REQUIRE_STOCK`                               | `float`    | Stock quantity                                 |
| `stock_status`          | `REQUIRE_STOCK`                               | `int`      | Stock status (1 = in stock)                    |
| `category`              | `REQUIRE_CATEGORIES`                          | `string`   | Formatted category (depends on format/display) |
| `category_ids`          | `REQUIRE_CATEGORIES`                          | `string`   | Comma-separated category IDs                   |
| `gallery`               | `REQUIRE_GALLERY`                             | `string[]` | Array of image filenames                       |
| `request_path`          | `REQUIRE_URL`                                 | `string`   | URL rewrite path                               |
| `item_group_id`         | `REQUIRE_CONFIGURABLES`                       | `int`      | Parent product ID (for children)               |
| `super_attribute_ids`   | `REQUIRE_CONFIGURABLES`                       | `string`   | Comma-separated super attribute IDs            |
| `associated_products`   | `REQUIRE_CONFIGURABLES` + `FLAG_ASSOCIATIONS` | `array`    | Child products (on parent)                     |
| *(EAV attributes)*      | `addAttributeToSelect()`                      | `mixed`    | Any selected product attribute                 |
| *(dropdown attributes)* | `REQUIRE_ATTRIBUTES`                          | `string`   | Resolved option labels (not IDs)               |
