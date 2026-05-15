---
name: om-feed-testing
description: "om-feed DDEV environment, PHPUnit tests, test module, sample data provisioning."
---

# om-feed Testing Environment

DDEV-based OpenMage instance with sample data for integration testing of the `ic_feed/feed` collection.

## Gotchas

- **`addPriceData()` before `setVisibility()`** — Feed model declares `use_price_index=true` as class default. In admin bootstrap, `website_id` isn't set. `addPriceData($groupId, $websiteId)` populates it. Without this, `setVisibility()` → `_productLimitationPrice()` crashes.
- **Simple children are "Not Visible Individually"** in sample data. Association tests must NOT filter by visibility or children won't be in the collection.
- **`Mage::app('admin')` sets store_id=0** — `_initLimitationFilters()` skips `website_id` when store_id is falsy. Always call `$feed->setStoreId($storeId)` explicitly.
- **DDEV `magento` type auto-generates `local.xml`** with `#ddev-generated` marker. `install.php` overwrites it without the marker. Future `ddev start` warns but leaves it alone. This is expected.
- **Sample data SQL imported AFTER `install.php`** — installer creates schema, then sample data SQL populates it. Reversed order breaks.
- **Sample data archive cached** in `.ddev/.sampleData/` — survives `ddev reset-openmage`, only removed by `--full`.
- **PHPUnit lives in OpenMage's vendor** (`openmage/vendor/bin/phpunit`), not the module's. Config at project root `phpunit.xml` is referenced via `--configuration`.
- **Event observers in tests** — can't use anonymous classes with SimpleXML `setNode()`. Use named classes with `type=model` and the class name directly (no `/` → `Mage::getModel()` resolves raw class names).

## File Locations

| Path | Purpose |
|------|---------|
| `.ddev/config.yaml` | PHP 8.2, MariaDB 11.8, docroot=openmage, magento type |
| `.ddev/commands/web/setup-openmage` | Full provisioning: composer + OpenMage + sample data + modules |
| `.ddev/commands/web/reset-openmage` | Wipe DB + local.xml (`--full` removes openmage/ entirely) |
| `.ddev/commands/web/test` | Runs PHPUnit inside DDEV container |
| `phpunit.xml` | PHPUnit config — bootstrap=`tests/bootstrap.php` |
| `tests/bootstrap.php` | Loads OpenMage from `openmage/`, inits admin, requires FeedTestCase |
| `tests/Integration/FeedTestCase.php` | Base class: `createFeed()`, `createAndLoadFeed()`, `getTempFeedPath()` |
| `tests/Integration/ModuleTest.php` | Module aliases, flags, constants (8 tests) |
| `tests/Integration/CollectionTest.php` | Loading, flags, config, price, stock (11 tests) |
| `tests/Integration/CategoryTest.php` | CTE breadcrumbs, formats, rules (8 tests) |
| `tests/Integration/GenerateTest.php` | File output, events, zip, error handling (6 tests) |
| `dev/test-module/` | Mock consumer module `InternetCode_FeedTest` — exercises full feed pipeline |
| `openmage/` | Ephemeral OpenMage instance (gitignored) |

## Commands

```bash
ddev start                     # Start services
ddev setup-openmage            # Provision OpenMage + sample data (~2-3 min first time)
ddev reset-openmage            # Wipe DB + local.xml, then re-run setup
ddev reset-openmage --full     # Remove everything including openmage/ dir
ddev test                      # Run all 36 tests
ddev test --filter=testName    # Run specific test
ddev test --testdox            # Readable output
ddev exec 'cd openmage && php shell/generate_test_feed.php'  # Run test feed
```

## Sample Data Facts

492 simple, 81 configurable, 10 downloadable, 3 grouped, 3 bundle, 4 virtual products. 27 categories (22 leaf). Source: Vinai/compressed-magento-sample-data 1.9.2.4.

## Module Mounting

Both `m-michalis/om-feed` and `m-michalis/om-feed-test` are composer path repos with `symlink: true`. Edits in module source reflect immediately in the OpenMage instance. The `magento-deploystrategy: symlink` setting creates symlinks via modman mappings.
