# Alarmas

Sistema de alertas por temáticas. Cada **temática** tiene **búsquedas** (las alertas), y cada búsqueda tiene una o varias **fuentes** de donde llega la información. El sistema solo avisa de lo nuevo.

Requisitos: Python 3.9+ y `pyyaml` (solo para importar `temas.yaml`).

```bash
pip install pyyaml
```

## Comandos

Todos aceptan `--db RUTA` (base de datos) y `--salida CARPETA` (resúmenes). Por defecto usan `alarmas/datos/` y `alarmas/salida/`.

### Temáticas

```bash
python3 alarmas/alarmas.py tema alta Subastas --frecuencia 1d
python3 alarmas/alarmas.py tema pausar Subastas
python3 alarmas/alarmas.py tema reanudar Subastas
python3 alarmas/alarmas.py tema baja Subastas          # borra también sus búsquedas y fuentes
```

### Búsquedas (alertas)

```bash
python3 alarmas/alarmas.py busqueda alta Subastas "Pisos Madrid" --prioridad alta \
    --incluir piso,vivienda --excluir coche --antiguedad 60 --frecuencia 6h
python3 alarmas/alarmas.py busqueda pausar Subastas "Pisos Madrid"
python3 alarmas/alarmas.py busqueda reanudar Subastas "Pisos Madrid"
python3 alarmas/alarmas.py busqueda baja Subastas "Pisos Madrid"
```

- `--prioridad alta`: además de en el resumen, sale por consola.
- `--incluir`: basta con que aparezca una de las palabras.
- `--excluir`: ninguna de las palabras puede aparecer.
- `--antiguedad N`: descarta resultados con más de N días (no aplica a fuentes `web`).
- `--frecuencia`: si no se indica, usa la de la temática.

### Fuentes (de dónde llega la información)

```bash
python3 alarmas/alarmas.py fuente alta Subastas "Pisos Madrid" google_news '"subasta" piso Madrid'
python3 alarmas/alarmas.py fuente alta Subastas "Pisos Madrid" rss https://ejemplo.com/feed.xml
python3 alarmas/alarmas.py fuente alta Subastas "Pisos Madrid" web https://portal.es/subastas --patron '/subasta/'
python3 alarmas/alarmas.py fuente pausar 7
python3 alarmas/alarmas.py fuente reanudar 7
python3 alarmas/alarmas.py fuente baja 7
```

Tipos:

- `google_news`: el valor es una consulta de Google News.
- `rss`: el valor es la URL de un feed RSS.
- `web`: el valor es una página de listados. Cada enlace con texto suficiente es un resultado, con su foto si la tiene.
- `--patron` (solo `web`): expresión regular que debe cumplir la URL de cada enlace. Es la forma de adaptar el scraper a cada sitio: por ejemplo `/subasta/` o `/lote/\d+`.

Cada fuente tiene un identificador numérico; úsalo para pausarla o borrarla.

### Ver y ejecutar

```bash
python3 alarmas/alarmas.py listar                 # temáticas, búsquedas y fuentes con sus ids
python3 alarmas/alarmas.py importar               # carga temas.yaml como semilla (no duplica)
python3 alarmas/alarmas.py ejecutar               # revisa lo que toque según su frecuencia
python3 alarmas/alarmas.py ejecutar --forzar      # revisa todo ya
python3 alarmas/alarmas.py ejecutar --tema Subastas
python3 alarmas/alarmas.py --forzar               # sin comando equivale a 'ejecutar'
```

## Salidas

- `alarmas/salida/AAAA-MM-DD.md`: resumen en texto, con enlaces, añadido día a día.
- `alarmas/salida/AAAA-MM-DD_HHMM.html`: página con tarjetas (foto, título, fuente, fecha, resumen) de cada ejecución que encuentra novedades.

Los resultados prioritarios también salen por consola.

## Programación diaria

El sistema no se programa solo. Ejemplo con cron, cada día a las 8:00:

```bash
0 8 * * * cd /ruta/al/repo && python3 alarmas/alarmas.py >> alarmas/salida/cron.log 2>&1
```

## Limitaciones

- Los sitios que cargan la lista con JavaScript no se leen: el scraper ve solo el HTML inicial.
- Algunos sitios bloquean descargas automáticas o exigen cookies; en ese caso la fuente dará error y se reintentará en la siguiente ejecución.
- Las fuentes `web` no tienen fecha de publicación, así que no se puede filtrar por antigüedad.
- Las fotos dependen de que el sitio ponga la imagen dentro del enlace o en el feed.
- El texto de las páginas web se decodifica como UTF-8.

## Pruebas

```bash
python3 -m unittest discover -s alarmas -p 'test_*.py'
```
