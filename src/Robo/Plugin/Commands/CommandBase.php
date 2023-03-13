<?php
/**
 * @file CommandBase.php
 */
namespace EauDeWeb\Robo\Plugin\Commands;

use DrupalFinder\DrupalFinder;
use Robo\Collection\CollectionBuilder;
use Robo\Exception\TaskException;
use Robo\Result;
use Robo\Robo;
use Robo\Tasks;
use Symfony\Component\Process\Process;
/**
 * Class CommandBase for other commands.
 *
 * @package EauDeWeb\Robo\Plugin\Commands
 */
class CommandBase extends Tasks {

  const FILE_FORMAT_VERSION = '3.0';

  /**
   * Check configuration file consistency.
   *
   * @throws TaskException
   */
  protected function validateConfig(): bool {
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
   * @throws TaskException
   */
  protected function validateHttpsUrl(string $url) : void {
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
  protected function config(string $key) {
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
  protected function configSite(string $key, $site = 'default') {
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
  protected function tmpDir(): string
  {
    return sys_get_temp_dir();
  }

  /**
   * Get project root directory.
   *
   * @return string
   */
  protected function projectDir(): string
  {
    return getcwd();
  }

  /**
   * Return absolute path to drush executable.
   *
   * @param string $site
   * @return string
   * @throws TaskException
   */
  protected function drushExecutable($site = 'default') : string {
    /** @TODO Windows / Windows+BASH / WinBash / Cygwin not tested */
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
   *     "web/": ["type:drupal-core"],
   *     "web/profiles/{$name}/": ["type:drupal-profile"],
   *     "web/libraries/{$name}/": ["type:drupal-library"],
   *     "web/modules/contrib/{$name}/": ["type:drupal-module"],
   *     "web/themes/{$name}/": ["type:drupal-theme"],
   *   },
   *   ...
   * </pre>
   *
   * @return string
   * @throws TaskException
   */
  protected function drupalRoot() : string {
    $drupalFinder = new DrupalFinder();
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
   * @throws TaskException
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
   * @return bool
   * @throws TaskException
   */
  protected function isDrush9(): bool
  {
    $drushVersion = $this->getDrushVersion();
    return version_compare($drushVersion, '9') >= 0;
  }

  /**
   * @param string $version
   * @return bool
   * @throws TaskException
   */
  protected function isDrushVersionBiggerThan($version) {
    $drushVersion = $this->getDrushVersion();
    return version_compare($drushVersion, $version) >= 0;
  }

  /**
     * @param $module
     * @return bool
     * @throws TaskException
     */
  protected function isModuleEnabled($module) : bool {
    $drush = $this->drushExecutable();
    $p = new Process(["$drush pml --type=module --status=enabled | grep '($module)'"]);
    $p->run();
    return !empty($p->getOutput());
  }

    /**
     * @param $package
     * @return bool
     * @throws TaskException
     */
  protected function isPackageAvailable($package) : bool {
    $vendorDir = $this->getVendorDir();
    return file_exists($vendorDir . '/' . $package);
  }

  /**
   * @return string
   * @throws TaskException
   */
  protected function getVendorDir(): string {
    $drupalFinder = new DrupalFinder();
    if (!$drupalFinder->locateRoot(getcwd())) {
      throw new TaskException($this, "Cannot find vendor dir.");
    }
    return $drupalFinder->getVendorDir();
  }

    /**
     * @param $module
     * @return string
     * @throws TaskException
     */
  protected function getModuleInfo($module): string
  {
    $drush = $this->drushExecutable();
    $p = new Process(["$drush pml --type=module --status=enabled | grep '($module)'"]);
    $p->run();
    return $p->getOutput();
  }

    /**
     * @param CollectionBuilder $execStack
     * @param $phase
     * @throws TaskException
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
     * @param $execStack
     * @param $commands
     * @param array $excludedCommandsArray
     * @param array $extraCommandsArray
     * @param string $site
     * @return mixed
     * @throws TaskException
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

  protected function isLinuxServer() : bool {
    return strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN';
  }

  /**
   * @throws TaskException
   */
  protected function allowOnlyOnLinux() {
    if (!$this->isLinuxServer()) {
      throw new TaskException(static::class, "This command is only supported by Unix environments!");
    }
  }

  /**
   * @param Result $out
   * @param string $message
   * @param bool $allowFail
   * @throws TaskException
   */
  protected function handleFailure(Result $out, string $message, $allowFail = false) {
    if ($out->getExitCode() != 0) {
      if (!$allowFail) {
          throw new TaskException($this, $message);
      }
    }
  }
}
