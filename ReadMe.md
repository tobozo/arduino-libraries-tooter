## Arduino Libraries Tooter 📯🐘

This is the source code for the Bot running behind the fediverse/bluesky accounts:
  - 🐘 [@arduinoLibraries@botsin.space](https://botsin.space/@arduinoLibraries)
  - 🟦 [@arduinolibs.bsky.social](https://bsky.app/profile/arduinolibs.bsky.social)

<img width=250 src=./assets/head.jpg>

Just like its [Twitter counterpart](https://twitter.com/ArduinoLibs), this app publishes the latest changes found in [Arduino Library Registry](https://www.arduino.cc/reference/en/libraries/) to the [Mastodon](https://github.com/mastodon/mastodon) and [Bluesky](https://bsky.app) networks.


<img width=500 src=./assets/Arduino-IDE-add-library-featured-image.jpg>

Requirements:
  - linux (wget, gzip)
  - php8+
  - composer
  - Mastodon Application TOKEN
  - Bluesky Application TOKEN

Quick Start:

  - Copy `.env.example` to `.env`
  - Edit the `.env` file to set `MASTODON_API_KEY` and `MASTODON_API_URL` and `MASTODON_ACCOUNT_ID` values
  - Edit the `.env` file to set `BSKY_API_APP_NAME` and `BSKY_API_APP_USER` and `BSKY_API_APP_TOKEN` values
  - Schedule a run every 10mn by either:
    - Setting `cron.php` as a crontab `*/10 * * * * cd /home/vRAk/_src/php/arduino-libraries-announcer/ && sh cron.sh >> logfile.txt 2>&1`
    - Or by using a systemd timer (TODO: document this)

Dependencies:
  - https://github.com/Eleirbag89/MastodonBotPHP
  - https://github.com/halaxa/json-machine
  - https://github.com/vlucas/phpdotenv

Resources:
  - https://downloads.arduino.cc/libraries/library_index.json.gz
  - https://www.arduino.cc/reference/en/libraries/
  - https://github.com/arduino/library-registry

Inspiration:
  - https://twitter.com/ArduinoLibs
  - https://github.com/njh/arduino-libraries
  - https://www.arduinolibraries.info/

Thanks:
  - @tipiak75

