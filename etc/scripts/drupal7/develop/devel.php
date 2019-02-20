<?php

function devel_add_permission_for_all_users() {
  if (!module_exists('devel')) {
    return;
  }

  $permissions = array('access devel information', 'execute php code', 'switch users');
  foreach (array('anonymous user', 'authenticated user') as $rid) {
    $role = user_role_load_by_name($rid);
    user_role_grant_permissions($role->rid, $permissions);
  }
}

devel_add_permission_for_all_users();
