## Arduino Libraries Tooter 🐘

This is the source code for the Bot running behind the fediverse account [@arduinoLibraries@botsin.space](https://botsin.space/@arduinoLibraries).

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
  - set `cron.php` as a hourly crontab

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
  - https://github.com/njh
  - https://www.arduinolibraries.info/



