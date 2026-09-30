# Despliegue Debian

## Almacenamiento

Crear el directorio fuera del codigo y concederlo solo al usuario que ejecuta Apache/PHP:

```sh
sudo install -d -o www-data -g www-data -m 700 /mnt/storage
```

Configurar `DRIVE_STORAGE_PATH=/mnt/storage` en el entorno del servicio PHP. Los archivos se guardan como UUID sin extension; MariaDB conserva nombre, extension, tamano, MIME, usuario y carpeta logica.

Ejecutar `config/init_db.php` una vez para crear la tabla y migrar las columnas `uuid` y `extension`.

## Nginx y archivos grandes

1. Copiar `nginx/drive.conf.example` a `/etc/nginx/sites-available/drive` y sustituir el dominio y el puerto interno de Apache.
2. Verificar con `sudo nginx -t` y recargar Nginx.
3. Aplicar los valores de `php.ini.example` al PHP que atiende Apache. La subida admite archivos de hasta 50 GB.
4. Activar HTTPS despues de que DuckDNS resuelva al servidor:

```sh
sudo certbot --nginx -d arca12btp01.duckdns.org
```

`proxy_request_buffering off` permite que Nginx no espere a recibir el cuerpo completo antes de enviarlo al backend.

## DuckDNS y cron

Copiar `ddns/duckdns-update.sh.example`, regenerar el token mostrado en la captura y guardarlo fuera del repositorio. Crear `/etc/duckdns.env` con permisos `600`:

```sh
DUCKDNS_TOKEN='TOKEN_REGENERADO'
sudo chmod 600 /etc/duckdns.env
```

Proteger el script con `chmod 700`, probarlo manualmente y ejecutarlo cada cinco minutos con cron:

```cron
*/5 * * * * . /etc/duckdns.env; export DUCKDNS_TOKEN; /usr/local/sbin/duckdns-update.sh
```

El backend no expone `/mnt/storage` como URL publica; las descargas pasan por la autorizacion de `dashboard.php`.
