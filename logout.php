<?php
require_once __DIR__ . '/config.php';

$_SESSION = [];
session_regenerate_id(true);
session_destroy();

header('Location: login.php');
exit;
