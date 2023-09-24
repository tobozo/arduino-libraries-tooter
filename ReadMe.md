## Arduino Libraries Tooter 📯🐘

This is the source code for the Bot running behind the fediverse account [@arduinoLibraries@botsin.space](https://botsin.space/@arduinoLibraries)

<img width=250 src=./assets/head.jpg>

Just like its [Twitter counterpart](https://twitter.com/ArduinoLibs), this app publishes the latest changes found in [Arduino Library Registry](https://www.arduino.cc/reference/en/libraries/) to the [Mastodon](https://github.com/mastodon/mastodon) network.


<img width=500 src=./assets/Arduino-IDE-add-library-featured-image.jpg>

Requirements:
  - linux (wget, gzip, diff)
  - php8+
  - composer
  - Mastodon Application TOKEN

Quick Start:

  - Copy `.env.example` to `.env`
  - Edit the `.env` file to set `MASTODON_API_KEY` and `MASTODON_API_URL` values, then save
  - set `cron.php` as a hourly crontab `0 * * * * cd /path/to/arduino-libraries-announcer/ && /usr/bin/php cron.php >> logfile.txt`


Dependencies:
  - https://github.com/Eleirbag89/MastodonBotPHP
  - https://github.com/halaxa/json-machine
  - https://github.com/vlucas/phpdotenv

RoadMap:
  - Stop relying on `diff` to find changes in `library_index.json`

  - **GITHUB_API_CACHE** (https://github.com/KnpLabs/php-github-api):
    - Cache `{owner}/{repo}.json` API response (https://github.com/php-cache/filesystem-adapter):
      - get/repos/{owner}/{repo} => `stargazers_count`, `forks_count`, `topics`
      - get/repos/{owner}/{repo}/releases => {draft=false && prerelease=false}? `tag_name`, `name`, `published_at`


  - **ONE TIME JOB** Foreach github repo in `library_index.json`
    - GITHUB_API_CACHE( repo )

  - **CRON JOB** Foreach `arduinorepo` in `library_index.json`
    - Compare `arduinorepo` state with `library_index.consolidated.json` state, create/update GITHUB_API_CACHE if necessary
    - Get `githubrepo` from GITHUB_API_CACHE
    - Compare semver between `arduinorepo`.version and `githubrepo`.version, keep highest
    - Add {`name`,`version`,`architectures[]`, `author`, `url`, `description`, `hashtags[]`} to `library_index.consolidated.json`


Resources:
  - https://downloads.arduino.cc/libraries/library_index.json.gz
  - https://www.arduino.cc/reference/en/libraries/
  - https://github.com/arduino/library-registry

Inspiration:
  - https://twitter.com/ArduinoLibs
  - https://github.com/njh/arduino-libraries
  - https://www.arduinolibraries.info/



