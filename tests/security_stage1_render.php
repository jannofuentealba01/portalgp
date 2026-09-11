<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
// Read-only rendering with an in-memory session, never a reusable HTTP session.
session_set_save_handler(new class implements SessionHandlerInterface {
    public function open(string $path,string $name):bool{return true;}
    public function close():bool{return true;}
    public function read(string $id):string{return '';}
    public function write(string $id,string $data):bool{return true;}
    public function destroy(string $id):bool{return true;}
    public function gc(int $max_lifetime):int|false{return 0;}
});
session_start();
require_once dirname(__DIR__).'/db.php';
$routes=[
    'roles'=>'sistema/gestion/roles.php',
    'users'=>'sistema/gestion/usuarios.php',
    'permissions'=>'sistema/gestion/permisos.php',
    'departments'=>'sistema/gestion/departamentos.php',
    'profile'=>'profile.php',
    'menu'=>'msp/msp_menu.php',
];
$mode=$argv[1]??'roles';
if(!isset($routes[$mode]))throw new InvalidArgumentException('Vista no permitida.');
$user=$conn->query("SELECT id,UserName,nombre_completo,correo_electronico,security_version FROM dbo.cr_usuarios WHERE UserName=N'admin_2' AND estado_id=1")->fetch();
if(!$user)throw new RuntimeException('admin_2 no está habilitado.');
$_SESSION=['usuario'=>$user,'pgp_security_version'=>(int)$user['security_version']];
$_SERVER['REQUEST_METHOD']='GET';
$_SERVER['SCRIPT_NAME']='/portalgp/'.$routes[$mode];
$_SERVER['PHP_SELF']=$_SERVER['SCRIPT_NAME'];
$_SERVER['REQUEST_URI']=$_SERVER['SCRIPT_NAME'];
$_GET=[];$_POST=[];
ob_start();
require dirname(__DIR__).'/'.$routes[$mode];
$html=(string)ob_get_clean();
$result=['vista'=>$mode,'bytes'=>strlen($html),'sin_errores_php'=>preg_match('/(Fatal error:|Warning:|Parse error:)/',$html)!==1];
$protectedViews=['roles','users','permissions','departments'];
if(in_array($mode,$protectedViews,true)){
    $doc=new DOMDocument();@$doc->loadHTML($html,LIBXML_NONET);$xpath=new DOMXPath($doc);
    $forms=$xpath->query('//form[translate(@method,"post","POST")="POST"]');
    $protected=0;foreach($forms as $form){if($xpath->query('.//input[@name="_pgp_csrf"]',$form)->length===1)$protected++;}
    $result['formularios_post']=$forms->length;$result['formularios_con_csrf']=$protected;
    if($forms->length!==$protected)throw new RuntimeException('Hay formularios POST sin protección CSRF en '.$mode.'.');
}
if($mode==='roles'){
    $result['tabla_acciones']=$xpath->query('//*[@id="permission_actions_table"]')->length===1;
    $result['checks_acciones']=$xpath->query('//*[@id="permission_actions_table"]//input[@type="checkbox"]')->length;
    $expectedAdminPermissions=(int)$conn->query("SELECT COUNT(*) FROM dbo.cr_rol_permisos rp INNER JOIN dbo.cr_usuarios u ON u.rol_id=rp.rol_id WHERE u.UserName=N'admin_2' AND rp.lectura=1 AND rp.escritura=1 AND rp.eliminacion=1")->fetchColumn();
    $result['admin_permisos_completos']=false;
    $result['admin_permisos_esperados']=$expectedAdminPermissions;
    foreach($xpath->query('//*[@data-role]') as $node){$role=json_decode($node->getAttribute('data-role'),true);if((int)($role['id']??0)===1){$result['admin_permisos_completos']=$expectedAdminPermissions>0&&count($role['permission_actions']??[])===$expectedAdminPermissions;}}
    if($forms->length!==$protected||!$result['tabla_acciones']||!$result['admin_permisos_completos'])throw new RuntimeException('Falló el render de Roles.');
}elseif($mode==='profile'){
    $result['csrf_perfil']=str_contains($html,'name="_pgp_csrf"');
    $result['perfil_correcto']=str_contains($html,'admin_2');
    if(!$result['csrf_perfil']||!$result['perfil_correcto'])throw new RuntimeException('Falló el render de Perfil.');
}elseif($mode==='menu'){$result['menu_msp_visible']=str_contains($html,'Locales y tiendas');}
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),PHP_EOL;
if(!$result['sin_errores_php'])exit(1);
