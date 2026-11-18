#!/usr/bin/env python3
"""Sistema de alarmas: busca noticias y anuncios por temáticas y avisa solo de lo nuevo.

Uso:
    python alarmas/alarmas.py                    # ejecuta las búsquedas que toca según su frecuencia
    python alarmas/alarmas.py --forzar           # ejecuta todas las búsquedas activas ya
    python alarmas/alarmas.py --tema Subastas    # solo una temática
"""
import argparse
import hashlib
import re
import sqlite3
import sys
import unicodedata
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
from datetime import datetime, timedelta, timezone
from email.utils import parsedate_to_datetime
from pathlib import Path

import yaml

AQUI = Path(__file__).resolve().parent
CONFIG_POR_DEFECTO = AQUI / "temas.yaml"
DB_POR_DEFECTO = AQUI / "datos" / "alarmas.db"
SALIDA_POR_DEFECTO = AQUI / "salida"

UNIDADES = {"h": "hours", "d": "days", "w": "weeks"}


def normalizar(texto):
    """Minúsculas y sin tildes, para que 'Subasta' encaje con 'subásta'."""
    texto = unicodedata.normalize("NFKD", texto or "")
    return "".join(c for c in texto if not unicodedata.combining(c)).lower()


def frecuencia_a_delta(valor):
    """Convierte '6h', '1d' o '1w' en un timedelta."""
    match = re.fullmatch(r"(\d+)([hdw])", str(valor).strip())
    if not match:
        raise ValueError(f"Frecuencia no válida: {valor!r} (usa 6h, 1d, 1w...)")
    return timedelta(**{UNIDADES[match.group(2)]: int(match.group(1))})


def url_google_news(consulta):
    params = {"q": consulta, "hl": "es", "gl": "ES", "ceid": "ES:es"}
    return "https://news.google.com/rss/search?" + urllib.parse.urlencode(params)


def descargar(url):
    peticion = urllib.request.Request(url, headers={"User-Agent": "alarmas-local/1.0"})
    with urllib.request.urlopen(peticion, timeout=30) as respuesta:
        return respuesta.read()


def leer_rss(contenido):
    """Extrae los items de un feed RSS 2.0 (también sirve el de Google News)."""
    raiz = ET.fromstring(contenido)
    items = []
    for item in raiz.iter("item"):
        resumen = re.sub(r"<[^>]+>", " ", item.findtext("description") or "")
        publicado = None
        fecha = item.findtext("pubDate")
        if fecha:
            try:
                publicado = parsedate_to_datetime(fecha)
                if publicado.tzinfo is None:
                    publicado = publicado.replace(tzinfo=timezone.utc)
            except (TypeError, ValueError):
                publicado = None
        items.append({
            "titulo": (item.findtext("title") or "").strip(),
            "enlace": (item.findtext("link") or "").strip(),
            "resumen": " ".join(resumen.split()),
            "fuente": (item.findtext("source") or "").strip(),
            "publicado": publicado,
        })
    return items


def cumple_filtros(item, busqueda):
    """incluir: basta una palabra. excluir: ninguna palabra puede aparecer."""
    texto = normalizar(f"{item['titulo']} {item['resumen']}")
    incluir = [normalizar(t) for t in busqueda.get("incluir", [])]
    excluir = [normalizar(t) for t in busqueda.get("excluir", [])]
    if incluir and not any(t in texto for t in incluir):
        return False
    return not any(t in texto for t in excluir)


def es_demasiado_antiguo(item, max_dias, ahora):
    if max_dias is None or item["publicado"] is None:
        return False
    return ahora - item["publicado"] > timedelta(days=max_dias)


def abrir_base(ruta):
    ruta.parent.mkdir(parents=True, exist_ok=True)
    con = sqlite3.connect(ruta)
    con.executescript("""
        CREATE TABLE IF NOT EXISTS vistos (
            busqueda TEXT NOT NULL,
            clave TEXT NOT NULL,
            titulo TEXT,
            enlace TEXT,
            primera_vez TEXT NOT NULL,
            PRIMARY KEY (busqueda, clave)
        );
        CREATE TABLE IF NOT EXISTS ejecuciones (
            busqueda TEXT PRIMARY KEY,
            ultima TEXT NOT NULL
        );
    """)
    return con


def toca_ejecutar(con, identificador, frecuencia, ahora, forzar):
    if forzar:
        return True
    fila = con.execute("SELECT ultima FROM ejecuciones WHERE busqueda = ?", (identificador,)).fetchone()
    if fila is None:
        return True
    return ahora - datetime.fromisoformat(fila[0]) >= frecuencia_a_delta(frecuencia)


def ejecutar_busqueda(con, tema, busqueda, ahora, forzar=False, descargar_fn=descargar):
    """Devuelve los items nuevos, [] si no toca o nada nuevo, o None si hubo error de red."""
    identificador = f"{tema['nombre']}/{busqueda['nombre']}"
    frecuencia = busqueda.get("frecuencia", tema.get("frecuencia", "1d"))
    if not toca_ejecutar(con, identificador, frecuencia, ahora, forzar):
        return []

    if busqueda["fuente"] == "google_news":
        url = url_google_news(busqueda["consulta"])
    elif busqueda["fuente"] == "rss":
        url = busqueda["url"]
    else:
        raise ValueError(f"Fuente desconocida en '{identificador}': {busqueda['fuente']}")

    try:
        items = leer_rss(descargar_fn(url))
    except Exception as error:  # red caída, feed roto...
        print(f"[error] {identificador}: {error}", file=sys.stderr)
        return None

    nuevos = []
    max_dias = busqueda.get("antiguedad_max_dias")
    for item in items:
        if not cumple_filtros(item, busqueda) or es_demasiado_antiguo(item, max_dias, ahora):
            continue
        clave = hashlib.sha256((item["enlace"] or item["titulo"]).encode("utf-8")).hexdigest()
        cursor = con.execute(
            "INSERT OR IGNORE INTO vistos (busqueda, clave, titulo, enlace, primera_vez) VALUES (?, ?, ?, ?, ?)",
            (identificador, clave, item["titulo"], item["enlace"], ahora.isoformat()),
        )
        if cursor.rowcount:
            nuevos.append(item)

    # Solo se marca como ejecutada si la descarga funcionó; si falla, se reintenta en la siguiente pasada.
    con.execute(
        "INSERT OR REPLACE INTO ejecuciones (busqueda, ultima) VALUES (?, ?)",
        (identificador, ahora.isoformat()),
    )
    con.commit()
    return nuevos


def escribir_resumen(carpeta, ahora, resultados):
    """Añade los nuevos al resumen del día (un fichero .md por día)."""
    carpeta.mkdir(parents=True, exist_ok=True)
    ruta = carpeta / f"{ahora:%Y-%m-%d}.md"
    lineas = [f"# Alarmas {ahora:%Y-%m-%d %H:%M} UTC", ""]
    for tema, bloques in resultados.items():
        lineas.append(f"## {tema}")
        lineas.append("")
        for busqueda, items in bloques:
            prioridad = busqueda.get("prioridad", "media")
            lineas.append(f"### {busqueda['nombre']} — {len(items)} nuevos (prioridad {prioridad})")
            for item in items:
                fecha = item["publicado"].strftime("%Y-%m-%d") if item["publicado"] else "sin fecha"
                origen = f" · {item['fuente']}" if item["fuente"] else ""
                lineas.append(f"- [{item['titulo']}]({item['enlace']}) ({fecha}{origen})")
            lineas.append("")
    with ruta.open("a", encoding="utf-8") as f:
        f.write("\n".join(lineas) + "\n")
    return ruta


def main(argv=None):
    parser = argparse.ArgumentParser(description="Alarmas de búsqueda por temáticas")
    parser.add_argument("--config", type=Path, default=CONFIG_POR_DEFECTO)
    parser.add_argument("--tema", action="append", help="limita la ejecución a esta temática (repetible)")
    parser.add_argument("--forzar", action="store_true", help="ignora la frecuencia y ejecuta ya")
    args = parser.parse_args(argv)

    config = yaml.safe_load(args.config.read_text(encoding="utf-8"))
    ahora = datetime.now(timezone.utc)
    con = abrir_base(DB_POR_DEFECTO)

    resultados = {}
    prioritarios = []
    for tema in config["temas"]:
        if args.tema and tema["nombre"] not in args.tema:
            continue
        for busqueda in tema["busquedas"]:
            if not busqueda.get("activa", True):
                continue
            nuevos = ejecutar_busqueda(con, tema, busqueda, ahora, forzar=args.forzar)
            if not nuevos:
                continue
            resultados.setdefault(tema["nombre"], []).append((busqueda, nuevos))
            if busqueda.get("prioridad") == "alta":
                prioritarios.append((tema["nombre"], busqueda["nombre"], nuevos))

    total = sum(len(items) for bloques in resultados.values() for _, items in bloques)
    if not resultados:
        print("Sin novedades.")
        return 0

    ruta = escribir_resumen(SALIDA_POR_DEFECTO, ahora, resultados)
    for tema, nombre, items in prioritarios:
        print(f"!! [{tema} / {nombre}] {len(items)} nuevos:")
        for item in items:
            print(f"   - {item['titulo']}\n     {item['enlace']}")
    print(f"{total} novedades guardadas en {ruta}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
