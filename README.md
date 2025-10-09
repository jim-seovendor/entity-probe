# RankLens Entity Evaluator: Conditioned Probing with Resampling
**Entity-conditioned probing with resampling** — PHP 7.4 CLI toolkit implementing standardized prompts, structured outputs, k-sampling, and Bradley–Terry aggregation.

- **PHP**: PHP 7, no jQuery required. CLI only.
- **Libraries**: guzzlehttp/guzzle, opis/json-schema, league/csv, math-php.
- **Data**: Final aggregation product (PL scores, frequency metrics, CIs) from over 15,000 GPT-5 prompts (No Cache)

## Executive Summary
- **Goal.** Compare **PL scores (α=0.2, BT=300)** with **frequency baselines** (@1/@3) to surface category leaders and quality–popularity gaps.
- **Coverage.** 52 categories; global + per-locale (US/GB/DE/JP).
- **Sensitivity.** α/BT variants only reshuffle near cutlines → treat as ties.
- **Deliverables.** CSVs + viewer; see **Data**.

## Layout
```
bin/
  probe.php           # run k samples/entity and write JSONL
  aggregate.php       # compute Bradley–Terry scores + Spearman and export CSV
src/
  Client/LLMClient.php
  Prompt/Template.php
  Schema/Validator.php
  Canon/BrandMap.php
  Rank/BradleyTerry.php
  Stats/Spearman.php
  Stats/Bootstrap.php
data/
  entities.sample.jsonl
  results.sample.jsonl
  pi-top/...
tests/
  unit/...
.github/workflows/
  php.yml             # CI with PHPUnit
```
## Quickstart
```bash
composer install
php bin/probe.php --entities data/entities.sample.jsonl --out data/results.sample.jsonl --k 100 --n 10 --temperature 0.5
php bin/aggregate.php --in data/results.sample.jsonl --out data/scores.csv
```
## Notes
- Replace LLMClient::call() with your vendor API using **structured outputs** (JSON Schema in `Schema/Validator.php`).
- Bradley–Terry implementation uses MM updates; Spearman ρ via MathPHP fallback when installed.
- Add your alias rules to `Canon/BrandMap.php`.


### Choose aggregation method
Bradley–Terry (default):
```bash
php bin/aggregate.php --in data/results.sample.jsonl --out data/scores_bt.csv --method bt
```

Plackett–Luce (listwise):
```bash
php bin/aggregate.php --in data/results.sample.jsonl --out data/scores_pl.csv --method pl
```

## Licensing
- **Code:** MIT (c) 2025 SEO Vendor LLC
- **Data:** CC BY 4.0
