# Publicación de geoLocator en GitHub

Preparación de distribución: **28/10/2026**.

Este procedimiento describe cómo publicar el código como paquete Composer y producir una descarga autónoma. El árbol fuente queda asignado a `1.0.0` mediante `VERSION`, pero la preparación local no crea por sí sola el repositorio remoto, el tag `v1.0.0`, la GitHub Release ni una entrada de Packagist. La ubicación final prevista es `https://github.com/absolutoffice/geo-locator`.

## Qué se publica

| Elemento | Repositorio / fuente | ZIP autónomo de la Release |
| --- | --- | --- |
| Código y PHPDoc | Sí | Sí |
| Manifiesto y lock de Composer | Sí | Sí |
| Documentación y ejemplos sin credenciales | Sí | Sí |
| Pruebas y herramientas de mantenimiento | Sí | Pruebas y herramientas incluidas por el builder |
| Workflows de GitHub | En el repositorio | No |
| Dependencias de producción | Instaladas por Composer | Incluidas en `vendor/` |
| Herramientas Composer de desarrollo | Instaladas con `composer install` | No |
| MMDB, copias de bases, credenciales y estado local | No | No |

`composer.lock` se conserva para reproducir las pruebas y la construcción de la descarga autónoma. Cuando otra aplicación requiere la librería, Composer resuelve las dependencias con el lock de esa aplicación, no con el de geoLocator.

La construcción conserva las dependencias fijadas y sus archivos de licencia; los timestamps del ZIP pueden variar entre ejecuciones. [THIRD-PARTY-NOTICES.md](../THIRD-PARTY-NOTICES.md) identifica los componentes distribuidos. La suma publicada corresponde al archivo concreto adjuntado a cada Release.

La versión que Composer instala se obtiene de las ramas y tags de Git. No mantener un campo `version` manual en `composer.json`. El archivo `VERSION` identifica la versión estable preparada por el árbol fuente y el builder/workflow comprueban que coincida con el tag y el nombre del artefacto.

## 1. Validar el checkout

Utilizar un checkout limpio y un PHP compatible. Instalar las dependencias de desarrollo y ejecutar las comprobaciones del proyecto:

```bash
composer install --prefer-dist --no-interaction
composer validate --strict
composer check-platform-reqs
composer test
composer lint
composer analyse
composer cs
```

Para verificar también la selección de sockets en un entorno restringido:

```bash
php -d disable_functions=curl_exec -d allow_url_fopen=0 tests/index.php
```

Las pruebas usan colaboradores sintéticos y archivos temporales; no necesitan credenciales reales de MaxMind. El entorno de producción sigue necesitando su propia comprobación de permisos, conectividad y certificados cuando se active el actualizador.

## 2. Preparar el repositorio

Conservar la identidad Composer `absolutoffice/geo-locator` y el namespace `GeoLocator\`. Antes de publicar, confirmar la URL real de GitHub y ajustar las URLs de documentación o metadatos si se elige otra ubicación.

Los filtros de Git y de distribución excluyen dependencias instaladas, archivos de entorno reales, configuración local de pruebas, bases MMDB, estado de actualización, archivos del IDE y salidas generadas. Revisar el conjunto que se va a publicar antes de crear el commit:

```bash
git init -b main
git add .
git diff --cached --stat
git diff --cached --name-only
git commit -m "Prepare GeoLocator Composer and standalone distribution"
```

Estos comandos se aplican a un directorio que aún no sea un repositorio. Si ya existe uno, trabajar en su historial y rama correspondientes.

Después de crear o seleccionar el repositorio GitHub y autenticar Git con la cuenta adecuada:

```bash
git remote add origin https://github.com/absolutoffice/geo-locator.git
git push -u origin main
```

Si `origin` ya existe, comprobar su URL antes de cambiarlo. A partir de la rama publicada, la instalación VCS con `dev-main` puede comprobarse en una aplicación temporal usando los comandos de [INSTALLATION.md](INSTALLATION.md).

## 3. Preparar y probar la versión

La preparación actual fija `VERSION` y `CHANGELOG.md` en `1.0.0`. Para una versión futura, actualizar ambos antes de construir el candidato. La migración anterior elevó el mínimo de PHP y cambió el constructor público de `MaxMindHttpDownloadClient`; esos cambios deben seguir reflejados en el versionado.

El builder permite preparar el candidato local antes de crear el tag:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-scripts --no-interaction
composer check-platform-reqs --no-dev
php tests/index.php
php tools/build-release.php --version=1.0.0 --output=dist
```

Para una release estable, `--version` debe coincidir exactamente con `VERSION`; `dev-main` queda reservado para previews de desarrollo. La construcción requiere que `vendor/` corresponda a las dependencias de producción del lock. No usar una instalación con herramientas de desarrollo ni modificar manualmente los metadatos Composer.

El builder escribe:

- `dist/geo-locator-1.0.0-standalone.zip`.
- `dist/SHA256SUMS`.

El ZIP tiene una única carpeta superior `geo-locator/`. Comprobar su suma y extraerlo en un directorio separado para probar la instalación entregable:

```bash
cd dist
sha256sum --check SHA256SUMS
```

Desde la carpeta extraída, ejecutar `php tests/index.php` y el ejemplo de consulta con una configuración privada externa. Revisar que el manifiesto del archivo no contenga datos de la instalación desde la que se construyó.

Para volver a trabajar con las herramientas de desarrollo en el checkout, ejecutar `composer install` sin `--no-dev`.

## 4. Publicar un tag y la Release

Una vez revisado el commit de la versión y con CI correcto, crear y subir el tag que corresponda a `VERSION`:

```bash
git tag -a v1.0.0 -m "GeoLocator 1.0.0"
git push origin v1.0.0
```

El workflow de Release utiliza el repositorio que lo ejecuta y el tag recibido, comprueba que el tag sea SemVer y coincida con `VERSION`, ejecuta las comprobaciones del proyecto, construye el paquete autónomo y adjunta el ZIP y las sumas a la Release. Debe disponer del permiso de escritura de contenido requerido por esa publicación. El workflow no configura servidores consumidores ni descarga una base MaxMind con credenciales privadas.

GitHub ofrece además sus archivos automáticos de código fuente para el tag. Esos archivos no incluyen `vendor/`; el asset `*-standalone.zip` es el que permite instalar sin ejecutar Composer en el servidor de destino.

Comprobar después de publicar:

- [ ] El tag identifica exactamente el commit validado.
- [ ] La Release contiene el ZIP autónomo y `SHA256SUMS`.
- [ ] El enlace de descarga corresponde al asset construido y su suma coincide.
- [ ] Una aplicación vacía puede instalar el tag mediante el repositorio VCS de Composer.
- [ ] El ZIP extraído ejecuta sus pruebas con el PHP objetivo.
- [ ] README y changelog describen la versión realmente publicada.

## 5. Registrar en Packagist, si se desea

Registrar la URL real del repositorio en Packagist y activar su actualización desde GitHub. Esperar a que aparezca la versión publicada y comprobar una instalación sin la entrada VCS antes de anunciar ese comando como disponible.

La ruta de instalación VCS sigue siendo válida si se decide no utilizar Packagist. No es necesario registrar geoLocator en Packagist para que Composer lo instale desde GitHub.

## Referencias

- [Composer: creación, versionado y publicación de librerías](https://getcomposer.org/doc/02-libraries.md).
- [Composer: repositorios VCS](https://getcomposer.org/doc/05-repositories.md).
- [GitHub: administración de Releases](https://docs.github.com/en/repositories/releasing-projects-on-github/managing-releases-in-a-repository).
- [GitHub: archivos de código fuente descargables](https://docs.github.com/en/repositories/working-with-files/using-files/downloading-source-code-archives).
