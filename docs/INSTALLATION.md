# Instalación de geoLocator

Preparación de distribución: **28/10/2026**.

El paquete Composer conserva el nombre `absolutoffice/geo-locator`, el namespace `GeoLocator\` y PHP `^8.5.12`. Este árbol fuente está preparado como versión `1.0.0`, registrada en `VERSION`; Composer no usa ese archivo para resolver la dependencia y seguirá viendo la rama como `dev-main` hasta que se publique el tag `v1.0.0`. La ubicación final prevista es `https://github.com/absolutoffice/geo-locator`.

## 1. Elegir la distribución

| Modalidad | Qué contiene | Qué hace falta para utilizarla |
| --- | --- | --- |
| Dependencia Composer desde GitHub | Código de geoLocator; Composer resuelve sus dependencias | Composer en la aplicación consumidora |
| Clon o ZIP de código fuente de GitHub | Código, configuración de ejemplo, documentación, pruebas y herramientas | Ejecutar `composer install` después de descargar |
| Asset `geo-locator-<version>-standalone.zip` de una Release | Código y `vendor/` de producción ya preparado | PHP y extensiones compatibles; Composer no es necesario para ejecutarlo |

Las tres modalidades utilizan la misma API. Ninguna distribución pública contiene una base MMDB, credenciales de MaxMind, una clave privada de caché ni estado de actualización de una instalación anterior.

### Requisitos de ejecución

- PHP `>=8.5.12` y `<9.0`, tanto para CLI como para el SAPI web que ejecute la aplicación.
- Extensiones JSON, Phar, zlib y Sodium declaradas por el paquete.
- Para las actualizaciones HTTPS, un transporte usable por xcurl: cURL, streams HTTPS o socket TLS con sus requisitos de entorno. La instalación de Composer no habilita extensiones del servidor.
- Una base MaxMind compatible y legible para consultar IP públicas. La primera actualización necesita además las credenciales correspondientes, conectividad HTTPS y permisos de escritura en los directorios de datos.

La compatibilidad con PHP 9 se evaluará cuando se pueda probar esa versión. Esta preparación conserva las restricciones de versión acordadas.

## 2. Instalar con Composer desde GitHub

Ejecutar en la raíz de la **aplicación consumidora**, después de crear y publicar el repositorio:

```bash
composer config repositories.geolocator vcs https://github.com/absolutoffice/geo-locator
composer require absolutoffice/geo-locator:dev-main
composer check-platform-reqs --no-dev
```

`dev-main` selecciona explícitamente la rama de desarrollo. Para producción, utilizar una versión estable publicada; por ejemplo, después de publicar `v1.0.0`:

```bash
composer require 'absolutoffice/geo-locator:^1.0'
```

La aplicación tendrá una entrada equivalente a esta en su propio `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/absolutoffice/geo-locator"
        }
    ],
    "require": {
        "absolutoffice/geo-locator": "dev-main"
    }
}
```

Integrar estas claves en el archivo existente de la aplicación, conservando las demás dependencias. Los repositorios deben declararse en el proyecto raíz: Composer no incorpora los `repositories` de las dependencias.

El autoloader que se carga es el de la aplicación:

```php
require __DIR__ . '/vendor/autoload.php';
```

No ejecutar una instalación Composer adicional dentro de `vendor/absolutoffice/geo-locator/`. Las dependencias de geoLocator ya forman parte del árbol administrado por la aplicación. Esta debe conservar su propio `composer.lock` y desplegar con `composer install`.

### Packagist es opcional

El repositorio VCS anterior permite instalar el paquete directamente desde GitHub. Si posteriormente se registra el repositorio en Packagist, bastará con `composer require` y se podrá retirar la entrada VCS cuando la versión deseada esté disponible allí. Subir código a GitHub no registra automáticamente el paquete en Packagist.

## 3. Descargar el código fuente de GitHub

Después de descargar y descomprimir **Code → Download ZIP**, o de clonar el repositorio, ejecutar en su raíz:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
composer check-platform-reqs --no-dev
```

El código fuente no incluye `vendor/`. La ausencia del directorio antes de ejecutar Composer es normal. El lock del repositorio fija las dependencias de esta instalación como proyecto raíz; no obliga a una aplicación consumidora a utilizar ese mismo lock.

Una aplicación que mantenga esta copia en `/srv/lib/geo-locator` cargará:

```php
require '/srv/lib/geo-locator/vendor/autoload.php';
```

Si la aplicación ya administra sus dependencias con Composer, utilizar preferentemente la modalidad de dependencia para compartir un único autoloader.

## 4. Descargar el paquete autónomo

En la página **Releases** del repositorio, seleccionar el asset denominado `geo-locator-<version>-standalone.zip`. Los enlaces automáticos de GitHub **Source code (zip)** y **Source code (tar.gz)** son archivos de fuente y no sustituyen ese asset.

Descargar también `SHA256SUMS` y comprobar el archivo antes de desplegarlo. Por ejemplo, para una Release que realmente se llame `1.0.0`:

```bash
sha256sum --check SHA256SUMS
unzip geo-locator-1.0.0-standalone.zip
```

En PowerShell puede obtenerse la suma con `Get-FileHash .\geo-locator-1.0.0-standalone.zip -Algorithm SHA256` y compararla con la publicada.

El ZIP extrae una carpeta `geo-locator/` con el autoloader `geo-locator/vendor/autoload.php`. La carpeta puede moverse junto con su contenido. Las dependencias de producción y sus licencias acompañan al paquete; PHPStan y PHPCS pertenecen al entorno de desarrollo.

El paquete autónomo permite ejecutar pruebas y ejemplos con PHP. La base de datos y la configuración se preparan por separado, igual que en una instalación Composer.

## 5. Preparar configuración y datos persistentes

Para Composer, almacenar los datos fuera de `vendor/` evita que una actualización o reinstalación de dependencias sustituya una base MMDB o su estado. También es la disposición recomendada para una copia autónoma reemplazable.

| Ruta de ejemplo | Uso |
| --- | --- |
| `/srv/my-app/private/geolocator.env` | Configuración privada elegida por la aplicación |
| `/srv/my-app/var/geoip/GeoLite2-City.mmdb` | Base de datos persistente |
| `/srv/my-app/var/geoip/.state/` | JSON de estado y archivo de bloqueo |
| `/srv/my-app/vendor/` | Dependencias reemplazables de la aplicación |
| `/srv/my-app/public/` | Raíz pública del servidor web |

Copiar `.env.example` a la ubicación privada elegida y rellenar al menos:

```dotenv
GEOLOCATION_SECRET=YOUR_GENERATED_BASE64URL_32_BYTE_SECRET
MAXMIND_DATABASE_PATH=/srv/my-app/var/geoip/GeoLite2-City.mmdb
MAXMIND_STATE_DIR=/srv/my-app/var/geoip/.state
```

Generar la clave en el entorno propio y guardar el resultado como `GEOLOCATION_SECRET`:

```bash
php -r 'echo rtrim(strtr(base64_encode(random_bytes(32)), "+/", "-_"), "="), PHP_EOL;'
```

El texto `YOUR_GENERATED_BASE64URL_32_BYTE_SECRET` es un marcador. La clave se necesita al construir la configuración agregada con `GeoLocatorFactory::configFromEnvironment()`; no se genera ni se persiste automáticamente durante la instalación.

En Windows pueden utilizarse rutas absolutas como `C:/my-app/var/geoip/GeoLite2-City.mmdb` y `C:/my-app/var/geoip/.state`.

La aplicación pasa el archivo de entorno explícitamente:

```php
use GeoLocator\GeoLocatorFactory;

require '/srv/my-app/vendor/autoload.php';

$config = GeoLocatorFactory::configFromEnvironment('/srv/my-app/private/geolocator.env');
$locator = GeoLocatorFactory::create($config);
```

GeoLocator no busca archivos `.env` ni cambia las variables globales al leerlos. Con `configFromEnvironment(null)`, lee únicamente las fuentes de entorno del proceso/PHP. Los valores presentes en esas fuentes tienen prioridad sobre el archivo suministrado.

### Compatibilidad con rutas relativas existentes

Una ruta relativa siempre se resuelve desde la raíz **del paquete geoLocator**. No cambia de significado al usar Composer. Por ejemplo, `db/MaxMind/GeoLite2-City.mmdb` dentro de una instalación Composer apunta a `vendor/absolutoffice/geo-locator/db/MaxMind/GeoLite2-City.mmdb`.

Las rutas relativas se mantienen por compatibilidad con instalaciones autónomas que administran expresamente ese árbol. Para las nuevas instalaciones descritas aquí, usar rutas absolutas a los datos de la aplicación.

## 6. Disponer de una base MMDB

Elegir una de las dos opciones:

1. Colocar una MMDB obtenida por la aplicación en la ruta configurada.
2. Configurar `MAXMIND_ACCOUNT_ID` y `MAXMIND_LICENSE_KEY` y ejecutar explícitamente el actualizador.

Instalar el paquete o cargar el autoloader no inicia peticiones de actualización. El lector abre la MMDB cuando necesita consultar una IP pública. Una base ausente o ilegible provoca `MaxMindProviderException`; `NOT_FOUND` representa una búsqueda válida sin registro.

El usuario de PHP necesita leer la base y la configuración. El proceso que actualiza necesita además escribir en el directorio de la MMDB y en el de estado. La configuración privada solo se lee; no se escriben temporales junto a ella.

## 7. Comprobar la instalación

Los ejemplos reciben rutas absolutas para funcionar desde cualquier directorio de trabajo. Para una aplicación Composer:

```bash
php vendor/absolutoffice/geo-locator/examples/lookup.php \
    /srv/my-app/vendor/autoload.php \
    /srv/my-app/private/geolocator.env
```

La dirección predeterminada del ejemplo es `127.0.0.1`: permite comprobar configuración y carga de clases sin una MMDB. Para una consulta pública después de instalar la base:

```bash
php vendor/absolutoffice/geo-locator/examples/lookup.php \
    /srv/my-app/vendor/autoload.php \
    /srv/my-app/private/geolocator.env \
    8.8.8.8
```

En una copia autónoma:

```bash
php /srv/lib/geo-locator/examples/lookup.php \
    /srv/lib/geo-locator/vendor/autoload.php \
    /srv/my-app/private/geolocator.env
```

El ejemplo `update.php` construye el actualizador y valida la presencia de configuración y un transporte disponible. Por defecto no contacta con MaxMind ni comprueba que las credenciales sean aceptadas por el servicio. La opción `--run` hace la llamada real a `updateIfDue()`:

```bash
php /srv/lib/geo-locator/examples/update.php \
    /srv/lib/geo-locator/vendor/autoload.php \
    /srv/my-app/private/geolocator.env

php /srv/lib/geo-locator/examples/update.php \
    /srv/lib/geo-locator/vendor/autoload.php \
    /srv/my-app/private/geolocator.env \
    --run
```

Desde una copia de desarrollo o el paquete autónomo, las pruebas deterministas no requieren credenciales reales ni acceso a MaxMind:

```bash
php tests/index.php
php tools/lint.php
```

Para comprobar un autoloader consumidor específico, el test de instalación acepta su ruta:

```bash
php tests/ComposerInstallationTest.php /srv/my-app/vendor/autoload.php
```

### Lista de comprobación

- [ ] PHP y extensiones verificados en CLI y en el SAPI web real.
- [ ] Un único autoloader elegido para esta integración.
- [ ] Configuración privada suministrada explícitamente y clave generada por la aplicación.
- [ ] Rutas absolutas de MMDB y estado fuera de `vendor/`.
- [ ] Consulta local de prueba correcta; consulta pública correcta cuando la MMDB esté disponible.
- [ ] Actualización real comprobada solo cuando se vaya a utilizar esa función.
- [ ] Panel web de `tests/` reservado al entorno de desarrollo o acceso controlado.

## Referencias

- [Composer: repositorios VCS y configuración en el proyecto raíz](https://getcomposer.org/doc/05-repositories.md).
- [Composer: publicación de librerías y alcance de composer.lock](https://getcomposer.org/doc/02-libraries.md).
- [GitHub: descarga de archivos de código fuente](https://docs.github.com/en/repositories/working-with-files/using-files/downloading-source-code-archives).
- [Guía de migración PHP 8.5.12 / xcurl de este proyecto](../MIGRACION-XCURL.md).
