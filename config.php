<?php
define('DB_HOST', 'localhost');
define('DB_USUARIO', 'root');
define('DB_SENHA', 'SUA_SENHA_AQUI');
define('DB_BANCO', 'db_despesas');
define('MAX_TAMANHO_UPLOAD', 10 * 1024 * 1024);
define('TIPOS_PERMITIDOS', ['image/jpeg', 'image/png', 'image/webp']);
define('PASTA_UPLOADS', __DIR__ . '/uploads/');
define('ARQUIVO_CATEGORIAS', '/var/www/html/categorias.txt');
define('INTERVALO_CRON_MINUTOS', 15);
define('LLM_HOST', 'http://192.168.2.10:1234');
define('LLM_MODEL', 'gemma-3-4b');
define('LLM_TIMEOUT', 600); // segundos — processamento lento
