<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| -------------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------------
| This file lets you re-map URI requests to specific controller functions.
|
| Typically there is a one-to-one relationship between a URL string
| and its corresponding controller class/method. The segments in a
| URL normally follow this pattern:
|
|	example.com/class/method/id/
|
| In some instances, however, you may want to remap this relationship
| so that a different class/function is called than the one
| corresponding to the URL.
|
| Please see the user guide for complete details:
|
|	https://codeigniter.com/userguide3/general/routing.html
|
| -------------------------------------------------------------------------
| RESERVED ROUTES
| -------------------------------------------------------------------------
|
| There are three reserved routes:
|
|	$route['default_controller'] = 'welcome';
|
| This route indicates which controller class should be loaded if the
| URI contains no data. In the above example, the "welcome" class
| would be loaded.
|
|	$route['404_override'] = 'errors/page_missing';
|
| This route will tell the Router which controller/method to use if those
| provided in the URL cannot be matched to a valid route.
|
|	$route['translate_uri_dashes'] = FALSE;
|
| This is not exactly a route, but allows you to automatically route
| controller and method names that contain dashes. '-' isn't a valid
| class or method name character, so it requires translation.
| When you set this option to TRUE, it will replace ALL dashes in the
| controller and method URI segments.
|
| Examples:	my-controller/index	-> my_controller/index
|		my-controller/my-method	-> my_controller/my_method
*/
$route['default_controller'] = 'welcome';
$route['404_override'] = '';
$route['translate_uri_dashes'] = FALSE;

$route['api/login'] = 'api/login';


$route['api/buscar-folio'] = 'api/buscar_folio';
$route['api/validar-folio'] = 'api/validar_folio';


$route['api/validar-nap'] = 'api/validar_nap';
$route['api/buscar-nap']   = 'api/buscar_nap';
$route['api/validar-nap-activo'] = 'api/validar_nap_activo';
$route['api/dueno-nap'] = 'api/dueno_nap';
$route['api/validar-comisionista'] = 'api/validar_comisionista';



$route['api/afores']       = 'api/afores';

$route['api/buscar-comisionista'] = 'api/buscar_comisionista';
$route['api/guardar-traspaso']    = 'api/guardar_traspaso';


$route['api/validar-bloqueo'] = 'api/validar_bloqueo';

$route['api/subir-evidencia'] = 'api/subir_evidencia';
$route['api/evidencias']      = 'api/evidencias';

$route['api/dashboard-stats']      = 'api/dashboard_stats';
$route['api/historial-traspasos']  = 'api/historial_traspasos';
$route['api/pendientes'] = 'api/pendientes';
$route['api/folios-incompletos'] = 'api/folios_incompletos';
$route['api/guardar-token-push'] = 'api/guardar_token_push';

$route['api/spartan-folios']    = 'api/spartan_folios';
$route['api/spartan-recuperar'] = 'api/spartan_recuperar';
$route['api/spartan-reasignar'] = 'api/spartan_reasignar';
$route['api/spartan-capturar'] = 'api/spartan_capturar';
$route['api/spartan-historial'] = 'api/spartan_historial';

$route['api/super-capturas']        = 'api/super_capturas';
$route['api/super-usuarios']        = 'api/super_usuarios';
$route['api/super-guardar-usuario'] = 'api/super_guardar_usuario';
$route['api/spartan-subir-evidencia'] = 'api/spartan_subir_evidencia';
$route['api/super-capturas-usuario'] = 'api/super_capturas_usuario';
$route['api/super-buscar-spartan'] = 'api/super_buscar_spartan';
$route['api/cancelaciones-estructura'] = 'api/cancelaciones_estructura';

$route['api/detalle-folio'] = 'api/detalle_folio';


$route['api/expediente-crear']      = 'api/expediente_crear';

$route['api/super-reporte-spartan'] = 'api/super_reporte_spartan';
$route['api/spartan-buscar-folio'] = 'api/spartan_buscar_folio';
$route['api/expedientes-lista']     = 'api/expedientes_lista';
$route['api/expediente-detalle']    = 'api/expediente_detalle';
$route['api/expediente-subir-doc']  = 'api/expediente_subir_doc';

$route['api/expediente-generar-token']          = 'api/expediente_generar_token';
$route['api/expediente-token']                  = 'api/expediente_token';
$route['api/expediente-subir-doc-token']        = 'api/expediente_subir_doc_token';
$route['api/expediente-actualizar-datos-token'] = 'api/expediente_actualizar_datos_token';

$route['api/notificaciones']      = 'api/notificaciones';
$route['api/notificaciones-leer'] = 'api/notificaciones_leer';

$route['api/comentarios']         = 'api/comentarios';
$route['api/comentarios-agregar'] = 'api/comentarios_agregar';


$route['api/aceptar-tyc'] = 'api/aceptar_tyc';

$route['api/notificaciones-limpiar'] = 'api/notificaciones_limpiar';
$route['api/super-buscar-folio'] = 'api/super_buscar_folio';

$route['api/super-reporte-spartan'] = 'api/super_reporte_spartan';
$route['api/spartan-buscar-folio'] = 'api/spartan_buscar_folio';

$route['api/sepomex'] = 'api/sepomex';

$route['api/super-admin-capturas']   = 'api/super_admin_capturas';
$route['api/super-admin-reasignar']  = 'api/super_admin_reasignar';

$route['api/version'] = 'api/version';
