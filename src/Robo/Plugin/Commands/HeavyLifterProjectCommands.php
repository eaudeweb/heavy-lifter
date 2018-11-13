<?php

namespace EauDeWeb\Robo\Plugin\Commands;


class HeavyLifterProjectCommands extends \Robo\Tasks {

  /**
   * Do ... well ... nothing.
   *
   * @throws Exception
   *
   * @command do:nothing
   */
  public function doNothing() {
    Utilities::validateGlobalConfig();
    $this->say('Done doing nothing ¯\_ツ_/¯');
  }

}
