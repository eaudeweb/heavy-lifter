<?php

namespace EauDeWeb\Robo\Plugin\Commands;

use Robo\Robo;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Class SiteCommands with commands specific to manage site structure/data.
 *
 * @package EauDeWeb\Robo\Plugin\Commands
 */
class SiteCommands extends CommandBase {

  use \Boedah\Robo\Task\Drush\loadTasks;
  use \EauDeWeb\Robo\Task\Curl\loadTasks;

  /**
   * @inheritdoc
   */
  protected function validateConfig() {
    parent::validateConfig();
  }

  /**
   * Setup development.
   *
   * @command site:develop
   *
   * @param string $newPassword
   * @param array $options
   *  Command options.
   * @throws \Exception when cannot find the Drupal installation folder.
   */
  public function siteDevelop($newPassword = 'password', $options = ['site' => 'default']) {
    $this->allowOnlyOnLinux();
    $site = $options['site'];
    $this->validateConfig();
    $execStack = $this->taskExecStack()->stopOnFail(TRUE);
    $commands = [];

    // Reset admin password if available.
    $username = $this->configSite('site.develop.admin_username', $site);
    if (empty($username)) {
      $this->yell('sites.default.site.develop.admin_username not set, password will not be reset');
    }
    $modules = $this->configSite('site.develop.modules', $site);
    if ($this->isDrush9()) {
      if (!empty($username)) {
        $commands[] = 'user:password ' . $username . ' ' . $newPassword;
      }

      $root = $this->projectDir();
      if ($dev = realpath($root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'dev')) {
        $commands[] = 'config:import dev --partial -y';
      }
      else {
        $this->yell("Skipping import of 'dev' profile because it's missing");
      }

      if (!empty($modules)) {
        foreach ($modules as $module) {
          $commands[] = 'pm:enable ' . $module . ' -y';
        }
      }
    }
    else {
      $execStack->dir('docroot');
      if (!empty($username)) {
        $commands[] = 'user-password ' . $username . ' --password=' . $newPassword;
      }
      if (!empty($modules)) {
        foreach ($modules as $module) {
          $commands[] = 'pm-enable ' . $module . ' -y';
        }
      }
    }

    $excludedCommandsArray = $this->configSite('site.develop.excluded_commands', $site);
    $extraCommandsArray = $this->configSite('site.develop.extra_commands', $site);

    $execStack = $this->updateDrushCommandStack($execStack, $commands, $excludedCommandsArray, $extraCommandsArray, $site);
    $this->addDrushScriptsToExecStack($execStack, 'develop');
    $execStack->run();
  }

  /**
   * @param array $options
   *  Command options.
   * @return \Robo\Result
   * @throws \Robo\Exception\TaskException
   */
  public function siteInstall($options = ['site' => 'default']) {
    $this->allowOnlyOnLinux();
    $site = $options['site'];
    $url =  $this->configSite('sql.sync.source', $site);
    $this->validateHttpsUrl($url);

    $dir = $this->taskTmpDir('heavy-lifter')->run();
    $dest = $dir->getData()['path'] . '/database.sql';
    $dest_gz = $dest . '.gz';

    $url =  $this->configSite('sql.sync.source', $site);
    $username = $this->configSite('sync.username', $site);
    $password = $this->configSite('sync.password', $site);
    $this->validateHttpsUrl($url);
    $download = $this->taskCurl($url)
      ->followRedirects()
      ->failOnHttpError()
      ->locationTrusted()
      ->output($dest_gz)
      ->basicAuth($username, $password)
      ->option('--create-dirs')
      ->run();

    if ($download->wasSuccessful()) {
      $build = $this->collectionBuilder();
      $build->addTask(
        $this->taskExec('gzip')->option('-d')->arg($dest_gz)
      );
      $drush = $this->drushExecutable($site);
      $drush = $this->taskDrushStack($drush)
        ->drush('sql:drop')
        ->drush(['sql:query','--file', $dest]);
      $build->addTask($drush);
      $sync = $build->run();
      if ($sync->wasSuccessful()) {
        return $this->siteUpdate($options);
      }
      return $sync;
    }
    return $download;
  }

  /**
   * Update Drupal core. Check that "drupal/core:^8.7" and
   * "webflo/drupal-core-require-dev:^8.7" exist in composer.json declaration.
   *
   * @command core:update
   */
  public function coreUpdate() {
    $execStack = $this->taskExecStack()->stopOnFail(TRUE);
    $execStack->exec("composer update drupal/core webflo/drupal-core-require-dev --with-dependencies")
      ->run();
  }
  /**
   * Setup for a new project
   *
   * @command project:create
   */
  public function createProject($name = 'name') {
    // create name.conf
    $docroot = $this->configSite('work.dir');
    $conf = "<VirtualHost *:80" . "> \n"
      . "     DocumentRoot ". $docroot . "/" . $name . ".local/web" . "/ \n"
      . "     ServerName " . $name . ".local \n"
      . "     <Directory  ". $docroot  . "/" . $name . ".local/web/> \n"
      . "          AllowOverride All \n"
      . "          Require all granted \n"
      . "     </Directory" . "> \n"
      . "</VirtualHost" . ">";
    $execStack = $this->taskExecStack()->stopOnFail(TRUE);
    $execStack->exec("sudo  touch /etc/apache2/sites-available/" . $name . ".conf")
      ->run();
    $execStack = $this->taskExecStack()->stopOnFail(TRUE);
    $execStack->exec('echo "' . $conf . '" | sudo tee /etc/apache2/sites-available/' . $name . '.conf')
      ->run();
    // modify hosts
    $execStack = $this->taskExecStack()->stopOnFail(TRUE);
    $execStack->exec("cat /etc/hosts | sudo tee /etc/copy.txt")->run();
    $execStack = $this->taskExecStack()->stopOnFail(TRUE);
    $execStack->exec('sed "1 s/^/127.0.0.1   "' . $name . '".local \n/" /etc/copy.txt | sudo tee /etc/hosts')
      ->run();
    $execStack = $this->taskExecStack()->stopOnFail(TRUE);
    $execStack->exec('sudo rm /etc/copy.txt')->run();
    // enable name.conf
    $execStack = $this->taskExecStack()->stopOnFail(TRUE);
    $execStack->exec("sudo a2ensite " . $name . ".conf")->run();
    // restart apache2
    $execStack = $this->taskExecStack()->stopOnFail(TRUE);
    $execStack->exec("systemctl restart apache2")->run();
  }

  /**
   * Update the local instance: import configuration, update database, rebuild
   * cache.
   *
   * @command site:update
   *
   * @param array $options
   *  Command options.
   *
   * @return null|\Robo\Result
   * @throws \Robo\Exception\TaskException
   */
  public function siteUpdate($options = ['site' => 'default']) {
    $this->allowOnlyOnLinux();
    $site = $options['site'];
    $this->validateConfig();
    $execStack = $this->taskExecStack()->stopOnFail(TRUE);
    $commands = [];

    if ($this->isDrush9()) {
      $commands[] = "state-set system.maintenance_mode TRUE";

      // Allow updatedb to fail once and execute it again after config:import.
      $commands[] = "updatedb -y";

      $commands[] = 'cache:rebuild';
      if ($this->configSite('site.develop.config_split') === TRUE) {
        $commands[] = 'config-split-import -y';
      }
      else {
        $commands[] = 'config-import -y';
      }
      $commands[] = 'updatedb -y';

      if ($this->isModuleEnabled('locale')) {
        $commands[] = 'locale:check';
        $commands[] = 'locale:update';
      }

//      if ($this->isModuleEnabled('pathauto') && floatval(substr(trim($this->getModuleInfo('pathauto')), -3)) >= 1.4) {
//        $commands[] = 'pathauto:aliases-generate create all';
//      }

      $commands[] = 'cache:rebuild';
      $commands[] = 'state-set system.maintenance_mode FALSE';

    }
    else {
      // Drupal 7
      $execStack->dir('docroot');
      $commands[] = 'vset maintenance_mode 1';
      // Execute the update commands
      $commands[] = 'updatedb -y';

      // The 'drush locale:check' and 'drush locale:update' don't have equivalents in Drupal 7

      // Clear the cache
      $commands[] = 'cc all';
      $commands[] = 'vset maintenance_mode 0';
    }

    $excludedCommandsArray = $this->configSite('site.update.excluded_commands', $site);
    $extraCommandsArray = $this->configSite('site.update.extra_commands', $site);

    $execStack = $this->updateDrushCommandStack($execStack, $commands, $excludedCommandsArray, $extraCommandsArray, $site);

    return $execStack->run();
  }

  /**
   * Create new configuration file.
   *
   * @command site:config
   *
   * @throws \ReflectionException
   */
  public function siteConfig() {
    $reflector = new \ReflectionClass('EauDeWeb\Robo\Plugin\Commands\SiteCommands');
    if ($source = realpath(dirname($reflector->getFileName()) . '/../../../../example.robo.yml')) {
      // example.robo.yml
      $dest = $this->projectDir() . DIRECTORY_SEPARATOR . 'example.robo.yml';
      if (!file_exists($dest)) {
        copy($source, $dest);
        $this->yell('Configuration template created: ' . $dest);
      }
      else {
        $this->yell('Configuration file already exists and it was left intact: ' . $dest);
      }

      // robo.yml
      $dest = $this->projectDir() . DIRECTORY_SEPARATOR . 'robo.yml';
      if (!file_exists($dest)) {
        copy($source, $dest);
        $this->yell('Your personal configuration created: ' . $dest);
      }
      else {
        $this->yell('Personal configuration already exists and it was left intact: ' . $dest);
      }

      // Check .gitignore for robo.yml and add it
      $ignore = $this->projectDir() . DIRECTORY_SEPARATOR . '.gitignore';
      if (file_exists($ignore)) {
        $content = file_get_contents($ignore);
        $content = explode(PHP_EOL, $content);
        if (!in_array('robo.yml', $content)) {
          $content[] = 'robo.yml';
          file_put_contents($ignore, implode(PHP_EOL, $content));
          $this->yell('Added robo.yml to project .gitignore');
        }
        else {
          $this->yell('.gitignore already ignores robo.yml ...');
        }
      }

      // Create a default empty RoboFile.php to properly initialize robo.
      $roboFile = $this->projectDir() . DIRECTORY_SEPARATOR . 'RoboFile.php';
      if (!file_exists($roboFile)) {
        $app = Robo::application();
        $init = $app->get('init');
        $init->run(Robo::input(), new NullOutput());
        $this->yell('Created default RoboFile.php. You can add later project specific commands here.');
      }
    }
  }
}

