<?php

namespace EauDeWeb\Robo\Plugin\Commands;

use Robo\Exception\TaskException;
use Symfony\Component\Process\Process;

class GitCommands extends CommandBase {

  /**
   * Enable (or disable) the git phpcs pre-commit hook.
   *
   * @command git:phpcs-pre-commit
   *
   * @param array $options
   *  Command options.
   * @option disable Disable the hook.
   * @option accept-warnings Accept phpcs warnings.
   * @usage --accept-warnings
   *   Enable the phpcs git pre-commit hook and accept phpcs warnings.
   * @usage --disable
   *   Disable the phpcs git pre-commit hook.
   *
   * @throws \Robo\Exception\TaskException
   */
  public function gitPhpcsPrecommit($options = ['disable' => FALSE, 'accept-warnings' => FALSE]) {
    $execStack = $this->taskExecStack()->stopOnFail(TRUE);
    $vendorDir = $this->getVendorDir();
    $projectDir = $this->projectDir();
    $drupalRoot = $this->drupalRoot();

    if (!file_exists("$projectDir/.git")) {
      throw new TaskException($this, 'This command can only be run inside git repositories. Please run `git init` first.');
    }

    if (!$this->isPackageAvailable('squizlabs/php_codesniffer')) {
      $execStack->exec("composer require squizlabs/php_codesniffer");
    }

    if (!$this->isPackageAvailable('drupal/coder')) {
      $execStack->exec("composer require drupal/coder");
      $execStack->exec("rm -rf $vendorDir/drupal/coder/.git");
    }

    $p = new Process(["$vendorDir/bin/phpcs", "--config-show"]);
    $p->run();
    if (strpos($p->getOutput(), 'drupal/coder/coder_sniffer') === FALSE) {
      $execStack->exec("$vendorDir/bin/phpcs --config-set installed_paths ../../drupal/coder/coder_sniffer");
    }

    if (!file_exists("$projectDir/phpcs.xml")) {
      $execStack->exec("cp $drupalRoot/core/phpcs.xml.dist $projectDir/phpcs.xml");
    }

    if ($options['disable']) {
      if (!file_exists("$projectDir/.git/hooks/pre-commit")) {
        $this->say('The phpcs pre-commit hook is already disabled');
        return;
      }
      else {
        // The first time we find a preconfigured pre-commit hook, create a backup of it
        // in case the user already had a script that he doesn't want to lose.
        if (!file_exists("$projectDir/.git/hooks/pre-commit.backup")) {
          $execStack->exec("cp $projectDir/.git/hooks/pre-commit $projectDir/.git/hooks/pre-commit.backup");
        }
        $execStack->exec("rm $projectDir/.git/hooks/pre-commit");
      }
    }
    else {
      if (file_exists("$projectDir/.git/hooks/pre-commit")) {
        // The first time we find a preconfigured pre-commit hook, create a backup of it
        // in case the user already had a script that he doesn't want to lose.
        if (!file_exists("$projectDir/.git/hooks/pre-commit.backup")) {
          $execStack->exec("cp $projectDir/.git/hooks/pre-commit $projectDir/.git/hooks/pre-commit.backup");
        }
        $execStack->exec("rm $projectDir/.git/hooks/pre-commit");
      }
      $preCommitScript = "$vendorDir/eaudeweb/heavy-lifter/etc/scripts/git/pre-commit";
      if ($options['accept-warnings']) {
        $preCommitScript = "$vendorDir/eaudeweb/heavy-lifter/etc/scripts/git/pre-commit-with-warnings";
      }
      $execStack->exec("cp $preCommitScript $projectDir/.git/hooks/pre-commit");
      $execStack->exec("chmod +x $projectDir/.git/hooks/pre-commit");
    }

    $execStack->run();
  }

}
