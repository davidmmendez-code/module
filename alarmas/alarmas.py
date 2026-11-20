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
from html import escape as esc_html
from html.parser import HTMLParser
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


def _imagen_rss(item, descripcion_bruta):
    """Busca la imagen de un item: media:content, media:thumbnail, enclosure o <img> del resumen."""
    for etiqueta in ("{http://search.yahoo.com/mrss/}content", "{http://search.yahoo.com/mrss/}thumbnail"):
        elemento = item.find(etiqueta)
        if elemento is not None and elemento.get("url"):
            return elemento.get("url")
    enlace = item.find("enclosure")
    if enlace is not None and (enlace.get("type") or "").startswith("image/"):
        return enlace.get("url")
    coincidencia = re.search(r'<img[^>]+src="([^"]+)"', descripcion_bruta)
    return coincidencia.group(1) if coincidencia else None


def leer_rss(contenido):
    """Extrae los items de un feed RSS 2.0 (también sirve el de Google News)."""
    raiz = ET.fromstring(contenido)
    items = []
    for item in raiz.iter("item"):
        bruto = item.findtext("description") or ""
        resumen = re.sub(r"<[^>]+>", " ", bruto)
        imagen = _imagen_rss(item, bruto)
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
            "imagen": imagen,
        })
    return items


class _Enlaces(HTMLParser):
    def __init__(self):
        super().__init__()
        self.enlaces = []  # tuplas (href, texto, imagen)
        self._ancla = None  # datos del <a> en el que estamos

    def handle_starttag(self, tag, attrs):
        datos = dict(attrs)
        if tag == "a":
            self._ancla = {"href": datos.get("href"), "title": datos.get("title") or "",
                           "imagen": None, "alt": "", "texto": []}
        elif tag == "img" and self._ancla is not None and self._ancla["imagen"] is None:
            self._ancla["imagen"] = datos.get("src")
            self._ancla["alt"] = datos.get("alt") or ""

    def handle_data(self, data):
        if self._ancla is not None:
            self._ancla["texto"].append(data)

    def handle_endtag(self, tag):
        if tag == "a" and self._ancla is not None:
            ancla = self._ancla
            texto = " ".join("".join(ancla["texto"]).split()) or ancla["alt"] or ancla["title"]
            self.enlaces.append((ancla["href"], texto, ancla["imagen"]))
            self._ancla = None


def leer_enlaces(contenido, base):
    """Extrae los enlaces de una página de listados. Cada enlace con texto suficiente es un item."""
    parser = _Enlaces()
    parser.feed(contenido.decode("utf-8", errors="replace"))
    items, vistos = [], set()
    dominio = urllib.parse.urlparse(base).netloc
    for href, texto, imagen in parser.enlaces:
        if not href or len(texto) < 15:  # descarta menús y enlaces sueltos
            continue
        absoluta = urllib.parse.urljoin(base, href)
        if not absoluta.startswith(("http://", "https://")) or absoluta in vistos:
            continue
        vistos.add(absoluta)
        items.append({
            "titulo": texto,
            "enlace": absoluta,
            "resumen": "",
            "fuente": dominio,
            "publicado": None,
            "imagen": urllib.parse.urljoin(base, imagen) if imagen else None,
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


def obtener_fuente(fuente, descargar_fn):
    """Descarga una fuente concreta (google_news, rss o web) y devuelve sus items."""
    if fuente["fuente"] == "google_news":
        return leer_rss(descargar_fn(url_google_news(fuente["consulta"])))
    if fuente["fuente"] == "rss":
        return leer_rss(descargar_fn(fuente["url"]))
    if fuente["fuente"] == "web":
        return leer_enlaces(descargar_fn(fuente["url"]), fuente["url"])
    raise ValueError(f"Fuente desconocida: {fuente['fuente']}")


def ejecutar_busqueda(con, tema, busqueda, ahora, forzar=False, descargar_fn=descargar):
    """Devuelve los items nuevos, [] si no toca o nada nuevo, o None si hubo error de red."""
    identificador = f"{tema['nombre']}/{busqueda['nombre']}"
    frecuencia = busqueda.get("frecuencia", tema.get("frecuencia", "1d"))
    if not toca_ejecutar(con, identificador, frecuencia, ahora, forzar):
        return []

    # Una búsqueda puede tener varias fuentes en 'fuentes:'; si no, la propia búsqueda es la única fuente.
    items, errores = [], 0
    for fuente in busqueda.get("fuentes", [busqueda]):
        try:
            items += obtener_fuente(fuente, descargar_fn)
        except Exception as error:  # red caída, feed roto, página cambiada...
            errores += 1
            print(f"[error] {identificador} ({fuente['fuente']}): {error}", file=sys.stderr)
    if errores and not items:
        return None  # nada se descargó: no se marca como ejecutada y se reintenta luego

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


ESTILO = """
:root{--bg:#f6f6f4;--card:#fff;--txt:#1d1d1b;--mut:#6b6b66;--ac:#1a56db}
@media (prefers-color-scheme: dark){:root:not([data-theme="light"]){--bg:#141414;--card:#1f1f1e;--txt:#eee;--mut:#9a9a94;--ac:#7aa7ff}}
:root[data-theme="dark"]{--bg:#141414;--card:#1f1f1e;--txt:#eee;--mut:#9a9a94;--ac:#7aa7ff}
body{margin:0;padding:16px;background:var(--bg);color:var(--txt);font:15px/1.45 system-ui,sans-serif}
h1{font-size:1.4rem}
h2{margin-top:32px;border-bottom:1px solid #8884;padding-bottom:4px}
h3 small{color:var(--mut);font-weight:normal}
.rejilla{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px}
.tarjeta{background:var(--card);border-radius:10px;overflow:hidden;display:flex;flex-direction:column}
.tarjeta img{width:100%;height:170px;object-fit:cover;background:#8882}
.sin-imagen{height:170px;display:flex;align-items:center;justify-content:center;color:var(--mut);background:#8881}
.cuerpo{padding:12px}
.cuerpo h3{font-size:1rem;margin:4px 0 8px}
.cuerpo a{color:var(--ac);text-decoration:none}
.cuerpo p{margin:0 0 6px}
.meta{color:var(--mut);font-size:.8rem}
.badge{font-size:.7rem;border-radius:4px;padding:1px 6px;background:#8883}
.badge.alta{background:#e0484844}
"""


def _tarjeta(item):
    imagen = item.get("imagen")
    if imagen and imagen.startswith(("http://", "https://")):
        media = f'<img src="{esc_html(imagen, quote=True)}" alt="" loading="lazy">'
    else:
        media = '<div class="sin-imagen">Sin imagen</div>'
    fecha = item["publicado"].strftime("%Y-%m-%d") if item["publicado"] else "sin fecha"
    return (
        '<article class="tarjeta">'
        f'{media}'
        '<div class="cuerpo">'
        f'<p class="meta">{esc_html(item["fuente"])} · {esc_html(fecha)}</p>'
        f'<h3><a href="{esc_html(item["enlace"], quote=True)}" target="_blank" rel="noopener">'
        f'{esc_html(item["titulo"])}</a></h3>'
        f'<p>{esc_html(item["resumen"][:400])}</p>'
        '</div></article>'
    )


def escribir_html(carpeta, ahora, resultados):
    """Página con tarjetas (foto, título, fuente, fecha, resumen) de las novedades de esta ejecución."""
    carpeta.mkdir(parents=True, exist_ok=True)
    ruta = carpeta / f"{ahora:%Y-%m-%d_%H%M}.html"
    secciones = []
    for tema, bloques in resultados.items():
        secciones.append(f"<h2>{esc_html(tema)}</h2>")
        for busqueda, items in bloques:
            prioridad = busqueda.get("prioridad", "media")
            secciones.append(
                f'<h3>{esc_html(busqueda["nombre"])} '
                f'<span class="badge {esc_html(prioridad)}">{esc_html(prioridad)}</span> '
                f'<small>{len(items)} nuevos</small></h3>'
            )
            secciones.append('<div class="rejilla">' + "".join(_tarjeta(i) for i in items) + "</div>")
    titulo = f"Alarmas {ahora:%Y-%m-%d %H:%M} UTC"
    documento = (
        '<!doctype html><html lang="es"><head><meta charset="utf-8">'
        '<meta name="viewport" content="width=device-width, initial-scale=1">'
        f"<title>{esc_html(titulo)}</title><style>{ESTILO}</style></head>"
        f"<body><h1>{esc_html(titulo)}</h1>{''.join(secciones)}</body></html>"
    )
    ruta.write_text(documento, encoding="utf-8")
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
    ruta_html = escribir_html(SALIDA_POR_DEFECTO, ahora, resultados)
    for tema, nombre, items in prioritarios:
        print(f"!! [{tema} / {nombre}] {len(items)} nuevos:")
        for item in items:
            print(f"   - {item['titulo']}\n     {item['enlace']}")
    print(f"{total} novedades guardadas en {ruta}")
    print(f"Vista con fotos: {ruta_html}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
