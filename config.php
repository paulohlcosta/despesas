<?php
// ─── Banco de dados ────────────────────────────────────────────────────────
define('DB_HOST',    'localhost');
define('DB_USUARIO', 'seu_usuario');
define('DB_SENHA',   'sua_senha');
define('DB_BANCO',   'db_despesas');

// ─── Upload ────────────────────────────────────────────────────────────────
define('MAX_TAMANHO_UPLOAD', 10 * 1024 * 1024);          // 10 MB
define('TIPOS_PERMITIDOS',   ['image/jpeg','image/png','image/webp']);
define('PASTA_UPLOADS',      __DIR__ . '/uploads/');

// ─── Processamento ─────────────────────────────────────────────────────────
define('MAX_LADO_IMAGEM',    1000);                       // px — lado máximo antes de enviar ao Gemini
define('CATEGORIAS_ARQUIVO', '/var/www/html/categorias.txt');

// ─── Agendamento ───────────────────────────────────────────────────────────
define('CRON_INTERVALO_MIN', 15);                         // minutos entre execuções
define('CRON_CMD',
    '*/15 * * * * www-data /usr/bin/php ' . __DIR__ . '/processar_worker.php >> '
    . __DIR__ . '/logs/worker.log 2>&1'
);
define('CRON_ARQUIVO', '/etc/cron.d/despesas_worker');    // arquivo de cron do sistema