<?php
require_once 'config/database.php';
initSession();
session_destroy();
header('Location: login.php');
exit;
