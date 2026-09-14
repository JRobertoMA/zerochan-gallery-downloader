# Zerochan Gallery

Galería web y descargador por lotes sobre la [API pública de Zerochan](https://www.zerochan.net/api).
Busca ilustraciones por tag, filtra por orientación, color, popularidad, resolución y contenido sugerente,
míralas en grande y descárgalas en tamaño completo — una a una o cientos en segundo plano — con sus metadatos.

PHP 8 sin framework ni dependencias externas, frontend vanilla, índice local en SQLite.

## Características

- **Explorador**: tags combinables (`Bleach, Ichigo Kurosaki`), orden por recientes o populares (siempre / semana / día),
  filtro por dimensiones (`large`, `huge`, horizontal, vertical, cuadrada), color dominante, modo `strict`.
- **Filtros locales** que la API no ofrece: contenido sugerente (`Todo / Sin Ecchi / Solo Ecchi`) y resolución mínima
  (lado corto en px).
- **Modal** con la imagen en grande, tags clicables (Shift+clic para acumular), fuente original, número de variantes y
  descarga directa del `full`.
- **Descargas en segundo plano**: un worker independiente de Apache procesa la cola; cierra la pestaña y sigue.
  Progreso por imagen (bytes), cancelar, reintentar fallidos, reanudación automática si el proceso muere.
- **Biblioteca local** indexada en SQLite: búsqueda por texto, facetas de tags, orden por fecha/tamaño, detección de
  duplicados por md5 y marcado de "ya descargada" en los resultados de la API.
- **Respeta el rate limit** de Zerochan (60 req/min) con un limitador compartido entre web, CLI y worker; caché de
  respuestas JSON; reintentos con backoff; corte de descargas por velocidad mínima (el CDN a veces sirve a ~50 KB/s).
- **CLI** para scripts y cron; **log** propio consultable desde la interfaz.

## Requisitos

- Apache + PHP ≥ 8.1 con `curl`, `pdo_sqlite`, `posix` (todos vienen en la imagen oficial `php:*-apache`).
- `AllowOverride All` (el proyecto usa `.htaccess` para proteger `.env`, `data/`, `logs/`…).
- Una cuenta de Zerochan: la API exige un `User-Agent` con el nombre del proyecto y tu usuario.

## Instalación

```bash
git clone https://github.com/JRobertoMA/zerochan-gallery-downloader.git zerochan-gallery-downloader
cd zerochan-gallery-downloader
cp .env.example .env
```

Edita `.env`:

```ini
ZEROCHAN_USERNAME=TuUsuario
ZEROCHAN_APP_NAME=zerochan-gallery-downloader

; Opcional: sesión del navegador. Sin ella, Zerochan oculta a los invitados algunas entradas
; y redirige los meta tags (Ecchi, Nude…). Entre comillas porque lleva ';'.
ZEROCHAN_COOKIE="z_id=123456; z_hash=abcdef0123456789abcdef0123456789"
```

`z_id` y `z_hash` se obtienen de las cookies del navegador con sesión iniciada en zerochan.net (DevTools →
Storage/Application → Cookies). Duran 90 días. `z_hash` equivale a tu contraseña para esa sesión: no lo compartas.

Los directorios `cache/`, `downloads/`, `data/` y `logs/` deben ser escribibles por el usuario de Apache
(`www-data`). Se crean solos si no existen.

Abre `http://<host>/zerochan-gallery-downloader/`.

## Uso

### Interfaz web

| Pestaña | Qué hace |
|---|---|
| **Explorar** | Busca en Zerochan. `⬇` en cada tarjeta descarga el `full`; `✓` ya está en local; `≈` es la misma imagen que otra ya descargada. "Descargar todo…" encola un lote desde la página actual con los filtros activos. |
| **Descargados** | Tu biblioteca local. Buscador, facetas de tags, orden. Clic abre el fichero. |
| **Log** | Últimas líneas de `logs/zerochan.log` (reintentos, fallos, jobs). |
| Botón **Descargas** | Panel con los jobs en curso y terminados. |

### Línea de comandos

Ejecuta siempre como el usuario de Apache (en Docker: `docker exec -u www-data <contenedor> php ...`).

```bash
# Encola un lote y lanza el worker en segundo plano
php cli/download.php "Bleach" --max=100 --d=landscape --min=1080 --ecchi=hide

# Lo mismo, pero síncrono con salida en consola (útil en cron)
php cli/download.php "Undertale" --max=20 --s=fav --t=1 --sync

# Una sola imagen por id
php cli/download.php --id=3793685

# Estado de la cola
php cli/download.php --status

# Procesar la cola manualmente (p. ej. desde cron como red de seguridad)
php cli/worker.php

# Reconstruir el índice SQLite desde los .json de downloads/
php cli/reindex.php
```

Opciones de lote: `--d=large|huge|landscape|portrait|square`, `--s=id|fav`, `--t=0|1|2`, `--c=<color>`, `--p=<página>`,
`--strict`, `--ecchi=all|hide|only`, `--min=<px>`, `--max=<n>` (tope 500).

### Estructura de las descargas

```
downloads/
└── fiolina-germi/            ← slug del tag principal
    ├── 3060235.jpg           ← imagen full, md5 verificado
    └── 3060235.json          ← metadatos: tags, dimensiones, fuente, hash, fecha de descarga
```

## API interna

Todos los endpoints devuelven JSON y viven en `api/`:

| Endpoint | Método | Descripción |
|---|---|---|
| `search.php?tags=&p=&l=&s=&t=&d=&c=&strict=&ecchi=&min=` | GET | Listado de Zerochan con filtros; cada item lleva `saved` y `dup_of`. `raw_count` = items antes de filtros locales. |
| `item.php?id=` | GET | Detalle de una imagen (`small/medium/large/full`, tags, `children`). |
| `download.php` | POST `id` | Descarga síncrona de una imagen. |
| `jobs.php` | GET / GET `?id=` | Lista de jobs / detalle. |
| `jobs.php` | POST `action=create&tags=&max=…` | Encola un lote y lanza el worker. También `cancel`, `retry`, `clear`. |
| `downloads.php?tag=&q=&sort=&p=&l=` | GET | Biblioteca local desde el índice, con facetas y totales. |
| `log.php?n=` | GET | Últimas líneas del log. |

## Cómo funciona

```
navegador ──► api/search.php ──► ZerochanClient ──► www.zerochan.net (JSON, caché 10 min, rate limit)
    │                                   │
    │  POST action=create               └── Index (SQLite) ──► saved / dup_of
    ▼
api/jobs.php ──► data/jobs/<id>.json ──► setsid nohup php cli/worker.php
                                              │
                        polling cada 2 s      ├── Downloader::collectIds()  (pagina + filtros locales)
    ◄─────────────────────────────────────────┤
                                              └── Downloader::one()  → static.zerochan.net → downloads/ + Index
```

- Los lotes no corren dentro de una petición HTTP: en un Apache compartido (`mpm_prefork`) un lote de 250 imágenes
  bloquearía un worker durante minutos y moriría al cerrar la pestaña. El worker es un proceso PHP CLI aparte con
  `flock` para que solo haya uno; el estado vive en fichero y sobrevive a reinicios.
- El listado de Zerochan no incluye la URL `full`, así que cada descarga cuesta una petición de detalle más el binario:
  unas 25–30 imágenes/min dentro del límite de 60 req/min.
- Zerochan no tiene parámetro de rating. Lo sugerente lleva el tag `Ecchi`; el filtro se aplica localmente sobre `tags`.

## Notas sobre la API de Zerochan (no documentadas)

- Los invitados ven menos resultados que los usuarios con sesión; los meta tags (`Ecchi`, `Nude`) como único tag
  responden 302 sin sesión. De ahí la cookie opcional.
- El detalle incluye `children` (número de variantes). El listado incluye `md5`, igual al `hash` del detalle.
- `static.zerochan.net` limita la velocidad a veces; las descargas se cortan solo si bajan de 1 KB/s durante 60 s.

## Licencia

Uso personal. Las imágenes pertenecen a sus autores; respeta los términos de Zerochan y las fuentes originales.
