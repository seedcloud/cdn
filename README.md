# seedcloud CDN

Módulo de plataforma de seedcloud para sitios Drupal servidos por BunnyCDN. Hace que los cambios de contenido se
vean en segundos en vez de esperar a que venza el `max-age` de la página en Bunny.

## Cómo funciona

1. **Cabecera `CDN-Tag`.** Cada respuesta cacheable (`Cache-Control: public`) lleva sus cache tags de Drupal
   convertidas a `<prefijo>-<hash>`, donde el prefijo identifica al proyecto y ambiente de Lagoon. Bunny lee
   hasta 1.024 bytes: las tags de contenido van primero y, si no caben todas, la respuesta se marca con
   `<prefijo>-ovf` (se purga en cada lote, así nunca queda contenido viejo).
2. **Purgado.** Las tags que Drupal invalida en una solicitud (o en un comando de Drush) se envían en un solo
   lote, al terminar, al proxy `https://cdn-purge.seedcloud.net/purge-tags` con el `CDN_PURGE_TOKEN` del
   proyecto. El proxy valida que el ambiente sea del proyecto, antepone el prefijo y purga en la pull zone.
   Invalidar `rendered` o `library_info` purga todo el ambiente (`<prefijo>-all`).

El sitio nunca tiene la llave de Bunny: solo su token, que únicamente purga sus propias tags.

## Instalación

```bash
composer require seedcloud/seedcloud_cdn
drush en seedcloud_cdn -y
```

Requiere en el ambiente las variables de Lagoon `LAGOON_PROJECT`, `LAGOON_ENVIRONMENT` y `CDN_PURGE_TOKEN`.
Sin alguna de ellas (por ejemplo en un entorno local) el módulo no hace nada. `SEEDCLOUD_CDN_PURGE_URL` cambia
el endpoint del proxy (pruebas).

## Verificar

```bash
curl -sI https://<sitio>/<página-cacheable> | grep -i cdn-tag   # debe listar <prefijo>-all,...
```

Al guardar un nodo, el registro de Drupal (`seedcloud_cdn`) solo escribe si el purgado falla.

## Compatibilidad

Drupal 10.1+ y 11, PHP 8.1+. Mantener `short_hash` del proxy (`cdk/lambda/cdn-purge/index.py`) y
`TagHasher::shortHash` iguales: las pruebas de ambos lados comparten valores de referencia.
