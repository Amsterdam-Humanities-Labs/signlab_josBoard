# signlab_josBoard
Web pages that show how often each target word occurs in the SignCollect sentences (the "Josje-Board").

## What it does
- `index.html` (Gloss Matrix): per label, it counts how many sentences contain each word from table `jb_woorden`. It matches on `sentences.lemmaList`. Data comes from `fetch_matrix_data.php`, `fetch_gloss_details.php` and `fetch_sentences.php`. Results are cached for 24 hours.
- `words_manager.php`, `edit_word.php`, `batch_add.php`: list, edit and bulk-add words in `jb_woorden`.
- `addZinnen.php`, `editSentence.php`, `deleteSentence.php`: JSON endpoints that add, edit and delete sentences (zinnen). `addZinnen.html` also calls `getZinnen.php`, which is not in this repo.
- `lemma_lookup.py`: fills `sentences.lemmaList` from `sentences.zinString`, using lemmas in `hh_words`. [signlab_pythonCron](https://github.com/Amsterdam-Humanities-Labs/signlab_pythonCron) runs it hourly.
- One-off tools: `update_jb_lemmas.py`, `getUniqueWords.py`, `searchWordInSentences.py` (fill lemmas), `backup_sentences_table.php`, `compare_csv_sentences.php`, `update_sentences_from_csv.php`, `csv/compare_csv.py` (sentence CSV checks).

## Where it runs
The core server: `/web/josBoard`, URL `https://signcollect.nl/josBoard/`.
The server runs its own copy. This repo is a backup of that code and is not deployed yet.

## Status
Production (copy of the live code, 2026-09-22). See [signlab_signcollect-stack#35](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-stack/issues/35).

## How to run or deploy
Not deployed from here. Open `https://signcollect.nl/josBoard/`. Only `index.html` checks the login (`/userProtect.js`); the PHP pages do not.
The CSV scripts run from the command line, for example:
```
php update_sentences_from_csv.php --dry-run
```
They read `csv/zinnen_old.csv` and `csv/zinnen_new.csv` (not in git).

## Configuration
- PHP: `../mysql_config.php` (not in git) sets `$servername`, `$username`, `$password`, `$database`.
- Python: environment variable `DB_PASS`. `searchWordInSentences.py` also needs `OPENROUTER_API_KEY`.
- `lemma_lookup.py` imports `ClientMonitor` from `/home/gomer/pythonCron`.
- Cache files are written next to the scripts: `matrix_data_cache.json`, `matrix_progress.json`, `details_cache/`, `sentences_cache/`, `backup/`.

## Dependencies
- MySQL database `admin_gebarenoverleg`: tables `jb_woorden`, `sentences`, `sentences_logs`, `hh_words`, `labels`.
- `/uniqueLabels.php` and `/userProtect.js` on the core server docroot.
- Python: `mysql-connector-python`; the lemma tools also use `spacy` (`nl_core_news_lg`) and `pattern`.

## License and citation

Apache License 2.0, copyright University of Amsterdam: see [LICENSE](LICENSE) and
[NOTICE](NOTICE). You may use it, also commercially, as long as you credit
Gomer Otterspeer / University of Amsterdam as the source. To cite it, use
[CITATION.cff](CITATION.cff) (the *Cite this repository* button on GitHub) or the DOI [10.21942/uva.33980338](https://doi.org/10.21942/uva.33980338).
