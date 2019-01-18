# Heavy-lifter
Doing the heavy lifting for local Drupal development.

## Installation

### Drupal 8
* First of all, check if the old submodule initialization of heavy-lifter is still in the project. If it's still there, you have to
deinitialize it with the following command : `git submodule deinit robo`
* After it has been deinitialized, you can remove it's remaining folders : `rm -rf robo` 
* Remove the .gitmodules file from the git : `git rm .gitmodules` (Careful!! When executing this command, check if you dont have 
other submodules initialized in the project)
* Remove the robo folder from git as well : `git rm  robo`

* Now you can proceed on installing the heavy-lifter with composer : `composer require eaudeweb/heavy-lifter`
* Execute the configuration script for heavy-lifter : `./vendor/bin/robo site:config`
* Copy `example.robo.yml` to `robo.yml`, customize the username and password to the ones provided by system administrator,
and then execute `./vendor/bin/robo sql:sync` to see if the installation successfully worked


## How to use it inside a project
* Copy `example.robo.yml` to `robo.yml` and customize the username and password to the ones provided by system administrator
* Get the database dump and import: `./vendor/bin/robo sql:sync`
* Get the files archive: `./vendor/bin/robo files:sync`
* Enable development: `./vendor/bin/robo site:develop`
