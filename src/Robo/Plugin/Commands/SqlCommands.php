<?php

namespace EauDeWeb\Robo\Plugin\Commands;


use Robo\Exception\TaskException;


class SqlCommands extends CommandBase {

  use \EauDeWeb\Robo\Task\Curl\loadTasks;
  use \Boedah\Robo\Task\Drush\loadTasks;

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
    $url =  $this->configSite('sync.sql.url');
    $this->validateHttpsUrl($url);

    $dir = $this->taskTmpDir('heavy-lifter')->run();
    $dest = $dir->getData()['path'] . '/database.sql';
    $dest_gz = $dest . '.gz';
    $download = $this->sqlDownload($dest_gz);
    if ($download->wasSuccessful()) {
      $build = $this->collectionBuilder();
      $build->addTask(
        $this->taskExec('gzip')->option('-d')->arg($dest_gz)
      );
      $drush = $this->drushExecutable();
      $drush = $this->taskDrushStack($drush)
        ->drush('sql:drop')
        ->drush(['sql:query','--file', $dest]);

      if ($options['anonymize']) {
        $drush->drush("project:anonymize -y");
      }
      $build->addTask($drush);
      return $build->run();
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
  public function sqlDump($output, $options = ['gzip' => true]) {
    $output = preg_replace('/.gz$/', '', $output);
    if ($output[0] != '/') {
      $output = getcwd() . '/' . $output;
    }
    $drush = $this->drushExecutable();
    $task = $this->taskExec($drush)->rawArg('sql:dump')->rawArg('--structure-tables-list=cache,cache_*,watchdog,sessions,history');
    $task->option('result-file', $output);
    if ($options['gzip']) {
      $task->arg('--gzip');
    }
    return $task->run();
  }
}
