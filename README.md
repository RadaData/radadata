# Quick start

## Initial deployment

1. composer install
2. php crawler.php discover
3. php crawler.php download_laws

## Update existing database with new laws

1. php crawler.php update
2. php crawler.php download_laws


# Available commands


## check

### Arguments

- fix (whether or not to fix the problem (usually by removing non-existent files and marking entry for re-download))

#### Example

`php crawler.php check 1`

### What does it do?

1. Removes some unknown files for NOT_DOWNLOADED laws.
2. For laws, marked as HAS_TEXT, if the text is not actually present, removes all files and markes entry as NOT_DOWNLOADED.
3. For laws, marked as HAS_TEXT, but text entry is invalid, removes all files and markes entry as NOT_DOWNLOADED.
4. For laws, marked as HAS_TEXT == UNKNOWN, checks for the card presences. If the card says that text is not existent, update the HAS_TEXT, otherwise delete the files and mark entry as NOT_DOWNLOADED.




## discover

### Arguments

- reset (used to reset cached list of issuers)

#### Example

`php crawler.php discover 1`

### What does it do?

1. Parse all available issuers and their meta data.
2. For each issuer, schedule a law list scans starting from the very first law page.
3. Spawn a discover_issuer crawler to crawl jobs from step 2. This crawler schedules crawls for all law list pages of an issuer.
4. Spawn a discover_law_urls crawler, which parses each law list page and adds newly discovered laws to the DB (which can be downloaded later with `download_laws`).

This command works best for initial scans.




## update

### Arguments

- reset (used to reset cached list of issuers)

#### Example

`php crawler.php update 1`

### What does it do?

1. Works exactly as discover, but scans law lists from the last page until it finds a discovered law.
2. Spawns 4 update_issuer workers, which parse each law list page until it found known law or reach first page. Any newly discovered laws are added to the DB (which can be downloaded later with `download_laws`).

This command works best for the later scans, to skip the hundreds of pages with old laws.




## download_laws

#### Example

`php crawler.php download_laws`

### What does it do?

1. Takes all not downloaded laws from the DB and adds them to job queue.
2. Spawns 20 download_law worksers, which download particular scheduled law at a time. Each worker downloads law files and then marks the law as DONWLOADED (and HAS_TEXT if it has text).


## clean_jobs

#### Example

`php crawler.php clean_jobs`

### What does it do?

Removes all jobs from the queue. Usually, you should run `php crawler.php update` after this.



## debug

List current crawler config in readable format. It's taken from conf/conf.txt. That file contains serialized list of law
issuers, which could be updated with 'php crawler.php update 1'.