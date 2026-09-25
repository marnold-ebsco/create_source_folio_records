# source-folio-records

Builds FOLIO Instance/Holdings/Item JSON records from `ezborrow.tsv` (or any
similarly-shaped delimited file) — **build-only**, no writing to a live FOLIO
tenant. The one exception is a single read-only lookup of a tenant's existing
statistical codes (see `statisticalCodeIds` under Mapping files below), made
only if at least one mapping file actually maps one.

## Setup

### Option 1: just want to run it (no clone needed)

`install.sh` fetches a runtime-only copy — `bin/`, `src/`, `mapping/`,
`composer.json`/`composer.lock`, this README — into a fresh directory (no
`tests/`, no sample `.tsv` fixtures, no git history left behind) and runs
`composer install --no-dev` there:

```bash
curl -fsSL https://raw.githubusercontent.com/marnold-ebsco/create_source_folio_records/main/install.sh | bash -s -- ./create_source_folio_records
```

or, if you already have the script:

```bash
bash install.sh [target-dir]   # target-dir defaults to ./create_source_folio_records
```

Then use `php <target-dir>/bin/build-inventory ...` / `bin/load-inventory ...`
exactly as described below. If `target-dir` had no `tenant.ini`, a blank
`tenant.ini.example` template is installed as `tenant.ini` — fill in your
tenant's real `okapiUrl`/`tenant_id`/`username`/`password` before using
`--config` with either script; a `tenant.ini` that already exists is never
overwritten.

**To update an existing install**, either re-run the exact same command again
with the same `target-dir` — there's no git remote left in `target-dir` to
`pull` from, so this is how you pick up changes instead — or, more simply,
run the `update.sh` that's already sitting inside `target-dir` (installed
there for exactly this), from inside that directory:

```bash
bash update.sh
```

Either way, `bin/`, `src/`, `mapping/`, the composer files, and `update.sh`
itself are replaced wholesale with the latest versions (so a file removed or
renamed upstream doesn't linger); `tenant.ini`, `output/`, and `logs/` are
left untouched either way.

### Option 2: developing this project

```bash
git clone git@github.com:marnold-ebsco/create_source_folio_records.git
cd create_source_folio_records
composer install
```

This pulls dev dependencies too (`phpunit`) and keeps `tests/` and the
sample `.tsv` files, for running `vendor/bin/phpunit` and testing against
real data — see [Tests](#tests) below.

## Usage

```bash
php bin/build-inventory --input=ezborrow.tsv --mapping-dir=mapping/ezborrow
```

If `--input` is omitted, you're prompted for the input file path
interactively. Every source file has its own subfolder under `mapping/`
(e.g. `mapping/ezborrow/`, `mapping/source_folio/` — see
[Mapping files](#mapping-files) below); if `--mapping-dir` is omitted, you're
prompted to pick one from whatever subfolders exist under `mapping/` rather
than silently defaulting to any one of them, since building the wrong source
file against the wrong mapping set (e.g. tagging `source_folio.tsv` records
with `ezborrow`'s statistical code) fails silently rather than erroring.

If `--config` is omitted and at least one `*.ini` file exists at the project
root (e.g. `tenant.ini`), you're prompted to pick one to use as `--config`
rather than needing to type its path — `tenant.ini.example` is never offered,
since its filename doesn't end in `.ini`.

`instanceTypeId` (Instance) is a required field with no source column in
`ezborrow.tsv`, resolved in this order:

1. A CLI option: `--resource-type=NAME`.
2. A literal `value` already filled into `instance_field_mapping.json` under
   the chosen `--mapping-dir`.
3. The tenant's own instance types (via `--config`, or the `.ini` prompt
   above) are checked for one literally named `text` — if found, that's used
   automatically for every instance instead of prompting.
4. An interactive prompt (asked once per run, reused for every record) —
   only reached if no config was available to check with.

`materialTypeId`/`permanentLoanTypeId` (Item) and `permanentLocationId`
(Holdings) never prompt: each uses whatever's mapped in the relevant file
under the chosen `--mapping-dir` — `materialTypeId` from `Material_Format`,
`permanentLocationId` from `Item_Permanent_Shelving_Location` falling back to
`Item_Holding_Location` — falling back further to each mapping file's own
literal `fallback_value` (`Migration` for material type and location,
`Can circulate` for loan type — see [Mapping files](#mapping-files) below)
whenever a row leaves them blank. `--material-type=NAME`/`--loan-type=NAME`
can still override the whole field, same as `--resource-type`. If the tenant
has no material type, loan type, or location actually named by its
configured fallback, the run quits immediately (with `--config` required in
the first place, since resolving any of these needs a live tenant lookup)
rather than only failing once a row actually needs that fallback.

```bash
php bin/build-inventory --input=ezborrow.tsv --mapping-dir=mapping/ezborrow \
    --config=tenant.ini --resource-type=text
```

`--test` forces `materialTypeId` and `permanentLocationId` to the literal
name "Migration" for every row — ignoring the row's own column data and
each field's configured fallback — resolved via a live lookup against the
tenant (so `--config` is required) same as any other `live:` field. Meant
for exercising a build against a real tenant before its real location/
material type reference data has been loaded yet: as long as the tenant
already has a material type and a location each literally named
"Migration", every record resolves against those instead of needing every
real value up front.

```bash
php bin/build-inventory --input=ezborrow.tsv --mapping-dir=mapping/ezborrow \
    --config=tenant.ini --resource-type=text --test
```

Output (default `output/instances.json`, `output/holdings.json`,
`output/items.json`, one JSON object per line) and a run log under `logs/`
recording any validation errors (e.g. a row missing a required field).

### Instance/Holdings grouping

Rows aren't simply 1:1:1 with instance/holdings/item. Rows sharing the same
title + author collapse into a single shared Instance; within that, rows also
sharing the same call number + location collapse into a single shared
Holdings. Every row still produces its own Item, attached to whichever
(possibly shared) Holdings its own call number/location matches — so one
title can end up with one Instance, one or more Holdings, and one or more
Items per Holdings. See `src/RecordGrouping.php` for the exact matching rules
(normalizes whitespace/case; an actual difference in the data is never
merged). A record that fails validation only skips that record and anything
downstream of it for that one row — an already-built shared Instance or
Holdings from an earlier row is never discarded because a later row sharing
its key fails.

See `--help` for every option (mapping/output directories, delimiter,
output format, `--config` for statistical code lookups, etc.):

```bash
php bin/build-inventory --help
```

## Mapping files

Each source file gets its own subfolder under `mapping/` — `mapping/ezborrow/`
for `ezborrow.tsv`, `mapping/source_folio/` for `source_folio.tsv` — containing
`instance_field_mapping.json`, `holdings_field_mapping.json`, and
`item_field_mapping.json`. Point `--mapping-dir` at the one matching your
`--input`, or leave it off to be prompted for which subfolder to use. They use
the same format as `folio-migration-mapper`'s `create_map`/`verify_map` tools:

```json
{
    "data": [
        {
            "folio_field": "title",
            "legacy_field": "Title",
            "value": "",
            "description": "...",
            "fallback_legacy_field": ""
        }
    ]
}
```

For a given `folio_field`: a non-empty `value` is used verbatim for every row;
otherwise the row's `legacy_field` column is used if present and non-empty;
otherwise `fallback_legacy_field`; otherwise a non-empty `fallback_value` is
used verbatim (unlike `value`, this only kicks in when neither column had
anything for that row — e.g. `materialTypeId`'s `Material_Format` column
falling back to a literal `Migration` only on rows that leave it blank);
otherwise the field is left out of that row's record. A repeatable group
(e.g. a second identifier) uses `[N]` bracket notation before the dot, e.g.
`identifiers[2].value` (1-based). A repeatable *scalar* field has no
subfields, so no dot — just the bracketed key on its own, e.g.
`statisticalCodeIds[0]` (this one starts at 0; see below).

Only the fields actually requested for this project are included — see each
`src/Schema/*.php` class for the full list each record type supports here.

### `statisticalCodeIds`

All three record types support `statisticalCodeIds`, but as a *repeatable
scalar* field rather than a delimited list: each statistical code is its own
mapping entry, starting at `statisticalCodeIds[0]`, then `[1]`, `[2]`, etc. —
add as many `[N]` entries as you need. Each entry's `value` (or mapped column)
can be either a real statistical code's UUID or its name:

```json
{
    "folio_field": "statisticalCodeIds[0]",
    "legacy_field": "Not mapped",
    "value": "",
    "description": "..."
}
```

A UUID is checked against the tenant's existing statistical codes and passed
through unchanged if found; a name is looked up and replaced with its real
id. Either way, anything not found is dropped from the built record and
logged as a warning rather than silently loaded as a broken reference.
Because this requires knowing what the tenant's statistical codes actually
are, filling in *any* `statisticalCodeIds[N]` entry requires passing
`--config=tenant.ini` to `bin/build-inventory` (same `FolioConfig`-format INI
file `bin/load-inventory` uses — see below); leaving every
`statisticalCodeIds[N]` entry unmapped (the default) needs no connection at
all.

## Loading into a live tenant

`bin/load-inventory` loads the records `bin/build-inventory` wrote into a real
FOLIO tenant, via `phpFolioClient`'s `upsert()` (POST if the id doesn't exist
yet, PUT if it does). It loads instances, then holdings, then items, in that
order, since holdings reference an instanceId and items reference a
holdingsRecordId that must already exist.

```bash
php bin/load-inventory --config=tenant.ini
```

`tenant.ini` holds FOLIO connection settings per `phpFolioClient`'s
`FolioConfig` (`okapiUrl`, `tenant_id`, `username`, `password`; `sslVerify`,
`timeout`, `debug`, etc. are optional) — never commit a filled-in one, only a
`.sample.ini`-style template.

Before a real run, `bin/test-connection` checks that a config file actually
authenticates and can read from the tenant — logging in and making one
read-only request, nothing else — so a bad `tenant.ini` (wrong credentials,
tenant id, or URL) surfaces immediately instead of partway through a build
or load:

```bash
php bin/test-connection --config=tenant.ini
```

Each record type gets its own timestamped log file under `logs/` (e.g.
`instances_20260924_101500_ab12cd.log`) recording any record that failed to
load and why; a one-line summary per type is also printed to stderr. See
`--help` for `--input-dir`/`--logs-dir` overrides.

```bash
php bin/load-inventory --help
```

## Tests

```bash
vendor/bin/phpunit
```

No static-analysis tooling is configured; `php -l <file>` is the syntax check
available.
