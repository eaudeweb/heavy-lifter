<?php

namespace EauDeWeb\Robo\Plugin\Commands;


use EauDeWeb\Robo\Task\Curl\loadTasks;
use Robo\Exception\TaskException;


class SqlCommands extends CommandBase {

  use \Boedah\Robo\Task\Drush\loadTasks;
  use \EauDeWeb\Robo\Task\Curl\loadTasks;

  /**
   * Download the database dump from the remote storage, without importing it.
   *
   * @command sql:download
   *
   * @param string $destination
   *   Destination path to save the SQL database dump.
   *
   * @return null|\Robo\Result
   * @throws \Robo\Exception\TaskException
   *
   */
  public function sqlDownload($destination) {
    $url =  $this->configSite('sync.sql.url');
    $username = $this->configSite('sync.username');
    $password = $this->configSite('sync.password');
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
  public function sqlSync($options = ['anonymize' => FALSE]) {
    $url = $this->configSite('sync.sql.url');
    $this->validateHttpsUrl($url);

    $dir = $this->taskTmpDir('heavy-lifter')->run();
    $dest = $dir->getData()['path'] . '/database.sql';
    $dest_gz = $dest . '.gz';
    $download = $this->sqlDownload($dest_gz);
    if ($download->wasSuccessful()) {
      $drush = $this->drushExecutable();
      $execStack = $this->taskExecStack()->stopOnFail(TRUE);
      $execStack->exec("gzip -d $dest_gz");

      if ($this->isDrush9()) {
        $execStack->exec("$drush sql:drop -y");
        $execStack->exec("$drush sql:query --file $dest");
      }
      else {
        //Drupal 7
        $execStack->dir('docroot');
        $execStack->exec("$drush sql-drop -y");
        $execStack->exec("$drush sql-query --file=$dest");
      }

      // Add the anonymize command if required
      if ($options['anonymize']) {
        $execStack->exec("$drush project:anonymize -y");
      }

      $execStack->run();
    }
    return $download;
  }

  /**
   * Create archive with database dump directory to the given path
   *
   * @command sql:dump
   * @option gzip Create a gzipped archive dump. Default TRUE.
   *
   * @param string $output Absolute path to the resulting archive
   * @param array $options Command line options
   *
   * @return null|\Robo\Result
   * @throws \Robo\Exception\TaskException when output path is not absolute
   */
  public function sqlDump($output = NULL, $options = ['gzip' => true]) {
    if (empty($output)) {
      $output = $this->configSite('default_dump_location');
      if (empty($output)) {
        throw new TaskException(get_class($this), 'Dump location was not set. Please add the path parameter or add default_dump_location in your robo.yml.');
      }
    }
    $output = preg_replace('/.gz$/', '', $output);
    if ($output[0] != '/') {
      $output = getcwd() . '/' . $output;
    }
    $drush = $this->drushExecutable();
    $execStack = $this->taskExecStack()->stopOnFail(TRUE);
    if ($this->isDrush9()) {
      $task = $this->taskExec($drush)
        ->rawArg('sql:dump')
        ->rawArg('--structure-tables-list=cache,cache_*,watchdog,sessions,history');

    } else { // Drupal 7
      $task = $this->taskExec($drush)
        ->rawArg('sql-dump')
        ->rawArg('--structure-tables-list=cache,cache_*,watchdog,sessions,history')
        ->dir('docroot');

    }
    $task->option('result-file', $output);

    if ($options['gzip']) {
      $task->arg('--gzip');
    }
    return $task->run();
  }
}
