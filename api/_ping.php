<?php
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'php_version' => PHP_VERSION, 'step' => 'before_config']);
