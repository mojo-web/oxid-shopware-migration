# OXID 6.3 → Shopware 6.7 Migration

Zwei entkoppelte Teile, die ausschliesslich über eine JSON-Datei kommunizieren:
der Export läuft auf dem alten OXID-Server, der Import auf dem neuen Shopware-Server.
Das gesamte Mapping passiert zur **Export**-Zeit über `mapping.yaml`; die JSON ist
self-contained, der Importer braucht keine eigene Konfiguration.

## Verzeichnisse

| Verzeichnis | Laufzeit | Zweck |
|---|---|---|
| `oxid-export/` | PHP 8.2+, Symfony 6/7, DBAL 3 | moderner Exporter |
| `oxid-export-php72/` | **PHP 7.2**, Symfony 5.4, DBAL 2.13 | identische JSON, für alte OXID-Server |
| `oxid-export-standalone/` | **PHP 7.2 – 8.x**, keine Abhängigkeiten | identische JSON, läuft **ohne `composer install`** (PDO + gebündelter YAML-Parser) |
| `MojoOxidImport/` | PHP 8.2+ (Shopware 6.7) | Import-Plugin, Befehl `mojo:oxid:import` |

**Wähle einen Exporter** — alle drei erzeugen dieselbe `oxid-export.json` und sind
funktional deckungsgleich (inkl. Bild-Diagnose, Steuer-Mapping, Zahlart, Datums-Logik).
Der Standalone ist die robusteste Wahl auf Altservern, weil Composer/Packagist
nicht benötigt wird.

---

## Schritt 1 — Export aus OXID

```bash
cd oxid-export-standalone        # oder oxid-export / oxid-export-php72
composer install                 # beim Standalone NICHT nötig

php bin/export \
  --oxid-config=/var/www/oxid/source/config.inc.php \
  --mapping=mapping.yaml \
  --out=oxid-export.json
```

Zugangsdaten wahlweise über `--oxid-config` (liest `config.inc.php`) oder
`--db-host/--db-name/--db-user/--db-pass` bzw. `OXID_DB_*`-Umgebungsvariablen.

Weitere Optionen: `--default-vat=19`, `--skip-orders`, `--skip-customers`,
`-o/--out`, `-m/--mapping`, `--help`.

### mapping.yaml

```yaml
default_vat: 19.0

vat:
  mode: oxid            # oxid | standard | reduced
  standard_rate: 19.0
  reduced_rate: 7.0
  map:                  # optional, hat Vorrang vor mode
    16: standard
    5: reduced

images:
  base_url:   "https://alt-shop.de/out/pictures/master/product"
  source_dir: "/var/www/oxid/source/out/pictures/master/product"
  copy_to:    "./media-export"
  pic_fields: [1,2,3,4,5,6,7,8,9,10,11,12]

manufacturers: { enabled: true }
orders:        { enabled: true }
customers:     { enabled: true }

properties:
  - group: "Zustand"
    source: attribute          # oxobject2attribute / oxattribute
    oxid_attribute: "Zustand"
    options: { "sehr gut": "Sehr gut", "gut": "Gut" }   # optional Whitelist+Rename
  # - { group: "Material", source: field, oxid_field: "OXMATERIAL" }

attributes:                    # Kurzform: jeder Name wird zur Property-Gruppe
  - Farbe
  - Größe
```

**Umsatzsteuer (`vat.mode`)**

| mode | OXVAT 19 | OXVAT 7 | OXVAT 16 | leer |
|---|---|---|---|---|
| `oxid` *(Standard)* | 19 | 7 | 19 | `default_vat` |
| `standard` | 19 | 19 | 19 | 19 |
| `reduced` | 7 | 7 | 19 | 7 |

`map`-Einträge schlagen den Modus. Alles auf 7 %: `mode: reduced` **und keine `map`**.

**Bilder — drei Betriebsarten**

| Konfiguration | JSON-Feld | Importer liest |
|---|---|---|
| nur `base_url` | `images[].url` | lädt per HTTP(S) |
| `source_dir` + `copy_to` | `images[].file` | aus `--media-dir` |
| beides | `url` + `file` | bevorzugt lokale Datei |

Der Kopier-Modus kopiert die Master-Bilder unter eindeutigen, stabilen Namen nach
`copy_to` — den Ordner anschliessend auf den neuen Server synchronisieren und beim
Import mit `--media-dir` darauf zeigen. Enthält ein `OXPIC`-Feld bereits eine
komplette URL, wird sie unverändert übernommen.

**Bild-Diagnose:** Werden 0 Bilder exportiert, erklärt der Exporter warum —
Bild-Export deaktiviert, keine `OXPIC`-Werte vorhanden (inkl. Prüf-SQL), oder
Dateien nicht unter `source_dir` gefunden (mit bis zu 5 konkreten Pfaden).

## Schritt 2 — Plugin installieren

```bash
# MojoOxidImport/ nach custom/plugins/ kopieren
bin/console plugin:refresh
bin/console plugin:install --activate MojoOxidImport
bin/console cache:clear
```

Bei der Aktivierung legt das Plugin das Produkt-Custom-Field `condition_of_used` an.

## Schritt 3 — Import nach Shopware

```bash
bin/console mojo:oxid:import --file=oxid-export.json --media-dir=/pfad/zu/media-export
```

Reihenfolge: Hersteller → Property-Gruppen → Kategorien → Produkte (inkl. Bilder)
→ Kunden → Bestellungen → Bestand-Wiederherstellung → Index-Rebuild.

| Option | Wirkung |
|---|---|
| `--file`, `-f` | Pfad zur `oxid-export.json` |
| `--media-dir` | Ordner mit kopierten Bildern (`images[].file`) |
| `--root-category-id` | Ziel-Elternkategorie (Default: Navigations-Root des Storefront-Kanals) |
| `--closeout` | `zero-stock` *(Default)* \| `always` \| `never` — kein Verkauf bei Bestand 0 |
| `--paid-stays-open` | bezahlte Bestellungen **nicht** auf „Abgeschlossen" setzen |
| `--strict-paid` | nur `OXPAID` zählt als bezahlt (ohne Versand-/Ordner-Heuristik) |
| `--skip-manufacturers` `--skip-properties` `--skip-categories` `--skip-products` `--skip-customers` `--skip-orders` `--skip-media` | einzelne Schritte überspringen |

**Während des Imports werden keine E-Mails versendet.** Ein `ImportStateService`-Flag
plus Subscriber stoppen `CustomerRegisterEvent` und den Catch-all
`MailBeforeValidateEvent`, sodass keine Flow-Builder-/Transaktionsmail rausgeht.
Die Indizierung ist während der Schreibvorgänge deaktiviert und wird am Ende einmal
neu aufgebaut.

---

## Feld-Mapping

### Produkte

| OXID | Shopware |
|---|---|
| `OXARTNUM` (Fallback `OX-<OXID>`) | `productNumber` |
| `OXTITLE` | `name` |
| `OXLONGDESC` (`oxartextends`) | `description` |
| **`OXSHORTDESC`** | Custom-Field **`condition_of_used`** (Präfix „Zustand"/„Zustand:" wird entfernt) |
| `OXPRICE` / abgeleiteter Netto | `price.gross` / `price.net` |
| `OXVAT` bzw. `vat.mode` | Steuersatz (Regel wird bei Bedarf angelegt) |
| **`OXSTOCK`** | `stock` (+ `isCloseout` je `--closeout`) |
| `OXEAN`, `OXMPN` | `ean`, `manufacturerNumber` |
| `OXWEIGHT/OXWIDTH/OXHEIGHT/OXLENGTH` | `weight/width/height/length` |
| `OXACTIVE` | `active` |
| `OXMANUFACTURERID` → `oxmanufacturers` | `manufacturerId` |
| `OXPIC1..12` | `media` + Cover, Reihenfolge erhalten |
| `oxobject2attribute` (per Mapping) | `property_group` + Optionen |
| `oxobject2category` | `categories` |

**Doppelte Artikelnummern:** OXID erzwingt keine Eindeutigkeit, Shopware schon.
Duplikate werden deterministisch entschärft (`ca03`, `ca03-<hash>`); Kollisionen mit
bereits vorhandenen Shopware-Produkten ebenso. Alle Umbenennungen werden am Ende
aufgelistet. Schlägt ein Batch fehl, wird produktweise nachgefasst, damit ein
fehlerhafter Datensatz nicht den Lauf abbricht.

### Kunden, Adressen, Passwörter

| OXID | Shopware |
|---|---|
| `oxuser` | `customer` + Rechnungs-`customer_address` |
| `oxaddress` | zusätzliche Liefer-Adressen |
| `OXSAL` | Anrede (`mr`/`mrs`) |
| `OXCOUNTRYID` → `oxcountry`-ISO | `countryId` |
| `OXPASSWORD` + `OXPASSSALT` | `legacyPassword` (`"<hash>::<salt>"`) + `legacyEncoder` = `OxidLegacy` |

Passwörter migrieren **ohne Klartext**. Der mitgelieferte `OxidLegacyEncoder`
(Service-Tag `shopware.legacy_encoder`) prüft beim ersten Login beide OXID-Varianten —
bcrypt (`$2y$…`) und sha512+Salt. Danach re-hasht Shopware transparent und leert die
Legacy-Felder.

### Bestellungen

Historische Snapshots — sie werden **nicht** durch die State-Machine gespielt.

| OXID | Shopware |
|---|---|
| `OXORDERNR` (0/leer → `OX-<hash>`) | `orderNumber` |
| `OXORDERDATE` | `orderDateTime` (nie der Importzeitpunkt, s. u.) |
| `OXTOTALORDERSUM`, `OXDELCOST`, `OXDELVAT` | Cart-Price, Steueraufschlüsselung, Versandkosten |
| `OXBILL*` + `oxcountry` | Rechnungs-`order_address` |
| `oxorderarticles` | `lineItems` (verknüpft über `productNumber`) |
| `OXSTORNO` | Status `cancelled` |
| `OXPAID` / `OXSENDDATE` / `OXFOLDER` | Zahlungsstatus + Status `completed` |
| `OXPAYMENTTYPE` | Zahlart (s. u.) |

**Bezahlt-Erkennung:** Viele Shops pflegen `OXPAID` bei Vorkasse nie. Als bezahlt gilt
daher: `OXPAID` gesetzt **oder** `OXSENDDATE` gesetzt **oder**
`OXFOLDER = ORDERFOLDER_FINISHED`. Abschaltbar mit `--strict-paid`.

**Zahlarten:** Die installierten Shopware-Zahlarten werden einmalig klassifiziert
(über Handler/technischen Namen), PayPal also nur erkannt, wenn das Plugin installiert ist.

| OXID | Shopware |
|---|---|
| `oxidpayadvance` | Vorkasse |
| `oxidcashondel` | Nachnahme |
| enthält `paypal` | PayPal *(nur wenn installiert)* |
| enthält `sofort` | Sofort/Klarna |
| `oxidinvoice` / `oxiddebitnote` | Rechnung / Lastschrift |
| sonst | Standard-Zahlart des Sales-Channels |

**Bestelldatum:** Bei unbrauchbarem `OXORDERDATE` (`0000-00-00`, z. B. abgebrochene
Gateway-Zahlungen) wird **nie** die aktuelle Zeit gesetzt. Reihenfolge:
`OXORDERDATE` → Versanddatum → Sentinel `1970-01-01`, damit solche Datensätze
erkennbar bleiben statt als „heute" zu erscheinen.

**Lagerbestand:** Shopwares Stock-Subscriber bucht bei jeder importierten Position
Bestand ab, als wäre es ein frischer Verkauf. Der OXID-Bestand bildet diese
Bestellungen aber bereits ab — deshalb wird der Bestand nach dem Order-Import
automatisch aus dem Export wiederhergestellt.

**Fehlerdiagnose:** Bestellungen werden einzeln geschrieben. Fehlschläge werden nach
Ursache gruppiert ausgegeben (innerste Exception, IDs normalisiert) — inklusive
Beispiel-Bestellnummern, damit die tatsächliche Ursache sichtbar ist statt nur einer
Fehlerzahl.

## Idempotenz

Alle IDs sind deterministische Hashes der OXID-Identifier bzw. Bildquellen; geschrieben
wird per `upsert`. Wiederholte Läufe aktualisieren bestehende Datensätze, statt zu
duplizieren. Bereits geladene Bilder werden nicht erneut geholt.

## Bekannte Grenzen

- **Varianten** werden flach importiert (jede `oxarticles`-Zeile = eigenes Produkt);
  `parentOxid` bleibt in der JSON für eine spätere Parent/Child-Rekonstruktion erhalten.
- **Einsprachig** (OXID-Basissprache 0); `*_1`-Spalten werden nicht gelesen.
- **Properties** sind filterbare Eigenschaften, keine varianten-bildenden Optionen.
- **Bestellungen** ohne Lieferungen (`order_delivery`); Zahlung als eine Transaktion.
  Positionen ohne auffindbares Produkt werden als `custom`-Positionen geschrieben.
- **Staffelpreise** (`oxprice2article`) und **Lieferanten** (`oxvendor`) fehlen noch.
- Produkte werden in **allen** Sales-Channels sichtbar gesetzt (`VISIBILITY_ALL`).

## Voraussetzungen

- Exporter: PHP ≥ 8.2 (`oxid-export/`), ≥ 7.2 (`oxid-export-php72/`,
  `oxid-export-standalone/`), jeweils mit `ext-pdo`, `ext-mbstring`, `ext-json` und
  MySQL-Zugriff auf die OXID-Datenbank.
- Importer: Shopware-6.7-Projekt (PHP 8.2+).

## Troubleshooting

| Symptom | Ursache / Lösung |
|---|---|
| `There are no commands defined in the "mojo:oxid" namespace` | DB nicht erreichbar → Shopware kann Plugins nicht laden. `DATABASE_URL` prüfen bzw. Console im Container ausführen. |
| `Could not find encoder with name "OxidLegacy"` | `bin/console cache:clear` — der DI-Container muss den getaggten Encoder neu einsammeln. |
| `Product with number "…" already exists` | Doppelte `OXARTNUM`; aktuelle Plugin-Version löst das automatisch auf. |
| `OrderStockSubscriber::changeset(): $productId must be of type string` | Alt-Datensätze mit `type='product'` ohne `referenced_id`; der Import repariert sie vor dem Order-Lauf automatisch. |
| 0 Bilder im Export | Diagnose-Block der Export-Ausgabe lesen (Mapping, `pic_fields`, `source_dir`). |
| Bestellungen komplett löschen | ``DELETE FROM `order`;`` (Kaskaden räumen Positionen/Transaktionen/Adressen mit ab), danach neu importieren. |
