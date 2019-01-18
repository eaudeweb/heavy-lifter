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
      $this->taskExec($drush)->arg('config:import')->arg('dev')->arg('--partial')->rawArg('-y')->run();
    } else {
      $this->yell("Skipping import of 'dev' profile because it's missing");
    }
  }

  /**
   * Update the local instance: import configuration, update database, rebuild
   * cache.
   *
   * @command site:update
   *
   * @return null|\Robo\Result
   * @throws \EauDeWeb\Robo\InvalidConfigurationException
   * @throws \Robo\Exception\TaskException
   */
  public function siteUpdate() {
    $this->validateConfig();
    $drush = $this->drushExecutable();
    $this->taskExec($drush)->arg("state-set")->arg("system.maintenance_mode")->arg("TRUE")->run();
    // Allow updatedb to fail once and execute it again after config:import.
    $this->taskExec("{$drush} updatedb -y")->run();
    $execStack = $this->taskExecStack()->stopOnFail(TRUE);
    $execStack->exec("{$drush} cr");
    if ($this->configSite('develop.config_split') === TRUE) {
      $execStack->exec("{$drush} csim -y");
    }
    else {
      $execStack->exec("{$drush} cim sync -y");
    }
    $execStack->exec("{$drush} updatedb -y");
    $execStack->exec("{$drush} entup -y");
    $execStack->exec("{$drush} cr");
    $this->taskExec($drush)->arg("state-set")->arg("system.maintenance_mode")->arg("FALSE")->run();
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
