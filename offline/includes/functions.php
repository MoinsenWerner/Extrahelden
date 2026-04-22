<?php
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

function verifyPassword($password, $hashedPassword) {
    return password_verify($password, $hashedPassword);
}

function validateRequiredFields($fields) {
    foreach ($fields as $field) {
        if (empty($_POST[$field])) {
            return false;
        }
    }
    return true;
}
?>