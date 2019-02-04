# Heavy-lifter
Doing the heavy lifting for local Drupal development.

## Installation

### Drupal 8
* Install the heavy-lifter with composer : `composer require eaudeweb/heavy-lifter`
* Execute the configuration script for heavy-lifter : `./vendor/bin/robo site:config`
* Copy `example.robo.yml` to `robo.yml`, customize the username and password to the ones provided by system administrator,
and then execute `./vendor/bin/robo sql:sync` to see if the installation successfully worked


## How to use it inside a project
* Copy `example.robo.yml` to `robo.yml` and customize the username and password to the ones provided by system administrator
* Get the database dump and import: `./vendor/bin/robo sql:sync`
* Get the files archive: `./vendor/bin/robo files:sync`
* Enable development: `./vendor/bin/robo site:develop`
