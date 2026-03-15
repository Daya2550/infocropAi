<?php
// admin/logout.php
session_start();
unset($_SESSION['admin_id']);
unset($_SESSION['admin_user']);
header("Location: login.php");
exit;
