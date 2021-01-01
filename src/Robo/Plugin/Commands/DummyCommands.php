<?php

namespace EauDeWeb\Robo\Plugin\Commands;


use Robo\Exception\TaskException;

class DummyCommands extends CommandBase {

  /**
   * Do ... well ... nothing.
   *
   * @command do:nothing
   *
   * @throws TaskException
   */
  public function doNothing() {
    $this->validateConfig();
    $this->say('Done doing nothing ¯\_ツ_/¯');
  }
}
