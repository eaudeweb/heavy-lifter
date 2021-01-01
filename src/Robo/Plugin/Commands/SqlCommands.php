<?php

namespace EauDeWeb\Robo\Plugin\Commands;


use EauDeWeb\Robo\Task\Curl\loadTasks;
use machbarmacher\GdprDump\MysqldumpGdpr;
use Robo\Exception\TaskException;
use Symfony\Component\Yaml\Yaml;


class SqlCommands extends CommandBase {

  use loadTasks;

  /**
   * Download the database dump from the remote storage, without importing it.
   *
   * @command sql:download
   *
   * @param string $destination
   *   Destination path to save the SQL database dump.
   * @param array $options
   *  Command options.
   * @return null|\Robo\Result
   * @throws \Robo\Exception\TaskException
   *
   */
  public function sqlDownload($destination, $options = ['site' => 'default']) {
    $site = $options['site'];
    $url =  $this->configSite('sql.sync.source', $site);
    $username = $this->configSite('sync.username', $site);
    $password = $this->configSite('sync.password', $site);
    $this->validateHttpsUrl($url);
    return $this->taskCurl($url)
      ->followRedirects()
      ->failOnHttpError()
      ->locationTrusted()
      ->output($destination)
      ->basicAuth($username, $password)
      ->option('--create-dirs')
      ->run();
  }

  /**
   * Drop the current database and import a new database dump from the remote storage.
   *
   * @command sql:sync
   *
   * @param array $options
   *  Command options.
   * @option $anonymize Anonymize data after importing the SQL dump
   *
   * @return null|\Robo\Result
   * @throws \Robo\Exception\TaskException
   */
  public function sqlSync($options = ['anonymize' => FALSE, 'site' => 'default']) {
    $this->allowOnlyOnLinux();
    $site = $options['site'];

    $url = $this->configSite('sql.sync.source', $site);
    $this->validateHttpsUrl($url);
    $commands = [];

    $dir = $this->taskTmpDir('heavy-lifter')->run();
    $dest = $dir->getData()['path'] . '/database.sql';
    $dest_gz = $dest . '.gz';
    $download = $this->sqlDownload($dest_gz, ['site' => $site]);
    if ($download->wasSuccessful()) {
      $drush = $this->drushExecutable($site);
      $execStack = $this->taskExecStack()->stopOnFail(TRUE);
      $execStack->exec("gzip -d $dest_gz");

      if ($this->isDrush9()) {
        $commands[] = 'sql:drop -y';
        $commands[] = 'sql:query --file ' . $dest;
      }
      else {
        //Drupal 7
        $drupalRoot = $this->drupalRoot();
        $execStack->dir($drupalRoot);
        $commands[] = 'sql-drop -y';
        $commands[] = 'sql-query --file=' . $dest;
      }

      // Add the anonymize command if required
      if ($options['anonymize']) {
        $commands[] = 'project:anonymize -y';
      }

      $excludedCommandsArray = $this->configSite('sql.sync.excluded_commands', $site);
      $extraCommandsArray = $this->configSite('sql.sync.extra_commands', $site);

      $execStack = $this->updateDrushCommandStack($execStack, $commands, $excludedCommandsArray, $extraCommandsArray, $site);

      $execStack->run();
    }
    return $download;
  }

  /**
   * Create archive with database dump directory to the given path
   *
   * @command sql:dump
   * @option gzip Create a gzipped archive dump. Default TRUE.
   * @option anonymize Anonymize sensitive data according to your robo.yml configuration. Default FALSE.
   *
   * @param string $output Absolute path to the resulting archive
   * @param array $options Command line options
   *
   * @return null|\Robo\Result
   * @throws \Robo\Exception\TaskException when output path is not absolute
   */
  public function sqlDump($output = NULL, $options = ['gzip' => true, 'anonymize' => false, 'site' => 'default']) {
    $site = $options['site'];
    if (empty($output)) {
      $output = $this->configSite('sql.dump.location', $site);
      if (empty($output)) {
        throw new TaskException(get_class($this), 'Dump location was not set. Please add the path parameter or add default_dump_location in your robo.yml.');
      }
    }
    $output = preg_replace('/.gz$/', '', $output);
    $separator = $this->isLinuxServer() ? '/' : '\\';
    if ($output[0] != $separator) {
      $output = getcwd() . $separator . $output;
    }

    if (!$this->isLinuxServer()) {
      $output = str_replace("\\", "\\/", $output);
    }

    $drush = $this->drushExecutable($site);

    if ($options['anonymize']) {
      $anonSchema = $this->projectDir() . '/anonymize.schema.yml';
      if (!file_exists($anonSchema)) {
        throw new TaskException(get_class($this), 'The anonymize.schema.yml file is missing.');
      }

      if (!class_exists(MysqldumpGdpr::class)) {
        throw new TaskException(get_class($this), 'You cannot anonymize data without package "eaudeweb/gdpr-dump" being installed! Please run "composer require eaudeweb/gdpr-dump:1.0.6" !');
      }
      $exportPath = 'export PATH=' . $this->projectDir() . '/vendor/bin:$PATH; ';
      $drush = $exportPath . $drush;
    }

    $execStack = $this->taskExecStack()->stopOnFail(TRUE);
    if ($this->isDrush9()) {
      $task = $this->taskExec($drush)
        ->rawArg('sql:dump')
        ->rawArg('--structure-tables-list=cache,cache_*,watchdog,sessions,history')
        ->option('result-file', $output);

    } else { // Drupal 7
      $drupalRoot = $this->drupalRoot();
      $task = $this->taskExec($drush)
        ->rawArg('sql-dump')
        ->rawArg('--structure-tables-list=cache,cache_*,watchdog,sessions,history')
        ->rawArg("--result-file=$output")
        ->dir($drupalRoot);
    }

    if ($options['gzip']) {
      $task->arg('--gzip');
    }

    if ($options['anonymize']) {
      $anonArgs = json_encode(Yaml::parseFile($anonSchema));
      $task->rawArg(" --extra-dump=\'--gdpr-replacements='{$anonArgs}'\'");
    }

    return $task->run();
  }
}
