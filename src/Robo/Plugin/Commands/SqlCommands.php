<?php

namespace EauDeWeb\Robo\Plugin\Commands;



use EauDeWeb\Robo\InvalidConfigurationException;

class SqlCommands extends CommandBase {

  use \Boedah\Robo\Task\Drush\loadTasks;

  /**
   * @inheritdoc
   */
  protected function validateConfig() {
    parent::validateConfig();
    $url =  $this->configSite('sync.sql.url');
    if (!empty($url) && strpos($url, 'https://') !== 0) {
      throw new InvalidConfigurationException(
        'SQL sync URL is not HTTPS, cannot send credentials over unencrypted connection to: ' . $url
      );
    }
  }

  /**
   * Only download the database dump from the remote storage, without importing it.
   *
   * @command sql:download
   *
   * @return null|\Robo\Result
   * @throws \EauDeWeb\Robo\InvalidConfigurationException
   *
   */
  public function sqlDownload() {
    $this->validateConfig();
    $url =  $this->configSite('sync.sql.url');
    $username = $this->configSite('sync.username');
    $password = $this->configSite('sync.password');
    $sql_dump = $this->tmpDir() . '/database.sql';
    $sql_dump_gz = $sql_dump . '.gz';

    $build = $this->collectionBuilder()->addTask(
      $this->taskFilesystemStack()->remove($sql_dump)->remove($sql_dump_gz)
    );
    $curl = $this->taskExec('curl');
    $curl->option('-fL')->option('--location-trusted')->option('--create-dirs');
    $curl->option('-o', $sql_dump_gz);
    $curl->option('-u', $username . ':' . $password);
    $curl->arg($url);
    $build->addTask($curl);
    return $build->run();
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
   * @throws \EauDeWeb\Robo\InvalidConfigurationException
   */
  public function sqlSync($options = ['anonymize' => FALSE]) {
    $this->validateConfig();
    if (($download = $this->sqlDownload()) && $download->wasSuccessful()) {
      // @TODO How can we retrieve the downloaded file path from sqlDownload()?
      $sql_dump = $this->tmpDir() . '/database.sql';
      $sql_dump_gz = $sql_dump . '.gz';

      $build = $this->collectionBuilder();
      $build->addTask(
        $this->taskExec('gzip')
          ->option('-d')
          ->option('--keep')
          ->arg($sql_dump_gz)
      );

      $drush = $this->drushExecutable();
      $drush = $this->taskDrushStack($drush)
        ->drush('sql:drop')
        ->drush(['sql:query','--file', $sql_dump]);

      if ($options['anonymize']) {
        $drush->drush("project:anonymize -y");
      }
      $build->addTask($drush);
      return $build->run();
    }
    return $download;
  }
}
