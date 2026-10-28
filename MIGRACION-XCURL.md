# Migración de geoLocator a xcurl y PHP 8.5.12

Migración y revisión PHPDoc: **25/10/2026**. Preparación de distribución Composer/GitHub: **26/10/2026**.

Este documento conserva las decisiones y evidencias de la migración a xcurl. Para instalar la distribución pública preparada después de esa migración, consultar [docs/INSTALLATION.md](docs/INSTALLATION.md). El repositorio de fuente y el ZIP autónomo tienen contenidos distintos: el primero se instala con Composer; el segundo incluye las dependencias de producción. Ninguno distribuye las bases, credenciales o estado privados del archivo original.

## Resultado y requisitos

GeoLocator utiliza `absolutoffice/xcurl` para sus comunicaciones HTTPS salientes. El cliente específico de MaxMind conserva las URLs, las credenciales, la consulta de fecha y las verificaciones del archivo. El actualizador conserva el bloqueo, la extracción, la validación MMDB y el reemplazo atómico.

| Elemento | Requisito de esta entrega |
| --- | --- |
| PHP | `^8.5.12`, también en CLI y en el servidor web |
| Transporte y políticas HTTP | `absolutoffice/xcurl:^1.1` |
| Lector MMDB | `maxmind-db/reader:^1.14` |
| Extensiones requeridas por geoLocator | JSON, Phar, zlib y Sodium |
| Transporte disponible | cURL, streams HTTPS o socket TLS/OpenSSL, elegido por xcurl |

El requisito de PHP permite versiones compatibles posteriores y evita instalar el paquete en versiones inferiores a 8.5.12. Composer no instala ni actualiza el intérprete PHP. La aplicación consumidora debe conservar su `composer.lock` para fijar las dependencias instaladas.

La entrega autónoma de la migración incluía `vendor/` de producción: **xcurl v1.1.0** (commit `5661dcb2ae959ec2249232bef4ed933230f2f4f2`) y **maxmind-db/reader v1.14.0**. El lock conserva esas dependencias y las herramientas de desarrollo PHPStan 2.2.14 y PHPCS 4.0.4. En la distribución Composer/GitHub, el código fuente se entrega sin `vendor/`; el builder incorpora exclusivamente las dependencias de producción al asset `*-standalone.zip`. Ejecutar `composer install` sin `--no-dev` en un checkout de desarrollo para disponer de las herramientas de análisis.

La migración partió del archivo standalone facilitado, cuya documentación se identificaba como 0.9.0-rc1. La preparación de distribución tampoco asigna un nuevo tag SemVer a geoLocator. Antes de publicar una versión estable, decidir el número correspondiente a los cambios de mínimo PHP y de las clases HTTP públicas. Los artefactos locales `dev-main` son previews de desarrollo.

## Actualización de una instalación

El procedimiento siguiente describe la actualización de una copia autónoma previa. Para una dependencia administrada por Composer, actualizarla desde la aplicación consumidora y seguir su propio lock; conservar configuración, MMDB y estado en ubicaciones persistentes fuera de `vendor/`.

1. Actualizar PHP a una versión estable compatible, comprobando por separado CLI y PHP-FPM/Apache. Mantener las extensiones necesarias y la configuración de certificados CA.
2. Conservar una copia recuperable del código, configuración y MMDB de la instalación. Mantener el `.env` privado en su ubicación actual.
3. Desplegar `src/`, `config/environment.php`, `composer.json`, `composer.lock` y la documentación de esta entrega. Si se copia sobre una instalación existente, retirar el antiguo directorio `src/GeoLocator/Update/Http/`; no debe quedar una segunda implementación de red.
4. Instalar las dependencias con el PHP de destino:

   ```bash
   composer install --no-dev --prefer-dist --optimize-autoloader
   composer check-platform-reqs --no-dev
   php tests/index.php
   ```

5. Utilizar el autoloader de Composer de la aplicación. El fallback de desarrollo solo carga clases propias de GeoLocator y no sustituye las dependencias externas.
6. Comprobar el panel de pruebas en el SAPI web utilizado en producción: la versión y las extensiones pueden diferir de CLI. El actualizador real se ejecuta únicamente mediante la acción manual del panel o el planificador configurado por la aplicación.
7. En el despliegue real, comprobar primero una consulta MMDB y después una actualización controlada. Verificar estado, permisos y conservación de la base anterior si la descarga o la validación fallan.

**Cambio de distribución del 26/10/2026:** el ZIP de trabajo entregado el 25/10 conservaba los datos facilitados. El repositorio publicable y los nuevos assets autónomos excluyen MMDB, copias de bases, estado, configuración privada y metadatos del IDE. Conservar los datos propios de la instalación y configurar sus rutas absolutas; una descarga nueva del código no proporciona una MMDB.

Si la instalación se gestiona desde el Composer de una aplicación superior, actualizar su dependencia de geoLocator y su propio lock; no crear un segundo árbol de dependencias innecesario dentro del paquete.

## Compatibilidad de la API

El contrato `MaxMindDownloadClientInterface` sigue exponiendo:

```php
public function latestReleaseAt(): ?DateTimeImmutable;
public function downloadTo(string $targetPath): void;
```

Las aplicaciones que construyen el actualizador con `GeoLocatorFactory::createUpdater($config)` mantienen ese punto de entrada. La configuración existente `MAXMIND_HTTP_TIMEOUT_SECONDS` y `MAXMIND_HTTP_MAX_ATTEMPTS` se conserva.

El constructor de `MaxMindHttpDownloadClient` recibe ahora un cliente de xcurl:

```php
use AbsolutOffice\XCurl\Client\HttpClient;
use AbsolutOffice\XCurl\Client\RetryPolicy;
use AbsolutOffice\XCurl\HttpTransportFactory;
use GeoLocator\Update\MaxMindHttpDownloadClient;

$http = new HttpClient(
    HttpTransportFactory::create(),
    new RetryPolicy(maxRetries: $maxAttempts - 1),
);

$downloadClient = new MaxMindHttpDownloadClient(
    accountId: $accountId,
    licenseKey: $licenseKey,
    client: $http,
    timeoutSeconds: 30,
);
```

Ya no acepta los argumentos `transport`, `retryPolicy` ni `sleeper` de GeoLocator. Los dobles de prueba se inyectan en `HttpClient` mediante los contratos de xcurl. El wrapper obsoleto `NativeHttpMaxMindDownloadClient` conserva su constructor de conveniencia y utiliza internamente xcurl.

Las clases de `GeoLocator\Update\Http` se han retirado. `GeoLocator\Http`, que gestiona la petición entrante, la IP del visitante y las cookies, pertenece a otra función de la biblioteca.

## Comportamiento de las peticiones

| Aspecto | Comportamiento |
| --- | --- |
| Selección de backend | cURL, después streams HTTPS, después socket TLS según capacidades disponibles |
| Error del backend elegido | No provoca un cambio automático a otro backend |
| Intentos HTTP | `httpMaxAttempts` cuenta intentos totales; se transforma en `maxRetries = attempts - 1` |
| Reintentos predeterminados | GET/HEAD, errores transitorios, HTTP 408/429/5xx, esperas de 1 s y 3 s |
| `Retry-After` | Límite de 60 s |
| Redirecciones | Hasta cinco; HTTPS; retirada de credenciales al cambiar de origen |
| Reintento después de redirección | Se reintenta la URL actual, incluida una URL firmada del almacenamiento |
| HTTP final fallido | El adaptador lo convierte en `UpdateException`, aunque xcurl devuelva una respuesta normal |
| Integridad | SHA-256 obligatorio; una repetición completa por archivo vacío, tamaño o checksum incorrecto |
| Destino de descarga | Ruta única inexistente; se rechazan archivos previos, directorios y enlaces simbólicos |
| Instalación MMDB | Solo después de validar el archivo; reemplazo atómico bajo bloqueo |

El reintento HTTP y la repetición por integridad son dos mecanismos distintos. No envolver `HttpClient` en otro bucle genérico de reintentos: multiplicaría el número de peticiones.

El timeout sigue siendo el del transporte. Los backends no tienen una semántica idéntica de tiempo total; la suma de redirecciones, reintentos, esperas y comprobaciones puede superar ese valor. Esta migración no añade un deadline global ni un límite fijo de tamaño que pudiera impedir futuras bases MaxMind más grandes.

El historial técnico de xcurl puede contener URLs firmadas. Evitar registrar sin filtrar URLs completas, cabeceras de autorización y cadenas completas de excepciones en interfaces públicas.

## PHP y seguridad: situación comprobada el 25/10/2026

PHP publicó 8.5.12 el 21/10/2026 como actualización de seguridad. Corrige CVE-2026-109618 y CVE-2026-109619. Otras ramas mantenidas también recibieron parches; el mínimo 8.5.12 es la decisión de plataforma de este proyecto, además de ser el requisito de xcurl 1.1.0.

El aviso adicional del 23/10/2026 describe un vector pendiente bajo una combinación concreta: uso de `php_binary` o `php_serialize` en configuración, cambio a `php` con `ini_set()` antes de `session_start()` y seguimiento de subidas de sesión activado. PHP recomienda mantener desactivado ese seguimiento hasta la siguiente corrección:

```ini
session.upload_progress.enabled=0
```

Aplicar la configuración en el `php.ini` o la configuración efectiva del SAPI antes del procesamiento de peticiones, y reiniciar los workers cuando corresponda. No confiar en añadir `ini_set()` a geoLocator para proteger una fase anterior a la ejecución de la aplicación. La biblioteca no modifica por sí misma la configuración global del servidor.

La política de mantenimiento es instalar los siguientes parches estables de PHP tras ejecutar las comprobaciones de la aplicación. Si una nueva corrección hace necesario elevar el mínimo obligatorio, actualizar `composer.json` y el lock mediante Composer y volver a validar; no editar el lock manualmente ni utilizar `--ignore-platform-reqs`.

Fuentes primarias consultadas:

- [PHP 8.5.12: anuncio](https://www.php.net/releases/8_5_12.php).
- [PHP: avisos oficiales del 21/10/2026 y 23/10/2026](https://www.php.net/archive/2026.php).
- [xcurl 1.1.0: manifiesto Composer](https://github.com/absolutoffice/xcurl/blob/v1.1.0/composer.json).
- [xcurl 1.1.0: cliente HTTP](https://github.com/absolutoffice/xcurl/blob/v1.1.0/src/Client/HttpClient.php).
- [MaxMind: actualización de bases y redirecciones R2](https://dev.maxmind.com/geoip/updating-databases/).

## Evidencias de verificación de la migración del 25/10/2026

Las comprobaciones se han ejecutado con **PHP 8.5.12 CLI NTS**, compilado desde el archivo oficial y con SHA-256 contrastado con php.net, y **Composer 2.10.3**. El runtime utilizado para estas pruebas no se incluye en el archivo ni representa una actualización de vuestros servidores.

| Comprobación | Resultado observado |
| --- | --- |
| `composer validate --strict` | Correcto |
| Requisitos reales de plataforma, desarrollo y producción | Correctos en PHP 8.5.12 |
| Sintaxis PHP de producción, configuración y pruebas | 82 archivos, 0 errores; incluida la configuración local de pruebas |
| Suite normal | 10/10 archivos de pruebas, 0 fallos; backend seleccionado cURL |
| Suite con `curl_exec` deshabilitado y `allow_url_fopen=0` | 10/10, 0 fallos; backend seleccionado socket |
| PHPStan 2.2.14, `src`, nivel máximo | 0 errores; ejecución secuencial en el entorno de validación |
| PHPCS 4.0.4, reglas `phpcs.xml.dist`, `src` y `tests` | 0 infracciones |
| Suite con el `vendor/` final de producción (`--no-dev`) | 10/10, 0 fallos |
| Dependencia xcurl ausente, copia aislada | Error explícito para la suite completa; pruebas propias independientes funcionan |
| Resolución Composer | Solo incorpora xcurl; las versiones y metadatos de los paquetes ya bloqueados se conservan |

Además, se probó el adaptador real contra un servidor HTTPS local, con datos y credenciales sintéticos. Para **cURL, streams y socket**, se verificaron HEAD y redirección, descarga a fichero de **2.097.184 bytes**, respuesta de checksum y SHA-256 correcto. El servidor comprobó que Basic Authentication aparecía en las entradas de archivo/checksum y desaparecía en los destinos redirigidos: 15 peticiones HTTP en total. Los tres backends rechazaron el certificado al retirar la CA de confianza. El fixture y sus claves fueron temporales.

Los escenarios de regresión incluyen HTTP 401/403/429/503, `Retry-After`, error transitorio, archivo parcial, checksum incorrecto o mal formado, fallo al obtener el checksum, destinos existentes, symlinks, archivo no declarado como propio y sustitución de la identidad de un temporal. Los errores públicos conservan un mensaje filtrado y su excepción original para diagnóstico privado.

La validación histórica del README describe el transporte anterior. La validación de esta entrega con HTTPS local tampoco sustituye la comprobación del hosting real, su conectividad, su configuración de certificados y la descarga autenticada de MaxMind.

Comandos reproducibles con dependencias de desarrollo:

```bash
composer install
composer validate --strict
composer check-platform-reqs
php tests/index.php
vendor/bin/phpstan analyse src --level=max --memory-limit=512M
vendor/bin/phpcs --standard=phpcs.xml.dist src tests

# Selección de sockets en un entorno restringido; el runner propaga estos ajustes.
php -d disable_functions=curl_exec -d allow_url_fopen=0 tests/index.php
```

La descarga autenticada contra MaxMind y la configuración PHP-FPM/Apache del hosting deben verificarse en el despliegue, con las credenciales gestionadas por la aplicación. Las pruebas automáticas no necesitan solicitar ni exponer esas credenciales.

## Revisión de PHPDoc — 25/10/2026

Se ha completado la documentación PHPDoc de todo el código propio de producción, además de los dobles de prueba, funciones auxiliares y funciones del panel. Los comentarios mantienen el inglés utilizado en el código. La revisión incluye responsabilidades, significado y unidades de parámetros, resultados y valores nulos, formas de arrays, firmas de callbacks, precondiciones, efectos sobre archivos/cookies y excepciones que pueden propagarse.

La auditoría de declaraciones propias de `src/GeoLocator/` da este resultado:

| Elemento | Total | Con PHPDoc descriptivo |
| --- | ---: | ---: |
| Clases | 53 | 53 |
| Interfaces | 10 | 10 |
| Enums | 5 | 5 |
| Métodos declarados, incluidos constructores y privados | 205 | 205 |
| Propiedades, incluidas las promovidas en constructores | 118 | 118 |
| Constantes | 10 | 10 |
| Casos de enum | 17 | 17 |

En esa revisión quedaron documentadas las **43 funciones y métodos con nombre que entonces había en `tests/`**. Los miembros heredados de PHP o de dependencias, los miembros que PHP genera para enums y las closures no forman parte de estos recuentos.

La revisión PHPDoc del 25/10/2026 modificó comentarios en **81 archivos PHP** y amplió README, CHANGELOG y esta guía. La comparación de tokens de los **82 archivos PHP** de aquella entrega, ignorando únicamente comentarios y espacios, confirmó **cero cambios de código ejecutable**. Aquella revisión conservó la configuración, dependencias y datos de su archivo de partida. Estos recuentos son la evidencia de esa revisión, anterior a los ejemplos y herramientas de distribución añadidos el 26/10/2026.

Validación de esta revisión: **82/82 archivos sin errores de sintaxis**, **10/10 archivos de pruebas correctos**, **PHPStan nivel máximo sin errores** y **PHPCS sin infracciones**. La cobertura mide la presencia de documentación y se ha complementado con revisión del contenido contra las implementaciones; no sustituye la revisión de futuros cambios de comportamiento.

## Distribución Composer/GitHub — 26/10/2026

Se conserva la API documentada y el mínimo de PHP acordado. La preparación incorpora un árbol de fuente publicable, ejemplos de integración, comprobaciones del autoloader consumidor y construcción de un ZIP autónomo con dependencias de producción. La identidad Composer sigue siendo `absolutoffice/geo-locator`; no se ha publicado un tag ni registrado el paquete en Packagist como parte de esta preparación.

La configuración recomendada para las nuevas instalaciones usa rutas absolutas de la aplicación, por ejemplo `/srv/my-app/var/geoip/GeoLite2-City.mmdb` y `/srv/my-app/var/geoip/.state`. Las rutas relativas existentes conservan su resolución desde la raíz del paquete. La aplicación elige y suministra su archivo de entorno explícitamente.

La instalación y publicación se describen en [INSTALLATION.md](docs/INSTALLATION.md) y [RELEASING.md](docs/RELEASING.md). Las evidencias anteriores siguen correspondiendo a la migración y revisión PHPDoc de 25/10/2026; las comprobaciones de distribución deben ejecutarse sobre el artefacto que se vaya a publicar.
