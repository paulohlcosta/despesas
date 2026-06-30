<?php
define('DB_HOST', 'localhost');
define('DB_USUARIO', 'seu_usuario');
define('DB_SENHA', 'sua_senha');
define('DB_BANCO', 'db_despesas');
define('MAX_TAMANHO_UPLOAD', 10 * 1024 * 1024);
define('TIPOS_PERMITIDOS', ['image/jpeg', 'image/png', 'image/webp']);
define('PASTA_UPLOADS', __DIR__ . '/uploads/');
define('ARQUIVO_CATEGORIAS', '/var/www/html/categorias.txt');
define('INTERVALO_CRON_MINUTOS', 15);