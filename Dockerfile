FROM php:8.2-apache

# Instala dependências do sistema e bibliotecas necessárias para o PostgreSQL
RUN apt-get update && apt-get install -y \
    libpq-dev \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Instala e habilita as extensões PHP (PDO e driver do PostgreSQL)
RUN docker-php-ext-install pdo pdo_pgsql pgsql

# Habilita o mod_rewrite do Apache (útil para rotas amigáveis no futuro)
RUN a2enmod rewrite

# Define o diretório de trabalho padrão do Apache
WORKDIR /var/www/html/

# Copia os arquivos do projeto para o container
# Evitamos copiar pastas de teste ou scripts locais desnecessários
COPY index.html ./
COPY api.php ./
COPY webhook.php ./
COPY data.json ./
COPY backend/ ./backend/
COPY assets/ ./assets/
COPY js/ ./js/

# Ajusta as permissões para que o usuário do Apache possa ler/escrever
# Isso é vital para que a aplicação consiga persistir alterações no data.json
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod 664 /var/www/html/data.json

# O Apache já expõe a porta 80 por padrão na imagem base
EXPOSE 80

