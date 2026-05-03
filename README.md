# PDDIKTI kemdikbud API

## pddiktidart

### Description

Data Pipeline for:

- Querying List of Queries. Example: List of ITB Student Queries.
- Getting Mahasiswa Details for Every Query Result. Example: Get Mahasiswa Details for ITB Student Queries.

### How to Use

Run the following command to execute the script

```bash
dart pddiktidart/dikti_scraper.dart
```

This will:

- Create API Call for Every `all_mahasiswa_itb.dart` query.
- Create another API call for every `all_mahasiswa_itb.dart` query result.
  - Each API call will have 20 milliseconds delay (Customizable).
- Create a file called `mahasiswa_itb.txt`. This file contains the details of all the students from the queries.

## pddiktijs

### Description

JavaScript/Node.js version of the Dart scraper pipeline. It:

- Reads query strings from `pddiktijs/all_mahasiswa_itb.js`.
- Queries PDDIKTI mahasiswa search for each query.
- Fetches detail data for every mahasiswa search result.
- Appends each detail response as a JSON line to an output file.

### Requirements

- Node.js 18 or newer.

### How to Use

Run the scraper from the repository root:

```bash
node pddiktijs/dikti_scraper.js
```

By default this reads `pddiktijs/all_mahasiswa_itb.js`, waits 20 milliseconds between search queries, and writes to `pddiktijs/mahasiswa_itb.txt`.

Useful options:

```bash
node pddiktijs/dikti_scraper.js --limit 10
node pddiktijs/dikti_scraper.js --delay 50
node pddiktijs/dikti_scraper.js --query-file pddiktijs/all_mahasiswa_itb.js --output pddiktijs/mahasiswa_itb.txt
```

You can also use it as a CommonJS module:

```js
const {
  searchMahasiswa,
  getMhsDetail,
  buildHeaders,
} = require("./pddiktijs/dikti_scraper");

async function run() {
  const headers = await buildHeaders();
  const results = await searchMahasiswa("Muhammad ITB", headers);
  const detail = await getMhsDetail(results[0].id, headers);
  console.log(detail);
}

run();
```

## pddiktits

### Description

TypeScript/Node.js version of the same scraper pipeline. It:

- Reads query strings from `pddiktits/all_mahasiswa_itb.ts`.
- Queries PDDIKTI mahasiswa search for each query.
- Fetches detail data for every mahasiswa search result.
- Appends each detail response as a JSON line to an output file.

### Requirements

- Node.js 23.6 or newer to run `.ts` files directly.
- Optional: `npm install` inside `pddiktits` if you want to run TypeScript type checks with `npm run check`.

### How to Use

Run the scraper from the repository root:

```bash
node pddiktits/dikti_scraper.ts
```

By default this reads `pddiktits/all_mahasiswa_itb.ts`, waits 20 milliseconds between search queries, and writes to `pddiktits/mahasiswa_itb.txt`.

Useful options:

```bash
node pddiktits/dikti_scraper.ts --limit 10
node pddiktits/dikti_scraper.ts --delay 50
node pddiktits/dikti_scraper.ts --query-file pddiktits/all_mahasiswa_itb.ts --output pddiktits/mahasiswa_itb.txt
```

You can also import the typed functions:

```ts
import {
  buildHeaders,
  getMhsDetail,
  searchMahasiswa,
} from "./pddiktits/dikti_scraper.ts";

async function run() {
  const headers = await buildHeaders();
  const results = await searchMahasiswa("Muhammad ITB", headers);
  const detail = results?.[0]?.id
    ? await getMhsDetail(results[0].id, headers)
    : null;
  console.log(detail);
}

run();
```

## pddiktiphp

### Description

PHP version of the same scraper pipeline. It:

- Reads query strings from `pddiktiphp/all_mahasiswa_itb.php`.
- Queries PDDIKTI mahasiswa search for each query.
- Fetches detail data for every mahasiswa search result.
- Appends each detail response as a JSON line to an output file.

### Requirements

- PHP 8.0 or newer.

### How to Use

Run the scraper from the repository root:

```bash
php pddiktiphp/dikti_scraper.php
```

By default this reads `pddiktiphp/all_mahasiswa_itb.php`, waits 20 milliseconds between search queries, and writes to `pddiktiphp/mahasiswa_itb.txt`.

Useful options:

```bash
php pddiktiphp/dikti_scraper.php --limit 10
php pddiktiphp/dikti_scraper.php --delay 50
php pddiktiphp/dikti_scraper.php --query-file pddiktiphp/all_mahasiswa_itb.php --output pddiktiphp/mahasiswa_itb.txt
```

You can also include the scraper functions:

```php
<?php

require __DIR__ . '/pddiktiphp/dikti_scraper.php';

$headers = build_headers();
$results = search_mahasiswa('Muhammad ITB', $headers);
$detail = !empty($results[0]['id'])
    ? get_mhs_detail($results[0]['id'], $headers)
    : null;

print_r($detail);
```

## pddiktipy

Please refer to the original pddiktipy repository for more information: https://github.com/IlhamriSKY/PDDIKTI-kemdikbud-API

## Tools Related

- Scraper for MS Teams ITB -> [itb-nim-scrapper](https://github.com/hendpraz/itb-nim-scrapper)
- ITB NIM Finder Backend -> [nim-finder-backend](https://github.com/hendpraz/nim-finder-backend)
- ITB NIM Finder Frontend -> [nim-finder-hendpraz](https://github.com/hendpraz/nim-finder-hendpraz)
