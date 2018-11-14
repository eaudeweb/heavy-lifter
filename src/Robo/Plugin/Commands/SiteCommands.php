<?php

namespace EauDeWeb\Robo\Plugin\Commands;



class SiteCommands extends CommandBase {

  /**
   * @inheritdoc
   */
  protected function validateConfig() {
    parent::validateConfig();
    $username =  $this->configSite('develop.admin_username');
    if (empty($username)) {
      $this->yell('project.sites.default.develop.admin_username not set, password will not be reset');
    }
  }


  /**
   * Setup development.
   *
   * @command site:develop
   *
   * @param string $newPassword
   * @throws \Exception when cannot find the Drupal installation folder.
   */
  public function siteDevelop($newPassword = 'password') {
    $this->validateConfig();
    $drush = $this->drushExecutable();

    // Reset admin password if available.
    $username = $this->configSite('develop.admin_username');
    if ($this->isDrush9()) {
      $this->taskExec($drush)->arg('user:password')->arg($username)->arg($newPassword)->run();
    }
    else {
      $this->taskExec($drush)->arg('user:password')->arg('--password=' . $newPassword)->arg($username)->run();
    }

    $this->taskExec($drush)->arg('pm:enable')->arg('devel')->run();
    $this->taskExec($drush)->arg('pm:enable')->arg('webprofiler')->run();

    $root = $this->projectDir();
    if ($dev = realpath($root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'dev')) {
      $this->taskExec($drush)->arg('config:import')->arg('dev')->arg('--partial')->run();
    } else {
      $this->yell("Skipping import of 'dev' profile because it's missing");
    }
  }
}