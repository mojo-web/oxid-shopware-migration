# OXID 6.3 → JSON exporter — standalone (no dependencies)

Runs directly on the OXID server with **no `composer install`** and **no
`vendor/`**. Pure PHP; needs only `ext-pdo` (pdo_mysql) and `ext-mbstring`,
which every OXID 6 server already has. Works on PHP **7.2 through 8.x**.

## Run

```bash
php bin/export \
  --oxid-config=/var/www/oxid/source/config.inc.php \
  --mapping=mapping.yaml \
  --out=oxid-export.json
```

Or pass DB credentials directly / via env:

```bash
php bin/export --db-host=127.0.0.1 --db-name=shop --db-user=shop --db-pass=secret
# or: OXID_DB_NAME=shop OXID_DB_USER=shop OXID_DB_PASS=secret php bin/export
```

`php bin/export --help` lists all options.

## What it exports

Same `oxid-export.json` contract as the Symfony builds: categories,
manufacturers, property groups, products (prices, **images**, manufacturer +
property refs, categories), orders and customers (billing + delivery addresses,
password material). Import it with the `MojoOxidImport` Shopware 6.7 plugin.

### Images to a directory

In `mapping.yaml`:

```yaml
images:
  source_dir: "/var/www/oxid/source/out/pictures/master/product"
  copy_to: "./media-export"     # copies masters here under unique names
  base_url: "https://old-shop.example.com/out/pictures/master/product"  # optional URL refs
```

Then on import: `bin/console mojo:oxid:import --file=oxid-export.json --media-dir=./media-export`.

## Notes

- No Symfony, no Doctrine — a small PDO wrapper (`src/Db.php`) and the bundled
  single-file YAML parser Spyc (`src/Lib/Spyc.php`, MIT) replace them.
- Sort queries fall back gracefully if an optional column (e.g. `OXSORT`) is
  absent in your OXID schema.
- `composer.json` is included only for optional PSR-4 autoloading; it is **not**
  required to run.
