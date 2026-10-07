<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/send_push.php';

$token = 'fqF0XL5FSvytmrNGaGMxI5:APA91bEitPNjATSsMu53S3bng0NA4KFRhZUqrbfh2j750SeYiQO5SH_T0mMNPZxItAzsgALfwN0nLAdsHaY0M1Ch8EZlClmBV29IQlvTGT_1C3jiyGetbNs';

$resultado = enviar_push($token, 'Test Carobra', 'Notificación de prueba funcionando', ['folio' => '1234']);
echo json_encode($resultado, JSON_PRETTY_PRINT);
