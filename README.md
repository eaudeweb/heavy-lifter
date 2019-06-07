# Heavy-lifter
Doing the heavy lifting for local Drupal development.

## Installation

https://packagist.org/packages/eaudeweb/heavy-lifter

### Drupal 8

* Install the heavy-lifter with composer : `composer require eaudeweb/heavy-lifter`

* Execute the configuration script for heavy-lifter : `./vendor/bin/robo site:config`

* Copy `example.robo.yml` to `robo.yml`, customize the username and password to the ones provided by system administrator

* (Optional) You can add excluded drush commands to any of heavy-lifter's robo commands in `robo.yml` 

Example : 
```
site:
    update:
         excluded_commands:
           - locale:check
           - yyy
```

* (Optional) You can add extra drush commands to any of heavy-lifter's robo commands in `robo.yml`

Example:
```
site:
    update:
         extra_commands:
           - locale:update
```    

* Execute `./vendor/bin/robo sql:sync` to see if the installation successfully worked

### Drupal 7

The installation guideline on Drupal 7 is the same as the one on Drupal 8, just a few observations are necessary:

* Not all the available robo commands are available for Drupal 7 websites. (Some commands commit modifications available only to Drupal 8 websites and therefore have not been implemented/tested on Drupal 7)

* All the Drupal 7 implementations have been set to be executed from the root folder of the project, because they change to the `docroot` directory by themselves. Therefore, all robo commands on Drupal 7 should be executed from the root directory of the project. 

## How to use it inside a project

* Copy `example.robo.yml` to `robo.yml` and customize the username and password to the ones provided by system administrator

* Get the database dump and import: `./vendor/bin/robo sql:sync`

* Update the site's configuration and database : `./vendor/bin/robo site:update`

* Get the files archive: `./vendor/bin/robo files:sync`

* Create archive with files directory to the given path : `/vendor/bin/robo files:dump [destination]`

* Enable development: `./vendor/bin/robo site:develop`

* Download the database dump from the remote storage, without importing it : `/vendor/bin/robo sql:download [destination]`


## Custom development scripts

If you want to run custom drush scripts at the end of the site:develop command, add these scripts in the PROJECT/etc/scripts/develop folder.


## Database anonymize 

1. Run `composer require eaudeweb/gdpr-dump`
2. Define your anonymize schema in an anonymize.schema.yml file, in the project root (in the same folder as robo.yml)
3. Run `./vendor/bin/robo sql:dump --anonymize`

Row values listed under **ignored_values** will not be anonymized.

### anonymize.schema.yml example

```
users_field_data:
  name:
    formatter: email
    conditions:
      - uid: [1, 76, 228, 81, 116, 117, 149, 393]
    conditions_action: ignore
  telephone:
    formatter: phoneNumber
  mail:
    formatter: email
    conditions:
      - uid: [1, 76, 228, 81, 116, 117, 149, 393]
    conditions_action: ignore
  pass:
    formatter: password
  preferred_admin_langcode:
    formatter: clear
table2:
  column1:
    formatter: randomText
    conditions:
      - name: ['Name to anonymize']
      - conditions_action: anonymize
...
```

### Formatter types

- name - generates a name
- phoneNumber - generates a phone number
- username - generates a random user name
- password - generates a random password
- email - generates a random email address
- date - generates a date
- longText - generates a sentence
- number - generates a number
- randomText - generates a sentence
- text - generates a paragraph
- uri - generates a URI
- clear - generates an empty string

For more information, check https://github.com/machbarmacher/gdpr-dump
