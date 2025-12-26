<?php
// set_auth.php
require_once __DIR__ . '/auth.php';

// Przykład ustawienia autoryzacji
// ?secret=twojSekret w URL
$secret = $_GET['secret'] ?? '';
if ($secret === 'secret') {
    setAuth(true);
    echo "Autoryzacja ustawiona.";
} else {
    echo "Niepoprawny sekret.";
}
?>
