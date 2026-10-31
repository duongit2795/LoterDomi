<?php
require __DIR__ . '/lib/bootstrap.php';
header('Location: ' . (is_installed() ? 'admin.php' : 'install.php'));
