<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
$dbName=$argv[1]??'';$mode=$argv[2]??'';
if(!preg_match('/^PORTALGP_SEC_STAGE1_[a-f0-9]{12}$/D',$dbName))throw new RuntimeException('Solo base temporal de pruebas.');
$modes=[
    'profile_anonymous','profile_no_csrf','roles_no_csrf','users_no_csrf',
    'permissions_no_csrf','departments_no_csrf','msp_readonly_write',
    'msp_editor_delete','disabled_user'
];
if(!in_array($mode,$modes,true))throw new RuntimeException('Caso no permitido.');
putenv('PORTALGP_DB_DATABASE='.$dbName);
session_set_save_handler(new class implements SessionHandlerInterface {
    public function open(string $path,string $name):bool{return true;}
    public function close():bool{return true;}
    public function read(string $id):string{return '';}
    public function write(string $id,string $data):bool{return true;}
    public function destroy(string $id):bool{return true;}
    public function gc(int $max_lifetime):int|false{return 0;}
});
session_start();
require dirname(__DIR__).'/db.php';
require dirname(__DIR__).'/security.php';
$admin=(int)$conn->query("SELECT id FROM dbo.cr_usuarios WHERE UserName=N'admin_2'")->fetchColumn();
$id=in_array($mode,['msp_readonly_write','msp_editor_delete','disabled_user'],true)?99001:$admin;
$user=pgpSecurityUser($conn,$id);
$_SESSION=$mode==='profile_anonymous'?[]:['usuario'=>$user,'pgp_security_version'=>(int)$user['security_version']];
$_SERVER['REQUEST_METHOD']='POST';$_POST=[];$_GET=[];
$route=match($mode){
    'roles_no_csrf'=>'sistema/gestion/roles.php',
    'users_no_csrf'=>'sistema/gestion/usuarios.php',
    'permissions_no_csrf'=>'sistema/gestion/permisos.php',
    'departments_no_csrf'=>'sistema/gestion/departamentos.php',
    'msp_readonly_write','disabled_user'=>'msp/locales/guardar.php',
    'msp_editor_delete'=>'msp/locales/eliminar.php',
    default=>'procesar_actualizar_perfil.php',
};
$_SERVER['SCRIPT_NAME']='/portalgp/'.$route;
$_SERVER['PHP_SELF']=$_SERVER['SCRIPT_NAME'];$_SERVER['REQUEST_URI']=$_SERVER['SCRIPT_NAME'];
ob_start();
register_shutdown_function(static function():void{
    $body=(string)ob_get_clean();
    echo json_encode(['status'=>http_response_code()?:200,'php_error'=>preg_match('/(Fatal error:|Warning:|Parse error:)/',$body)===1]);
});
require dirname(__DIR__).'/'.$route;
