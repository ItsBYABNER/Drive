<?php
require_once 'config/database.php';
initSession();

if (isAuthenticated()) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;
