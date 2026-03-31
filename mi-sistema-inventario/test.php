<?php
echo "cURL disponible: " . (function_exists('curl_init') ? 'SÍ' : 'NO') . "<br>";
$ch = curl_init('https://api.exchangerate-api.com/v4/latest/USD');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$r = curl_exec($ch);
echo "HTTP Code: " . curl_getinfo($ch, CURLINFO_HTTP_CODE) . "<br>";
echo "cURL Error: " . curl_error($ch) . "<br>";
echo "Respuesta: " . substr($r, 0, 100);
curl_close($ch);