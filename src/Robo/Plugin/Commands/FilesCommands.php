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
     * Perform integrity on managed files: report missing files or unused
     * files.
     * Use --limit=10 to analyze only ten files from file_managed table.
     *
     * @command files:integrity-check
     *
     * @param array $options
     *  Command options.
     *
     * @throws \Robo\Exception\TaskException
     *   When output path is not absolute
     * @throws \Exception
     *   Under various circumstances
     */
  public function checkManagedFilesIntegrity($options = ['limit' => NULL, 'site' => 'default']) {
    if (!$this->isDrush9()) {
      throw new \Exception('Need drupal 8');
    }
    $this->allowOnlyOnLinux();

    $site = $options['site'];
    $limit = $options['limit'];
    [$missing, $orphans, $problem] = $this->checkManagedFilesIntegrityImpl($limit, $site);
    $rows = [];
    foreach ($problem as $row) {
      $fid = $row['fid'];
      $rows[$fid] = $row;
    }
    foreach (array_merge($missing, $orphans) as $row) {
      $fid = $row['fid'];
      $rows[$fid] = [
        'fid' => $row['fid'],
        'uri' => $row['uri'],
        'problem' => $row['problem'],
        'count' => $row['count'],
        'usage' => !empty($row['usage']) ? new TableCell($row['usage'], ['rowspan' => 2]) : '',
      ];
    }

    if (!empty($rows)) {
      echo "M = File recorded in file_managed but missing from disk\n";
      echo "O = File recorded in file_managed by no record in file_usage\n";
      // Create the output table
      $output = new BufferedOutput();
      $tbl = new Table($output);
      $tbl->setHeaders(["FID", "Path", "Problem", "Count", "Usage"]);
      $tbl->setColumnWidths([7, 10, 4, 2, 10]);
      $tbl->setRows($rows);
      $tbl->render();
      $tbl = $output->fetch();
      $this->output->write($tbl);
    } else {
      $this->output->write("No issues detected\n");
    }
  }


  /**
   * Get integrity of files recorded in file_managed but they're missing from
   * disk or recorded in file_managed with no record in file_usage.
   *
   * @param string $limit
   *  Use option $limit to interrogate a limited number of records from tables.
   * @param string $site
   *
   * @return array
   * @throws \Robo\Exception\TaskException
   */

  protected function checkManagedFilesIntegrityImpl($limit = '', $site = 'default') {
    $drupalRoot = $this->drupalRoot();
    try {
      $container = self::bootDrupal($site, 'prod');
    }
    catch (\Exception $e) {
      $this->yell('Cannot boot Drupal environment, aborting!', 40, 'red');
      exit(1);
    }

    /** @var \Drupal\Core\Database\Database $drupalDatabase */
    $drupalDatabase = $container->get('database');
    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    $file_system = $container->get('file_system');
    /** @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $stream_wrapper_manager */
    $stream_wrapper_manager = $container->get('stream_wrapper_manager');

    $this->yell(sprintf("Checking files in %s.file_managed", $drupalDatabase->getConnectionOptions()['database']));

    //Files recorded in files_managed
    $query = $drupalDatabase->select('file_managed', 'f')->fields('f', ['fid', 'uri'])->condition('status', 1);
    if (!empty($limit)) {
      $query = $query->range(0, $limit);
    }
    $file_managed = $query->execute()->fetchAll();

    // Files recorded in file_managed & file_usage
    $query = $drupalDatabase->select('file_managed', 'm');
    $query->innerJoin('file_usage', 'u', 'u.fid = m.fid');
    $query->fields('u', ['fid', 'type', 'id', 'count']);
    $files_in_use = $query->execute()->fetchAll();

    // Files recorded in files_managed but are not recorded in file_usage
    $query = $drupalDatabase->select('file_managed', 'm');
    $query->leftJoin('file_usage', 'u', 'm.fid=u.fid');
    $query->fields('m', ['fid', 'uri']);
    $query->isNull('u.count');
    if (!empty($limit)) {
      $query = $query->range(0, $limit);
    }
    $files_not_in_use = $query->execute()->fetchAll();

    $total = count($file_managed);

    $missing = $problem = $orphans = [];
    // List orphans
    foreach ($files_not_in_use as $row) {
      $orphans[$row->fid] = [
        'fid' => $row->fid,
        'uri' => $row->uri,
        'problem' => 'O',
        'count' => 'n/a',
      ];
    }
    echo sprintf("\nSummary:\n");
    echo sprintf("Total files in file_managed: %d\n", $total);
    if (count($files_not_in_use)) {
      echo sprintf("Files not in use: %d\n", count($files_not_in_use));
    }

    // Group records from file_usage table by fid
    $arr = [];
    foreach ($files_in_use as $key => $item) {
      $arr[$item->fid][$key] = $item;
    }
    $files_in_use = $arr;
    ksort($files_in_use, SORT_NUMERIC);

    $i = count($files_not_in_use);
    $orphans_fids = array_column($files_not_in_use, 'fid');
    foreach ($file_managed as $row) {
      // Get path
      $scheme = $file_system->uriScheme($row->uri);
      /** @var \Drupal\Core\StreamWrapper\PublicStream $wrapper */
      $wrapper = $stream_wrapper_manager->getViaScheme($scheme);
      if (\Drupal::VERSION > '8.8.0') {
        $path = $drupalRoot . '/' . $wrapper->getDirectoryPath() . '/' . $stream_wrapper_manager::getTarget($row->uri);
      } else {
        $path = $drupalRoot . '/' . $wrapper->getDirectoryPath() . '/' . file_uri_target($row->uri);
      }
      if (file_exists($path)) {
        continue;
      }
      // If file missing and is orphan, then item will be missing & orphan
      if (in_array($row->fid, $orphans_fids)) {
        $problem[$row->fid] = [
          'fid' => $row->fid,
          'uri' => $row->uri,
          'problem' => 'M/O',
          'count' => 'n/a',
          'usage' => '',
        ];
        //and unset from orphans list
        unset($orphans[$row->fid]);
        continue;
      }
      $count = 0;
      // If file exists and in use, get the usage
      if (!empty($files_in_use[$row->fid])) {
        [$references, $count] = $this->getFileUsage($files_in_use[$row->fid]);
      }
      $missing[$row->fid] = [
        'fid' => $row->fid,
        'uri' => $row->uri,
        'problem' => 'M',
        'count' => $count,
        'usage' => implode('' , $references),
      ];

      if ($total && ++$i % 5000 == 0) {
        echo sprintf("Processed: %04d/%d\n", $i, $total);
      }
    }
    return [$missing, $orphans, $problem];
  }


  /**
   * Prepare an array[type|string ids] with entities where file is usage.
   *
   * @param $usage
   *
   * @return array
   *   Return a string with information about file and the count
   */
  protected function getFileUsage($usage) {
    $references = [];
    $count = 0;
    //Parse each value and group by type because a file can be used for more
    //types and also more entities can use the same type
    foreach ($usage as $item) {
      $count += $item->count;
      //If type is paragraph, search by the parent type (e.g. node). If first
      //parent type is also paragraph, search until find the visible parent (e.g
      //node, taxonomy_term, media.
      if ($item->type == 'paragraph') {
        [$parent_type, $parent_id] = $this->getParagraphParentType($item->id);
        if (!empty($parent_id)) {
          $references['paragraph'][] = sprintf("%s: %s", $parent_type, $parent_id);
        }
        continue;
      }
      $references[$item->type][] = sprintf("%s", $item->id);
    }
    foreach ($references as $type => $items) {
      $ids = implode(', ', array_slice($items, 0, 3));
      $more = count($items) - 3 ?: 0;
      $references[$type] = sprintf("%s(s): %s %s\n", $type, $ids, ($more > 0) ? "and {$more} more" : '');
    }
    $references = array_values($references);

    return [$references, $count];
  }


  protected function getParagraphParentType($id) {
    /** @var \Drupal\paragraphs\Entity\Paragraph $paragraph */
    $paragraph = \Drupal\paragraphs\Entity\Paragraph::load($id);
    if (!empty($paragraph)) {
      $parent_type = $paragraph->get('parent_type')->value;
      $parent_id = $paragraph->get('parent_id')->value;
      if ($parent_type == 'paragraph') {
        return $this->getParagraphParentType($parent_id);
      }
      return [$parent_type, $parent_id];
    } else {
      $this->yell(sprintf('Found orphan paragraph with id:%s', $id), 40, 'yellow');
      return null;
    }
  }
}
