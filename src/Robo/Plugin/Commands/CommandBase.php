<?php
/**
 * @file CommandBase.php
 */
namespace EauDeWeb\Robo\Plugin\Commands;
use Robo\Collection\CollectionBuilder;
use Robo\Exception\TaskException;
use Robo\Robo;
use Symfony\Component\Process\Process;
/**
 * Class CommandBase for other commands.
 *
 * @package EauDeWeb\Robo\Plugin\Commands
 */
class CommandBase extends \Robo\Tasks {

  const FILE_FORMAT_VERSION = '3.0';

  /**
   * Check configuration file consistency.
   *
   * @throws \Robo\Exception\TaskException
   */
  protected function validateConfig() {
    $version = $this->config('version');
    if (empty($version)) {
      throw new TaskException(
        $this,
        'Make sure robo.yml exists and configuration updated to format version: ' . static::FILE_FORMAT_VERSION
      );
    }
    if (!version_compare($version, static::FILE_FORMAT_VERSION, '>=')) {
      throw new TaskException(
        $this,
        'Update your obsolete robo.yml configuration with changes from example.robo.yml to file format: ' . static::FILE_FORMAT_VERSION
      );
    }
    return TRUE;
  }

  /**
   * Validate the URL is https
   * @param string $url
   *
   * @throws \Robo\Exception\TaskException
   */
  protected function validateHttpsUrl($url) {
    if (strpos($url, 'https://') !== 0) {
      throw new TaskException($this, 'URL is not HTTPS: ' . $url);
    }
  }

  /**
   * Get configuration value.
   *
   * @param string $key
   *
   * @return mixed
   */
  protected function config($key) {
    $config = Robo::config();
    return $config->get($key);
  }

  /**
   * Get configuration value.
   *
   * @param string $key
   * @param string $site
   *   Site config key from the config file (e.g. sites.default.sql.username).
   *
   * @return null|mixed
   */
  protected function configSite($key, $site = 'default') {
    $config = Robo::config();
    $full = 'sites.' . $site . '.' . $key;
    $value = $config->get($full);
    if ($value === NULL) {
      $this->yell('Missing configuration key: ' . $full);
    }
    return $value;
  }

  /**
   * Get temporary dir to download temporary files.
   *
   * @return string
   */
  protected function tmpDir() {
    return sys_get_temp_dir();
  }

  /**
   * Get project root directory.
   *
   * @return string
   */
  protected function projectDir() {
    return getcwd();
  }

  /**
   * Return absolute path to drush executable.
   *
   * @param string $site
   * @param string $useSite
   * @return string
   * @throws \Robo\Exception\TaskException
   */
  protected function drushExecutable($site = 'default') {
    /** @TODO Windows / Windows+BASH / WinBash / Cygwind not tested */
    if (realpath(getcwd() . '/vendor/bin/drush') && $this->isLinuxServer()) {
      if ($site != 'default') {
        return realpath(getcwd() . '/vendor/bin/drush') . ' -l ' . $site;
      }
      return realpath(getcwd() . '/vendor/bin/drush');
    }
    else if (realpath(getcwd() . '/vendor/drush/drush/drush')) {
      if ($site != 'default') {
        return realpath(getcwd() . '/vendor/drush/drush/drush') . ' -l ' . $site;
      }
      return realpath(getcwd() . '/vendor/drush/drush/drush');
    }
    throw new TaskException($this, 'Cannot find Drush executable inside this project');
  }

  /**
   * Find Drupal root installation.
   *
   * For this function (and inherently DrupalFinder to work correctly) you need
   * to properly configure project's root composer.json:
   *
   * <pre>
   *    ...
   *   "require": {
   *     ...
   *     "composer/installers": "^1.2",
   *     ...
   *   "extra": {
   *     "installer-paths": {
   *     "docroot/": ["type:drupal-core"],
   *     "docroot/profiles/{$name}/": ["type:drupal-profile"],
   *     "docroot/sites/all/drush/{$name}/": ["type:drupal-drush"],
   *     "docroot/sites/all/libraries/{$name}/": ["type:drupal-library"],
   *     "docroot/sites/all/modules/contrib/{$name}/": ["type:drupal-module"],
   *     "docroot/sites/all/themes/{$name}/": ["type:drupal-theme"],
   *   },
   *   ...
   * </pre>
   *
   * @return string
   * @throws \Robo\Exception\TaskException
   */
  protected function drupalRoot() {
    $drupalFinder = new \DrupalFinder\DrupalFinder();
    if ($drupalFinder->locateRoot(getcwd())) {
      return $drupalFinder->getDrupalRoot();
    }
    else {
      throw new TaskException($this, "Cannot find Drupal root installation folder");
    }
  }

  /**
   * Detect drush version.
   *
   * @param string $site
   * @throws \Robo\Exception\TaskException
   */
  protected function getDrushVersion() {
    $drush = $this->drushExecutable();
    $p = new Process([$drush, 'version', '--format=json']);
    $p->run();
    if ($output = $p->getOutput()) {
      // Try Drush 9
      if ($version = json_decode($output, TRUE)) {
        if (isset($version['drush-version'])) {
          return $version['drush-version'];
        }
      }
      else {
        // Try Drush 8
        if (preg_match("/\d+\.\d+.\d+/", $output)) {
          return $output;
        }
      }
    }
    return FALSE;
  }

  /**
   * @param string $site
   * @return bool
   * @throws \Robo\Exception\TaskException
   */
  protected function isDrush9() {
    $drushVersion = $this->getDrushVersion();
    return version_compare($drushVersion, '9') >= 0;
  }

  /**
   * @param $module
   * @return bool
   */
  protected function isModuleEnabled($module) {
    $drush = $this->drushExecutable();
    $p = new Process("$drush pml --type=module --status=enabled | grep '($module)'");
    $p->run();
    return !empty($p->getOutput());
  }

  /**
   * @param $package
   * @return bool
   */
  protected function isPackageAvailable($package) {
    $vendorDir = $this->getVendorDir();
    return file_exists($vendorDir . '/' . $package);
  }

  /**
   * @return string
   * @throws \Robo\Exception\TaskException
   */
  protected function getVendorDir() {
    $drupalFinder = new \DrupalFinder\DrupalFinder();
    if (!$drupalFinder->locateRoot(getcwd())) {
      throw new TaskException($this, "Cannot find vendor dir.");
    }
    return $drupalFinder->getVendorDir();
  }

  /**
   * @param $module
   * @return string
   */
  protected function getModuleInfo($module) {
    $drush = $this->drushExecutable();
    $p = new Process("$drush pml --type=module --status=enabled | grep '($module)'");
    $p->run();
    return $p->getOutput();
  }

  /**
   * @param CollectionBuilder $execStack
   * @param $phase
   */
  protected function addDrushScriptsToExecStack(CollectionBuilder $execStack, $phase) {
    $drush = $this->drushExecutable();
    $drupal = $this->isDrush9() ? 'drupal8' : 'drupal7';
    $script_paths = [
      realpath(__DIR__ . "/../../../../etc/scripts/{$drupal}/{$phase}"),
      realpath(getcwd() . "/etc/scripts/{$phase}"),
    ];
    foreach ($script_paths as $path) {
      if (!file_exists($path)) {
        continue;
      }
      $scripts = scandir($path);
      foreach ($scripts as $idx => $script) {
        $extension = pathinfo($script, PATHINFO_EXTENSION);
        if ($extension != 'php') {
          continue;
        }
        $execStack->exec("$drush scr $path/$script");
      }
    }
  }

  /**
   * Update the drush execution stack according to robo.yml specifications.
   */
  protected function updateDrushCommandStack($execStack, $commands, $excludedCommandsArray = [], $extraCommandsArray = [], $site = 'default') {
    $drush = $this->drushExecutable($site);
    if (!empty($excludedCommandsArray)) {
      $excludedCommands = implode("|", $excludedCommandsArray);
      foreach ($commands as $command) {
        if (preg_match('/\b(' . $excludedCommands . ')\b/', $command)) {
          $index = array_search($command, $commands);
          if($index !== false){
            unset($commands[$index]);
          }
        }
      }
    }
    if (empty($extraCommandsArray)) {
      $extraCommandsArray = [];
    }
    $commands = array_merge($commands, $extraCommandsArray);
    foreach ($commands as $command) {
      $execStack->exec("{$drush} " . $command);
    }
    return $execStack;
  }

  protected function isLinuxServer() {
    return strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN';
  }

  /**
   * @throws \Robo\Exception\TaskException
   */
  protected function allowOnlyOnLinux() {
    if (!$this->isLinuxServer()) {
      throw new TaskException(static::class, "This command is only supported by Unix environments!");
    }
  }

  /**
   * @param \Robo\Result $out
   * @param string $message
   * @param bool $allowFail
   * @throws \Robo\Exception\TaskException
   */
  protected function handleFailure($out, $message, $allowFail = false) {
    if ($out->getExitCode() != 0) {
      if (!$allowFail) {
          throw new TaskException($this, $message);
      }
    }
  }

  /**
   * Boots up a Drupal environment.
   *
   * @param $uri
   * @param string $environment
   *
   * @return mixed
   * @throws \Exception
   */
  public static function bootDrupal($uri, $environment = 'prod') {
    $uri = rtrim($uri, '/') . '/';
    if (strpos($uri, 'http://') === FALSE && strpos($uri, 'https://') === FALSE) {
      $uri = 'http://' . $uri;
    }
    $parsed_url = parse_url($uri);

    $server = [
      'SCRIPT_FILENAME' => getcwd() . '/index.php',
      'SCRIPT_NAME' => isset($parsed_url['path']) ? $parsed_url['path'] . 'index.php' : '/index.php',
    ];
    $class_loader = require 'vendor/autoload.php';
    $request = \Symfony\Component\HttpFoundation\Request::create($uri, 'GET', [], [], [], $server);
    $kernel = \Drupal\Core\DrupalKernel::createFromRequest($request, $class_loader, $environment);
    $kernel->boot();
    $kernel->prepareLegacyRequest($request);
    return $kernel->getContainer();
  }
}
