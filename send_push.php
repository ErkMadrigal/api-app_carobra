<?php

define('FCM_CREDENTIALS', __DIR__ . '/storage/carobra-helpia-41848f23bcf2.json');
define('FCM_PROJECT_ID',  'carobra-helpia');

function obtener_token_fcm() {
    $creds = json_decode(file_get_contents(FCM_CREDENTIALS), true);

    $now    = time();
    $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claim  = base64_encode(json_encode([
        'iss'   => $creds['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));

    $header = str_replace(['+', '/', '='], ['-', '_', ''], $header);
    $claim  = str_replace(['+', '/', '='], ['-', '_', ''], $claim);

    $to_sign = $header . '.' . $claim;

    openssl_sign($to_sign, $signature, $creds['private_key'], 'SHA256');
    $signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

    $jwt = $to_sign . '.' . $signature;

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt,
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resp = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($resp, true);
    return $data['access_token'] ?? null;
}

function enviar_push($token, $titulo, $cuerpo, $data = []) {
    if (empty($token)) return null;

    $access_token = obtener_token_fcm();
    if (!$access_token) {
        error_log('FCM: no se pudo obtener access token');
        return null;
    }

    $url = 'https://fcm.googleapis.com/v1/projects/' . FCM_PROJECT_ID . '/messages:send';

    $data_string = [];
    foreach ($data as $k => $v) {
        $data_string[$k] = (string) $v;
    }
    $data_string['title'] = $titulo;
    $data_string['body']  = $cuerpo;

    $payload = [
        'message' => [
            'token'        => $token,
            'notification' => [
                'title' => $titulo,
                'body'  => $cuerpo,
            ],
            'android' => [
                'priority' => 'high',
                'notification' => [
                    'sound'        => 'default',
                    'click_action' => 'FCM_PLUGIN_ACTIVITY',
                ],
            ],
            'apns' => [
                'payload' => [
                    'aps' => [
                        'sound' => 'default',
                        'badge' => 1,
                    ],
                ],
            ],
            'data' => $data_string,
        ],
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_HTTPHEADER,     [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT,        10);

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        error_log("FCM curl error: {$err}");
        return null;
    }

    return json_decode($resp, true);
}

function enviar_push_masivo($tokens, $titulo, $cuerpo, $data = []) {
    if (empty($tokens)) return [];
    $resultados = [];
    foreach ($tokens as $token) {
        $resultados[$token] = enviar_push($token, $titulo, $cuerpo, $data);
    }
    return $resultados;
}
