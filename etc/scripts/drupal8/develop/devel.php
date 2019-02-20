<?php

function devel_add_permission_for_all_users() {
  if (!\Drupal::moduleHandler()->moduleExists('devel')) {
    return;
  }

  foreach (['anonymous', 'authenticated'] as $rid) {
    $role = \Drupal\user\Entity\Role::load($rid);
    $role->grantPermission('access devel information');
    $role->grantPermission('access kint');
    $role->grantPermission('execute php code');
    $role->grantPermission('switch users');
    $role->save();
  }

  $devel_config = \Drupal::configFactory()->getEditable('devel.settings')->set('devel_dumper', 'var_dumper')->save();
}

devel_add_permission_for_all_users();
