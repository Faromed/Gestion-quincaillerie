<?php
$password = 'admin'; // Le mot de passe que vous voulez hacher
$hashed_password = password_hash($password, PASSWORD_DEFAULT);
echo $hashed_password;
?>