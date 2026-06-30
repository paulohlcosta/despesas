<?php
// Credenciais do banco de dados
define('DB_HOST', 'localhost');
define('DB_USUARIO', 'seu_usuario');
define('DB_SENHA', 'sua_senha');
define('DB_BANCO', 'db_despesas');

// Tamanho máximo de upload (10MB em bytes)
define('MAX_TAMANHO_UPLOAD', 10 * 1024 * 1024);

// Tipos de arquivo permitidos
define('TIPOS_PERMITIDOS', ['image/jpeg', 'image/png', 'image/webp']);

// Pasta de uploads (com barra no final)
define('PASTA_UPLOADS', __DIR__ . '/uploads/');