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
exactly as described below. Re-run it (into a new or emptied directory) to
pick up updates — there's no git remote left in `target-dir` to `pull` from.

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
php bin/build-inventory --input=ezborrow.tsv
```

`instanceTypeId` (Instance), `materialTypeId`, and `permanentLoanTypeId` (Item)
are required fields with no source column in `ezborrow.tsv`. Each is resolved,
in this order:

1. A CLI option: `--resource-type=NAME`, `--material-type=NAME`, `--loan-type=NAME`.
2. A literal `value` already filled into the relevant file under `mapping/`.
3. An interactive prompt (asked once per run, reused for every record).

```bash
php bin/build-inventory --input=ezborrow.tsv \
    --resource-type=text --material-type=book --loan-type="Can circulate"
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

`mapping/instance_field_mapping.json`, `mapping/holdings_field_mapping.json`,
and `mapping/item_field_mapping.json` use the same format as
`folio-migration-mapper`'s `create_map`/`verify_map` tools:

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
otherwise `fallback_legacy_field`; otherwise the field is left out of that
row's record. A repeatable group (e.g. a second identifier) uses `[N]`
bracket notation before the dot, e.g. `identifiers[2].value` (1-based). A
repeatable *scalar* field has no subfields, so no dot — just the bracketed
key on its own, e.g. `statisticalCodeIds[0]` (this one starts at 0; see
below).

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
