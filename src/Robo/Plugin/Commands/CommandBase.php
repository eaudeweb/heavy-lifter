<?php
/**
 * @file CommandBase.php
 */

namespace EauDeWeb\Robo\Plugin\Commands;

use EauDeWeb\Robo\InvalidConfigurationException;
use Robo\Robo;

/**
 * Class CommandBase for other commands.
 *
 * @package EauDeWeb\Robo\Plugin\Commands
 */
class CommandBase extends \Robo\Tasks {

  const FILE_FORMAT_VERSION = '2.0';

  /**
   * Check configuration file consistency.
   *
   * @throws \EauDeWeb\Robo\InvalidConfigurationException
   */
  protected function validateConfig() {
    $version = $this->config('project.version');
    if (empty($version)) {
      throw new InvalidConfigurationException(
        'Make sure robo.yml exists and configuration updated to format version: ' . static::FILE_FORMAT_VERSION
      );
    }
    if (!version_compare($version, static::FILE_FORMAT_VERSION, '>=')) {
      throw new InvalidConfigurationException(
        'Update your obsolete robo.yml configuration with changes from example.robo.yml to file format: ' . static::FILE_FORMAT_VERSION
      );
    }
    return TRUE;
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
    $full = 'project.sites.' . $site . '.' . $key;
    if ($value = $config->get($full)) {
      return $value;
    } else {
      $this->yell('Missing configuration key: ' . $full);
    }
    return null;
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
   * @return string
   * @throws \EauDeWeb\Robo\InvalidConfigurationException
   */
  protected function drushExecutable() {
    /** @TODO Windows / Windows+BASH / WinBash / Cygwind not tested */
    if (realpath(getcwd() . '/vendor/bin/drush')) {
      return realpath(getcwd() . '/vendor/bin/drush');
    }
    else if (realpath(getcwd() . '/vendor/drush/drush/drush')) {
      realpath(getcwd() . '/vendor/drush/drush/drush');
    }
    throw new InvalidConfigurationException('Cannot find Drush executable inside this project');
  }
}
