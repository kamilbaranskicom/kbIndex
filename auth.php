<?php
// auth.php
session_start();

// Prosta autoryzacja: jeśli brak sesji, przekierowanie do stats.php
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header("Location: ../stats.php");
    exit;
}

// Funkcja do ustawienia autoryzacji w set_auth.php
function setAuth($state = true) {
    $_SESSION['authenticated'] = $state;
}
?>
