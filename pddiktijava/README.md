# pddiktijava

Java scraper pipeline for PDDIKTI Kemdikbud data.

## Requirements

- Java 17 or newer.

## Usage

Compile and run from the repository root:

```bash
javac pddiktijava/*.java
java -cp pddiktijava DiktiScraper
```

Useful options:

```bash
java -cp pddiktijava DiktiScraper --limit 10
java -cp pddiktijava DiktiScraper --delay 50
java -cp pddiktijava DiktiScraper --output pddiktijava/mahasiswa_itb.txt
```
