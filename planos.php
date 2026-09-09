<?php
require_once __DIR__ . '/lib/Config.php';
require_once Config::getLibDir() . '/Plans.php';

http_response_code(302);
header('Location: /#plans');
exit;
