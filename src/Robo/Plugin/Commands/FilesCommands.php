<?php

namespace EauDeWeb\Robo\Plugin\Commands;

use Robo\Exception\TaskException;

class FilesCommands extends CommandBase {

  /**
   * @inheritdoc
   */
  protected function validateConfig() {
    parent::validateConfig();
    $url =  $this->configSite('files.sync.source');
    if (!empty($url) && strpos($url, 'https://') !== 0) {
      throw new TaskException(
        $this,
        'Files sync URL is not HTTPS, cannot send credentials over unencrypted connection to: ' . $url
      );
    }
  }

  /**
   * Sync public files from staging server.
   *
   * @command files:sync
   *
   * @return null|\Robo\Result
   * @throws \Exception when cannot find the Drupal installation folder.
   */
  public function filesSync() {
    $this->allowOnlyOnLinux();
    $site = 'default';

    $this->validateConfig();
    $url =  $this->configSite('files.sync.source');
    $username = $this->configSite('sync.username');
    $password = $this->configSite('sync.password');
    $files_tar_gz = 'files.tar.gz';

    $root = $this->drupalRoot();
    $files_dir = $root . '/sites/' . $site . '/files';
    if (!is_writable($files_dir)) {
      throw new TaskException($this, "{$files_dir} does not exist or it is not writable");
    }

    $download = $this->tmpDir() . '/' . $files_tar_gz;
    $curl = $this->taskExec('curl')->dir($files_dir);
    $curl->option('-fL')->option('--location-trusted')->option('--create-dirs');
    $curl->option('-o', $download);
    $curl->option('-u', $username . ':' . $password);
    $curl->arg($url);

    $build = $this->collectionBuilder();
    $build->addTask($curl);
    $build->addTask($this->taskExec('rm')->arg('-rf')->rawArg($files_dir . '/*'));
    $build->addTask($this->taskExec('rm')->arg('-rf')->rawArg($files_dir . '/.[!.]*'));
    $build->addTask($this->taskExec('cp')->arg($download)->arg($files_dir));
    $build->addTask($this->taskExec('tar')->arg('zxf')->arg($files_tar_gz)->arg('-p')->rawArg('--strip-components=1')->dir($files_dir));
    $build->addTask($this->taskExec('rm')->arg('-rf')->arg($files_tar_gz)->dir($files_dir));
    $result = $build->run();
    $this->yell('Do not forget to check permissions on the files/*. Use "chown" to fix them.');
    return $result;
  }

  /**
   * Create archive with files directory to the given path.
   *
   * @command files:archive
   *
   * @return null|\Robo\Result
   * @throws \Robo\Exception\TaskException when output path is not absolute
   */
  public function filesDump($output = '') {
    $this->allowOnlyOnLinux();

    if (empty($output)) {
      $output = $this->configSite('files.dump.location');
    }

    if ($output[0] != '/') {
      $output = getcwd() . '/' . $output;
    }

    $site = 'default';
    $root = $this->drupalRoot();
    $files_dir = $root . '/sites/' . $site . '/files';
    $build = $this->collectionBuilder();
    if (is_readable($output)) {
      $build->addTask($this->taskExec('rm')->arg('-f')->rawArg($output));
    }
    $build->addTask(
      $this->taskExec('tar')
        ->arg('cfz')
        ->arg($output)
        ->rawArg('--exclude=css')
        ->rawArg('--exclude=js')
        ->rawArg('--exclude=php')
        ->rawArg('--exclude=styles')
        ->rawArg('--exclude=languages')
        ->rawArg('--exclude=xmlsitemap')
        ->rawArg('.')
        ->dir($files_dir)
    );
    return $build->run();
  }
}
