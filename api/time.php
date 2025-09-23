<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

echo json_encode([
    'server_time' => round(microtime(true) * 1000),
    'iso_time' => date('c')
]);
?>
