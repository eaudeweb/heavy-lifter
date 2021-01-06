<?php

namespace EauDeWeb\Robo\Plugin\Commands;

use Drush\Drush;
use PhpOffice\PhpSpreadsheet\Calculation\Database;
use Robo\Exception\TaskException;
use Robo\Robo;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

class FilesCommands extends CommandBase {

  /**
   * @inheritdoc
   */
  protected function validateConfig($site = 'default') {
    parent::validateConfig();
    $url = $this->configSite('files.sync.source', $site);
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
   * @param array $options
   *  Command options.
   * @return null|\Robo\Result
   * @throws \Exception when cannot find the Drupal installation folder.
   */
  public function filesSync($options = ['site' => 'default']) {
    $this->allowOnlyOnLinux();
    $site = $options['site'];

    $this->validateConfig($site);
    $url =  $this->configSite('files.sync.source', $site);
    $username = $this->configSite('sync.username', $site);
    $password = $this->configSite('sync.password', $site);
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
   * @param array $options
   *  Command options.
   * @return null|\Robo\Result
   * @throws \Robo\Exception\TaskException when output path is not absolute
   */
  public function filesDump($output = '', $options = ['site' => 'default']) {
    $this->allowOnlyOnLinux();
    $site = $options['site'];

    if (empty($output)) {
      $output = $this->configSite('files.dump.location', $site);
    }

    if ($output[0] != '/') {
      $output = getcwd() . '/' . $output;
    }

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

  /**
   * Perform integrity on managed files: report missing files or unused files.
   * Use --limit=10 to analyze only ten files from file_managed table.
   *
   * @command files:integrity-check

   * @param array $options
   *  Command options.
   * @return null|\Robo\Result
   * @throws \Robo\Exception\TaskException when output path is not absolute
   */
  public function checkIntegrity($options = ['limit' => NULL, 'site' => 'default']) {
    if (!$this->isDrush9()) {
      throw new \Exception('Need drupal 8');
      return FALSE;
    }
    $this->allowOnlyOnLinux();

    $site = $options['site'];
    $limit = $options['limit'];
    list($missing, $orphans, $problem) = $this->getIntegrityFiles($limit, $site);
    $final_records = [];
    foreach ($problem as $row) {
      $final_records[$row['fid']] = $row;
    }
    foreach (array_merge($missing, $orphans) as $row) {
      $final_records[$row['fid']] = [
        'fid' => $row['fid'],
        'uri' => $row['uri'],
        'problem' => $row['problem'],
        'count' => $row['count'],
        'usage' => !empty($row['usage']) ? new TableCell($row['usage'], ['rowspan' => 2]) : '',
      ];
    }

    if (!empty($final_records)) {
      //Create the output table
      echo "M = File recorded in file_managed but missing from disk\nO = File recorded in file_managed by no record in file_usage\n";
      $output = new BufferedOutput();
      $tbl = new Table($output);
      $tbl->setHeaders(["FID", "Path", "Problem", "Count", "Usage"]);
      $tbl->setColumnWidths([7, 10, 4, 2, 10]);
      $tbl->setRows($final_records);
      $tbl->render();
      $tbl = $output->fetch();
      $tbl_lines = substr_count($tbl, "\n");
      $this->output->write($tbl);
    } else {
      $this->output->write("No issue detected\n");
    }
  }
}
