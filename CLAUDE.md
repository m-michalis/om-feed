# om-feed (InternetCode_Feed)

OpenMage module — product feed collection with optimized single-query SQL + streaming I/O.

## Quick Facts

- **Type:** Library module (no controllers, no blocks, no frontend)
- **PHP:** 8.2+
- **DB:** MySQL 8.0+ or MariaDB 10.2+ (WITH RECURSIVE CTE)
- **Extends:** `Mage_Catalog_Model_Resource_Product_Collection`
- **Model alias:** `ic_feed/feed`
- **Helper alias:** `feed` (mismatch with model alias — kept for BC)
- **Tests:** 36 integration tests via `ddev test`

## Skills

| Skill | When to load |
|-------|-------------|
| `om-feed` | Any work on Feed.php — collection logic, CTE queries, generate(), flags, require sections, category rules |
| `om-feed-testing` | DDEV environment, PHPUnit tests, test module, sample data provisioning |
