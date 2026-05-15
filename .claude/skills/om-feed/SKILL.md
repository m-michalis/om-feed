---
name: om-feed
description: "om-feed module — Feed.php collection, CTE queries, generate(), flags, require sections, category rules."
---

# om-feed Module

Library module. Extends `Mage_Catalog_Model_Resource_Product_Collection` directly — NOT a normal Model/Resource/Collection triad.

## Gotchas

- **MySQL 8.0+ or MariaDB 10.2+ required.** `_gatherCategories()` uses `WITH RECURSIVE` CTE. No fallback.
- **`_afterLoad()` never calls `parent::_afterLoad()`** — intentional for performance. EAV attribute hydration via `_loadAttributes()` is bypassed. Consumers get raw data + manual post-processing.
- **`$_productLimitationFilters['use_price_index']`** declared `true` as class default but parent constructor wipes it. Re-set in `_beforeLoad()`. Consumers bootstrapping from admin context MUST call `addPriceData($groupId, $websiteId)` BEFORE `setVisibility()` — otherwise `_productLimitationPrice()` crashes on missing `website_id`.
- **Helper alias `feed` ≠ model alias `ic_feed`** — mismatch kept for BC.
- **`$_flags` override parent.** `no_stock_data = true` suppresses parent's auto stock join — the module does its own.
- **`addUrlRewrite()` is a no-op override** — URL rewrites are joined manually in `_beforeLoad()`.
- **`_beforeLoad()` section loading** gated by `_isRequired()`. In BC mode (`_requirements === null`) everything loads. When `require` config is set, only listed sections join/populate.
- **Category format modes** (`cat_format`) disable leaf-only filtering — all new format modes need non-leaf categories for per-product deepest-level logic.

## File Locations

| File | Purpose |
|------|---------|
| `app/code/local/InternetCode/Feed/Model/Feed.php` | Entire module logic (~987 lines) |
| `app/code/local/InternetCode/Feed/Helper/Data.php` | Empty required helper |
| `app/code/local/InternetCode/Feed/etc/config.xml` | Model alias `ic_feed`, helper alias `feed` |
| `app/etc/modules/InternetCode_Feed.xml` | Module declaration (depends: Mage_Catalog, Mage_CatalogInventory) |
| `tests/Integration/` | 36 PHPUnit integration tests (run via `ddev test`) |

## Data Flow

1. Consumer calls `setup($config)` → `addAttributeToSelect()` → `generate()`
2. `generate()` iterates `$this` (triggers lazy load)
3. `_beforeLoad()`: `_gatherCategories()` (CTE) → `_gatherAttributeOptions()` → conditional JOINs (super_link, super_attribute, url_rewrite, stock_status, category_product_index) gated by `_isRequired()` → `GROUP BY entity_id`
4. `_afterLoad()`: `_loadMediaGallery()` → `_resolveCategories()` (format/display) → fill attribute values → configurable associations regrouping
5. `generate()` streams products to callback via `Varien_Io_File` (.tmp → atomic rename → optional zip)

## Flags

| Constant | Default | Effect |
|----------|---------|--------|
| `FLAG_ASSOCIATIONS` | `false` | Moves children into parent's `associated_products` array |
| `FLAG_LEAF_CATS` | `true` | Only leaf categories (no children) — ignored when `cat_format` is set |
| `FLAG_REQUIRE_CAT` | `true` | INNER join on categories (products must exist in a category) |
| `FLAG_GALLERY` | `true` | Load media gallery images; overridden by `require` config when set |

## Config Keys (passed to `setup()`)

| Key | Type | Effect |
|-----|------|--------|
| `bread_excl` | `int[]` | Category IDs excluded from breadcrumb path building (BC) |
| `cat_excl` | `int[]` | Category IDs excluded entirely (BC) |
| `customer_group` | `int` | Customer group for price index (defaults to NOT_LOGGED_IN) |
| `require` | `array` | Section whitelist — `REQUIRE_*` constants. Mixed: `[REQUIRE_STOCK, REQUIRE_CATEGORIES => FILTER]` |
| `cat_format` | `string` | `CAT_SINGLE_DEEPEST` / `CAT_MULTI_DEEPEST` / `CAT_MULTI_ALL` |
| `cat_display` | `string` | `CAT_FULLPATH` / `CAT_NAME_ONLY` |
| `cat_separator` | `string` | Breadcrumb separator (default `' > '`) |
| `cat_join` | `string` | Multi-category separator (default `', '`) |
| `cat_rules` | `array` | Ordered rsync-style rules: `[[RULE_INCLUDE, [ids]], [RULE_EXCLUDE, '*']]` |
| `bread_rules` | `array` | Same format as cat_rules, for breadcrumb inclusion |

## Requirement System

Sections: `REQUIRE_STOCK`, `REQUIRE_CATEGORIES`, `REQUIRE_GALLERY`, `REQUIRE_URL`, `REQUIRE_CONFIGURABLES`, `REQUIRE_ATTRIBUTES`, `REQUIRE_PRICE`

Modes: `DATA` (join + populate) / `FILTER` (join only, no data on product objects)

When `require` is absent → BC mode, all sections load. When set → only listed sections.

## Audit Status (2026-04-30)

- **Fixed:** SEC-1, CM-2, CM-3, PF-1 through PF-4, all PHP 8.2 property declarations
- **Open:** MF-1 (missing parent::_afterLoad — needs investigation)
