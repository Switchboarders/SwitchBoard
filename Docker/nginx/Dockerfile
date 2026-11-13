FROM nginx:alpine

# Install envsubst (in gettext package)
RUN apk add --no-cache gettext

# Copy template config
COPY nginx.conf.template /etc/nginx/templates/nginx.conf.template

# Startup script
CMD envsubst < /etc/nginx/templates/nginx.conf.template > /etc/nginx/conf.d/default.conf \
    && exec nginx -g 'daemon off;'
