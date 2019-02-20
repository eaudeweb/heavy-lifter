<?php

function webprofiler_add_permission_for_all_users() {
  if (!\Drupal::moduleHandler()->moduleExists('webprofiler')) {
    return;
  }

  foreach (['anonymous', 'authenticated'] as $rid) {
    $role = \Drupal\user\Entity\Role::load($rid);
    $role->grantPermission('view webprofiler toolbar');
    $role->save();
  }
}

webprofiler_add_permission_for_all_users();
