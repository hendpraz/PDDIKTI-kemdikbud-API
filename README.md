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

## pddiktipy

Please refer to the original pddiktipy repository for more information: https://github.com/IlhamriSKY/PDDIKTI-kemdikbud-API

## Tools Related
- Scraper for MS Teams ITB -> [itb-nim-scrapper](https://github.com/hendpraz/itb-nim-scrapper)
- ITB NIM Finder Backend -> [nim-finder-backend](https://github.com/hendpraz/nim-finder-backend)
- ITB NIM Finder Frontend -> [nim-finder-hendpraz](https://github.com/hendpraz/nim-finder-hendpraz)
