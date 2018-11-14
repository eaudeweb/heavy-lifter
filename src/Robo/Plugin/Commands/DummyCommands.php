<?php

namespace EauDeWeb\Robo\Plugin\Commands;


class DummyCommands extends CommandBase {

  /**
   * Do ... well ... nothing.
   *
   * @command do:nothing
   *
   * @throws \EauDeWeb\Robo\InvalidConfigurationException
   */
  public function doNothing() {
    $this->validateConfig();
    $this->say('Done doing nothing ¯\_ツ_/¯');
  }
}
