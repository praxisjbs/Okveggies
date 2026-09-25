<?php
/** Role administration. All role state and assignments are database-backed. */
require_once __DIR__ . '/../../includes/bootstrap.php';

function rbac_guard_write(): void {
    if (!okv_is_post()) okv_error('Use POST for this action.', 405, 'method_not_allowed');
    if (!Rbac::canManageRoles()) okv_error('You do not have access to manage roles.', 403, 'forbidden');
    if (!Csrf::validate()) okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired');
}
function rbac_role_input(): array {
    $name = trim((string) okv_input('name', ''));
    $slug = okv_slug((string) okv_input('slug', $name));
    if ($name === '' || mb_strlen($name) > 80 || !preg_match('/^[\p{L}\p{N}][\p{L}\p{N} _.-]{1,78}[\p{L}\p{N}]$/u', $name)) okv_error('Use a role name between 3 and 80 letters, numbers, spaces, dots or hyphens.', 422, 'bad_name');
    if ($slug === '' || strlen($slug) > 80 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) okv_error('Use a valid role slug.', 422, 'bad_slug');
    if (in_array($slug, ['owner', 'manager'], true) || in_array(strtolower($name), ['owner', 'manager'], true)) okv_error('That name is reserved for a system role.', 422, 'reserved_name');
    return [$name, $slug];
}
function rbac_permissions(): array {
    return Database::all('SELECT `key`, module, description FROM permissions ORDER BY module, `key`');
}
function rbac_allowed_permissions(array $requested): array {
    $catalogue = array_column(rbac_permissions(), 'key');
    $allowed = array_flip(Rbac::delegablePermissions());
    $requested = array_values(array_unique(array_map('strval', $requested)));
    foreach ($requested as $key) if (!in_array($key, $catalogue, true) || !isset($allowed[$key])) okv_error('You cannot delegate one or more selected permissions.', 403, 'undelegable_permission');
    return $requested;
}

switch (okv_action()) {
case 'list_roles':
    if (!Rbac::canManageRoles() && !Rbac::can('rbac.roles.view')) okv_error('You do not have access to view roles.',403,'forbidden');
    okv_json(['status'=>'ok','roles'=>Database::all('SELECT id,name,slug,description,status,created_at FROM roles ORDER BY name')]);
    break;
case 'list_permissions':
    if (!Rbac::canManageRoles() && !Rbac::can('rbac.roles.view')) okv_error('You do not have access to view permissions.',403,'forbidden');
    okv_json(['status'=>'ok','permissions'=>rbac_permissions()]);
    break;
case 'create_role':
    rbac_guard_write(); [$name,$slug] = rbac_role_input();
    $permissions = rbac_allowed_permissions((array) ($_POST['permissions'] ?? []));
    $pdo = Database::getInstance()->getConnection();
    try { $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO roles (name,slug,description,status) VALUES (:n,:s,:d,\'active\')')->execute([':n'=>$name,':s'=>$slug,':d'=>trim((string) okv_input('description',''))]);
        $id=(int)$pdo->lastInsertId();
        $q=$pdo->prepare('INSERT INTO role_permissions (role_id,permission_id) SELECT :r,id FROM permissions WHERE `key`=:p');
        foreach ($permissions as $p) $q->execute([':r'=>$id,':p'=>$p]);
        Audit::record('rbac.role.create','roles',$id,null,['name'=>$name,'slug'=>$slug,'permissions'=>$permissions]); $pdo->commit();
    } catch (Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); if($e instanceof PDOException && $e->getCode()==='23000') okv_error('That role name or slug is already in use.',409,'duplicate_role'); error_log('rbac create: '.$e->getMessage()); okv_error('We could not create that role.',500,'role_failed'); }
    okv_json(['status'=>'ok','message'=>'Role created.','id'=>$id],201); break;
case 'update_role':
    rbac_guard_write(); $id=(int)okv_input('role_id',0); $old=Database::one('SELECT * FROM roles WHERE id=:id', [':id'=>$id]);
    if(!$old) okv_error('Role not found.',404,'not_found'); if(in_array($old['name'],['owner','manager'],true)) okv_error('System roles are protected.',403,'protected_role');
    [$name,$slug]=rbac_role_input(); $permissions=rbac_allowed_permissions((array)($_POST['permissions']??[])); $status=(string)okv_input('status','active'); if(!in_array($status,['active','disabled'],true)) okv_error('Choose active or disabled.',422,'bad_status');
    $pdo=Database::getInstance()->getConnection(); try{$pdo->beginTransaction(); $pdo->prepare('UPDATE roles SET name=:n,slug=:s,description=:d,status=:st WHERE id=:id')->execute([':n'=>$name,':s'=>$slug,':d'=>trim((string)okv_input('description','')),':st'=>$status,':id'=>$id]); $pdo->prepare('DELETE FROM role_permissions WHERE role_id=:id')->execute([':id'=>$id]); $q=$pdo->prepare('INSERT INTO role_permissions (role_id,permission_id) SELECT :r,id FROM permissions WHERE `key`=:p'); foreach($permissions as $p)$q->execute([':r'=>$id,':p'=>$p]); Audit::record('rbac.role.update','roles',$id,$old,['name'=>$name,'slug'=>$slug,'status'=>$status,'permissions'=>$permissions]); $pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($e instanceof PDOException&&$e->getCode()==='23000')okv_error('That role name or slug is already in use.',409,'duplicate_role');error_log('rbac update: '.$e->getMessage());okv_error('We could not update that role.',500,'role_failed');} okv_json(['status'=>'ok','message'=>'Role updated.']); break;
case 'set_status':
    rbac_guard_write(); $id=(int)okv_input('role_id',0); $status=(string)okv_input('status',''); $old=Database::one('SELECT * FROM roles WHERE id=:id', [':id'=>$id]); if(!$old)okv_error('Role not found.',404,'not_found'); if(in_array($old['name'],['owner','manager'],true))okv_error('System roles are protected.',403,'protected_role'); if(!in_array($status,['active','disabled'],true))okv_error('Choose a valid status.',422,'bad_status'); Database::run('UPDATE roles SET status=:s WHERE id=:id',[':s'=>$status,':id'=>$id]); Audit::record('rbac.role.status','roles',$id,$old,['status'=>$status]); okv_json(['status'=>'ok','message'=>'Role status updated.']); break;
default: okv_error('This action is not available.',400,'unknown_action');
}
