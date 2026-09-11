<?php
// Copie para config.php apenas se ele ainda não existir.
// Use exclusivamente a conexão da branch de testes durante o desenvolvimento.
// Nunca publique config.php nem uma URL que contenha a senha real.
define('NEON_DATABASE_URL', getenv('NEON_DATABASE_URL') ?: '');
// Alternativa local: substitua a expressão acima por uma string com a conexão,
// somente na cópia ignorada config.php. Preserve sslmode=require na URL.
