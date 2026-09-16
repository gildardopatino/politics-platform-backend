FROM nginx:alpine

# Config y archivos públicos dentro de la imagen (Coolify no monta archivos del repo)
COPY docker/nginx/prod.conf /etc/nginx/conf.d/default.conf
COPY public /var/www/public
