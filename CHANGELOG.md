# Changelog

All notable changes to this project will be documented in this file.

## [0.2.0] - 2026-05-01

### Added

- **Section gating via `setup()`** — `require` config key accepts a whitelist of data sections
  (`stock`, `categories`, `gallery`, `url`, `configurables`, `attributes`, `price`).
  Sections not listed are skipped entirely (no joins, no queries). Supports `DATA` and `FILTER` modes
  per section — `FILTER` joins the table for WHERE/HAVING but does not populate data on product objects.
  When `require` is absent, all sections load (backward compatible).
- **Category format modes** — `cat_format` config key controls which categories are selected per product:
  `single_deepest` (deepest-level category the product is assigned to),
  `multi_deepest` (all deepest per branch, ancestors filtered out),
  `multi_all` (every category the product appears in, including parents).
- **Category display modes** — `cat_display` config key controls how each category is rendered:
  `fullpath` (full breadcrumb, e.g. `Electronics / Phones / Smartphones`) or
  `name_only` (leaf segment only, e.g. `Smartphones`).
- **Configurable separators** — `cat_separator` (within breadcrumb paths, default `' > '`) and
  `cat_join` (between categories in multi modes, default `', '`).
- **rsync-style category rules** — `cat_rules` and `bread_rules` config keys accept ordered rule arrays.
  Rules are evaluated in order, first match wins. Wildcard `'*'` matches all remaining categories.
  Replaces `cat_excl` / `bread_excl` with a more expressive system (old keys still work as fallback).
- `_resolveCategories()` private method — category format/display engine used by `_afterLoad()`.
- `_compileCategoryRules()` private method — compiles ordered rules into include/exclude ID sets for SQL.
- `_getCategoryInClause()` private helper — extracted from `_beforeLoad()`.
- `_isRequired()` / `_requiresData()` private helpers — section gating checks.
- `raw_path` column added to category CTE output for ancestor detection in `multi_deepest` mode.
- 28 new class constants: `REQUIRE_*` (7), `DATA`/`FILTER`, `CAT_*` (5), `RULE_*` (2), `CONFIG_*` (7).

### Changed

- **`_beforeLoad()`** — all section joins are now gated by `_isRequired()`.
  URL and category joins are decoupled: URL can be loaded without categories (product-only URLs)
  and categories can be loaded without URL rewrites (direct category index join).
- **`_afterLoad()`** — all processing (gallery, categories, attributes, associations) gated by
  `_requiresData()`. `require` config takes precedence over flags when set.
- **`_gatherCategories()`** — CTE leaf filter is now conditional: disabled when any `cat_format` is set
  (new format modes need non-leaf categories). Integrated rules system with BC fallback to
  `cat_excl`/`bread_excl`. Uses parameterized `->where('... IN (?)', $ids)` instead of string
  interpolation for category exclusion.
- **`setup()`** — normalizes `require` key into internal `$_requirements` map. Expanded PHPDoc
  documents all new config keys.
- **Price index** — gated by `REQUIRE_PRICE`. Explicitly sets `use_price_index = false` when price
  is not required, preventing parent class from adding the join.

### Backward Compatibility

- When `setup()` is called **without** the `require` key, behavior is identical to 0.1.x
  (all sections load, all flags respected).
- `cat_excl` and `bread_excl` config keys continue to work. `cat_rules`/`bread_rules` take
  precedence when set.
- All existing flags (`FLAG_ASSOCIATIONS`, `FLAG_LEAF_CATS`, `FLAG_REQUIRE_CAT`, `FLAG_GALLERY`)
  remain functional. `require` config takes precedence for section loading when set.

## [0.1.7] - 2025-04-30

### Fixed

- SEC-1: SQL injection in category ID interpolation
- CM-2: Hardcoded table names replaced with `getTable()` calls
- CM-3: Empty `IN()` clause guard for products with no categories
- PF-1: Gallery query scoped to current store
- PF-2: `use_price_index` explicitly set in `_beforeLoad()` (parent constructor wipes it)
- PF-3: Attribute options scoped to current store with default fallback
- PF-4: `GROUP_CONCAT` session limit increased to 65536
- All PHP 8.2 property declarations added

## [0.1.6] - 2025-01-15

### Fixed

- Multistore URL rewrite fixes

## [0.1.5] - 2024-09-01

### Fixed

- Performance improvements for large catalogs

## [0.1.4] - 2024-06-01

### Added

- `FLAG_REQUIRE_CAT` — option to fetch products without a category assignment
- `COALESCE` for URL rewrites (category URL / product-only URL fallback)

## [0.1.3] - 2024-03-01

### Added

- `generate()` method for streaming feed file creation (XML, CSV, etc.)
- Event dispatching for header/footer customization
- Optional ZIP archive creation

## [0.1.2] - 2024-01-01

### Added

- `FLAG_LEAF_CATS` — option for leaf-only category filtering

## [0.1.0] - 2023-10-01

### Added

- Initial release
- Product feed collection extending `Mage_Catalog_Model_Resource_Product_Collection`
- WITH RECURSIVE CTE for category breadcrumbs
- Media gallery loading
- Stock status join
- Configurable product associations
- URL rewrite joins with category context
