#!/usr/bin/env python3
"""Sistema de alertas: da de alta búsquedas y fuentes, y avisa solo de lo nuevo.

Las temáticas, búsquedas y fuentes se guardan en SQLite (alarmas/datos/alarmas.db)
y se gestionan con comandos. README.md tiene la lista completa.
"""
import argparse
import hashlib
import json
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
TIPOS_FUENTE = ("google_news", "rss", "web")
COMANDOS = ("tema", "busqueda", "fuente", "listar", "importar", "ejecutar")


class AlarmaError(Exception):
    """Error que se muestra al usuario sin traza."""


# ---------------------------------------------------------------- utilidades

def normalizar(texto):
    """Minúsculas y sin tildes, para que 'Subasta' encaje con 'subásta'."""
    texto = unicodedata.normalize("NFKD", texto or "")
    return "".join(c for c in texto if not unicodedata.combining(c)).lower()


def frecuencia_a_delta(valor):
    """Convierte '6h', '1d' o '1w' en un timedelta."""
    match = re.fullmatch(r"(\d+)([hdw])", str(valor).strip())
    if not match:
        raise AlarmaError(f"Frecuencia no válida: {valor!r} (usa 6h, 1d, 1w...)")
    return timedelta(**{UNIDADES[match.group(2)]: int(match.group(1))})


def _lista(texto):
    """'a, b,c' -> ['a', 'b', 'c']"""
    return [t.strip() for t in (texto or "").split(",") if t.strip()]


def url_google_news(consulta):
    params = {"q": consulta, "hl": "es", "gl": "ES", "ceid": "ES:es"}
    return "https://news.google.com/rss/search?" + urllib.parse.urlencode(params)


def descargar(url):
    peticion = urllib.request.Request(url, headers={"User-Agent": "alarmas-local/1.0"})
    with urllib.request.urlopen(peticion, timeout=30) as respuesta:
        return respuesta.read()


# ---------------------------------------------------------------- lectores de fuentes

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
    """Items de un feed RSS 2.0 (también sirve el de Google News)."""
    raiz = ET.fromstring(contenido)
    items = []
    for item in raiz.iter("item"):
        bruto = item.findtext("description") or ""
        resumen = re.sub(r"<[^>]+>", " ", bruto)
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
            "imagen": _imagen_rss(item, bruto),
        })
    return items


class _Enlaces(HTMLParser):
    def __init__(self):
        super().__init__()
        self.enlaces = []  # tuplas (href, texto, imagen)
        self._ancla = None

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


def leer_enlaces(contenido, base, patron=None):
    """Enlaces de una página de listados. 'patron' es una regex que debe encajar con la URL absoluta."""
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
        if patron and not re.search(patron, absoluta):
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


def obtener_fuente(fuente, descargar_fn):
    """Descarga una fuente (dict con tipo, valor, patron) y devuelve sus items."""
    if fuente["tipo"] == "google_news":
        return leer_rss(descargar_fn(url_google_news(fuente["valor"])))
    if fuente["tipo"] == "rss":
        return leer_rss(descargar_fn(fuente["valor"]))
    if fuente["tipo"] == "web":
        return leer_enlaces(descargar_fn(fuente["valor"]), fuente["valor"], fuente.get("patron"))
    raise AlarmaError(f"Tipo de fuente desconocido: {fuente['tipo']}")


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


# ---------------------------------------------------------------- base de datos

ESQUEMA = """
CREATE TABLE IF NOT EXISTS temas (
    id INTEGER PRIMARY KEY,
    nombre TEXT NOT NULL UNIQUE,
    frecuencia TEXT NOT NULL DEFAULT '1d',
    activa INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE IF NOT EXISTS busquedas (
    id INTEGER PRIMARY KEY,
    tema_id INTEGER NOT NULL REFERENCES temas(id) ON DELETE CASCADE,
    nombre TEXT NOT NULL,
    prioridad TEXT NOT NULL DEFAULT 'media',
    frecuencia TEXT,
    incluir TEXT NOT NULL DEFAULT '[]',
    excluir TEXT NOT NULL DEFAULT '[]',
    antiguedad_max_dias INTEGER,
    activa INTEGER NOT NULL DEFAULT 1,
    UNIQUE (tema_id, nombre)
);
CREATE TABLE IF NOT EXISTS fuentes (
    id INTEGER PRIMARY KEY,
    busqueda_id INTEGER NOT NULL REFERENCES busquedas(id) ON DELETE CASCADE,
    tipo TEXT NOT NULL CHECK (tipo IN ('google_news', 'rss', 'web')),
    valor TEXT NOT NULL,
    patron TEXT,
    activa INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE IF NOT EXISTS vistos (
    busqueda_id INTEGER NOT NULL,
    clave TEXT NOT NULL,
    titulo TEXT,
    enlace TEXT,
    primera_vez TEXT NOT NULL,
    PRIMARY KEY (busqueda_id, clave)
);
CREATE TABLE IF NOT EXISTS ejecuciones (
    busqueda_id INTEGER PRIMARY KEY,
    ultima TEXT NOT NULL
);
"""


def abrir_base(ruta):
    if str(ruta) != ":memory:":
        Path(ruta).parent.mkdir(parents=True, exist_ok=True)
    con = sqlite3.connect(ruta)
    con.execute("PRAGMA foreign_keys = ON")
    con.executescript(ESQUEMA)
    return con


def _id_tema(con, nombre):
    fila = con.execute("SELECT id FROM temas WHERE nombre = ?", (nombre,)).fetchone()
    if fila is None:
        raise AlarmaError(f"No existe la temática '{nombre}'")
    return fila[0]


def _id_busqueda(con, tema, nombre):
    fila = con.execute(
        "SELECT b.id FROM busquedas b JOIN temas t ON t.id = b.tema_id WHERE t.nombre = ? AND b.nombre = ?",
        (tema, nombre),
    ).fetchone()
    if fila is None:
        raise AlarmaError(f"No existe la búsqueda '{nombre}' en la temática '{tema}'")
    return fila[0]


def _validar_fuente(tipo, valor, patron):
    if tipo not in TIPOS_FUENTE:
        raise AlarmaError(f"Tipo no válido: {tipo} (usa {', '.join(TIPOS_FUENTE)})")
    if not valor.strip():
        raise AlarmaError("El valor de la fuente no puede estar vacío")
    if tipo != "google_news" and not valor.startswith(("http://", "https://")):
        raise AlarmaError("Una fuente rss o web necesita una URL que empiece por http:// o https://")
    if patron:
        try:
            re.compile(patron)
        except re.error as error:
            raise AlarmaError(f"Patrón no válido: {error}")


def _borrar_busquedas(con, ids):
    for bid in ids:
        con.execute("DELETE FROM vistos WHERE busqueda_id = ?", (bid,))
        con.execute("DELETE FROM ejecuciones WHERE busqueda_id = ?", (bid,))
        con.execute("DELETE FROM busquedas WHERE id = ?", (bid,))  # sus fuentes caen en cascada


# ---------------------------------------------------------------- comandos de gestión

def tema_alta(con, nombre, frecuencia="1d"):
    frecuencia_a_delta(frecuencia)
    try:
        con.execute("INSERT INTO temas (nombre, frecuencia) VALUES (?, ?)", (nombre, frecuencia))
    except sqlite3.IntegrityError:
        raise AlarmaError(f"Ya existe la temática '{nombre}'")
    con.commit()
    return f"Temática '{nombre}' creada (cada {frecuencia})."


def tema_baja(con, nombre):
    tid = _id_tema(con, nombre)
    ids = [fila[0] for fila in con.execute("SELECT id FROM busquedas WHERE tema_id = ?", (tid,))]
    _borrar_busquedas(con, ids)
    con.execute("DELETE FROM temas WHERE id = ?", (tid,))
    con.commit()
    return f"Temática '{nombre}' eliminada, con sus {len(ids)} búsquedas y fuentes."


def tema_activar(con, nombre, activa):
    tid = _id_tema(con, nombre)
    con.execute("UPDATE temas SET activa = ? WHERE id = ?", (1 if activa else 0, tid))
    con.commit()
    return f"Temática '{nombre}' {'reanudada' if activa else 'pausada'}."


def busqueda_alta(con, tema, nombre, prioridad="media", frecuencia=None, incluir="", excluir="", antiguedad=None):
    tid = _id_tema(con, tema)
    if frecuencia:
        frecuencia_a_delta(frecuencia)
    if prioridad not in ("alta", "media", "baja"):
        raise AlarmaError("La prioridad debe ser alta, media o baja")
    try:
        cursor = con.execute(
            "INSERT INTO busquedas (tema_id, nombre, prioridad, frecuencia, incluir, excluir, antiguedad_max_dias) "
            "VALUES (?, ?, ?, ?, ?, ?, ?)",
            (tid, nombre, prioridad, frecuencia, json.dumps(_lista(incluir)), json.dumps(_lista(excluir)), antiguedad),
        )
    except sqlite3.IntegrityError:
        raise AlarmaError(f"Ya existe la búsqueda '{nombre}' en '{tema}'")
    con.commit()
    return f"Búsqueda [{cursor.lastrowid}] '{nombre}' creada en '{tema}'. Añade fuentes con 'fuente alta'."


def busqueda_baja(con, tema, nombre):
    bid = _id_busqueda(con, tema, nombre)
    _borrar_busquedas(con, [bid])
    con.commit()
    return f"Búsqueda '{nombre}' eliminada de '{tema}'."


def busqueda_activar(con, tema, nombre, activa):
    bid = _id_busqueda(con, tema, nombre)
    con.execute("UPDATE busquedas SET activa = ? WHERE id = ?", (1 if activa else 0, bid))
    con.commit()
    return f"Búsqueda '{nombre}' {'reanudada' if activa else 'pausada'}."


def fuente_alta(con, tema, busqueda, tipo, valor, patron=None):
    bid = _id_busqueda(con, tema, busqueda)
    _validar_fuente(tipo, valor, patron)
    cursor = con.execute(
        "INSERT INTO fuentes (busqueda_id, tipo, valor, patron) VALUES (?, ?, ?, ?)",
        (bid, tipo, valor, patron),
    )
    con.commit()
    return f"Fuente [{cursor.lastrowid}] {tipo} añadida a '{busqueda}'."


def _fuente_existe(con, fid):
    if con.execute("SELECT 1 FROM fuentes WHERE id = ?", (fid,)).fetchone() is None:
        raise AlarmaError(f"No existe la fuente [{fid}]")


def fuente_baja(con, fid):
    _fuente_existe(con, fid)
    con.execute("DELETE FROM fuentes WHERE id = ?", (fid,))
    con.commit()
    return f"Fuente [{fid}] eliminada."


def fuente_activar(con, fid, activa):
    _fuente_existe(con, fid)
    con.execute("UPDATE fuentes SET activa = ? WHERE id = ?", (1 if activa else 0, fid))
    con.commit()
    return f"Fuente [{fid}] {'reanudada' if activa else 'pausada'}."


def listar(con):
    lineas = []
    temas = con.execute("SELECT id, nombre, frecuencia, activa FROM temas ORDER BY nombre").fetchall()
    for tid, tnombre, tfrec, tactiva in temas:
        lineas.append(f"[{tid}] {tnombre}  (cada {tfrec}){'' if tactiva else '  [PAUSADA]'}")
        busquedas = con.execute(
            "SELECT id, nombre, prioridad, frecuencia, incluir, excluir, activa FROM busquedas "
            "WHERE tema_id = ? ORDER BY nombre", (tid,),
        ).fetchall()
        for bid, bnombre, bprio, bfrec, binc, bexc, bactiva in busquedas:
            detalle = f"prioridad {bprio}" + (f", cada {bfrec}" if bfrec else "")
            lineas.append(f"  - [{bid}] {bnombre}  ({detalle}){'' if bactiva else '  [PAUSADA]'}")
            if json.loads(binc):
                lineas.append(f"      incluir: {', '.join(json.loads(binc))}")
            if json.loads(bexc):
                lineas.append(f"      excluir: {', '.join(json.loads(bexc))}")
            fuentes = con.execute(
                "SELECT id, tipo, valor, patron, activa FROM fuentes WHERE busqueda_id = ? ORDER BY id", (bid,),
            ).fetchall()
            for fid, ftipo, fvalor, fpatron, factiva in fuentes:
                extra = f"  patrón: {fpatron}" if fpatron else ""
                lineas.append(f"      · fuente [{fid}] {ftipo}: {fvalor}{extra}{'' if factiva else '  [PAUSADA]'}")
    return "\n".join(lineas) if lineas else "No hay temáticas. Crea una con 'tema alta'."


def importar(con, ruta):
    """Carga un YAML de semilla. Las búsquedas que ya existen no se tocan."""
    config = yaml.safe_load(Path(ruta).read_text(encoding="utf-8"))
    nuevas = 0
    for tema in config.get("temas", []):
        con.execute("INSERT OR IGNORE INTO temas (nombre, frecuencia) VALUES (?, ?)",
                    (tema["nombre"], tema.get("frecuencia", "1d")))
        tid = _id_tema(con, tema["nombre"])
        for b in tema.get("busquedas", []):
            cursor = con.execute(
                "INSERT OR IGNORE INTO busquedas (tema_id, nombre, prioridad, frecuencia, incluir, excluir, "
                "antiguedad_max_dias, activa) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                (tid, b["nombre"], b.get("prioridad", "media"), b.get("frecuencia"),
                 json.dumps(b.get("incluir", [])), json.dumps(b.get("excluir", [])),
                 b.get("antiguedad_max_dias"), 1 if b.get("activa", True) else 0),
            )
            if cursor.rowcount == 0:
                continue  # ya existía: no se duplican sus fuentes
            nuevas += 1
            for f in b.get("fuentes", [b]):
                valor = f.get("consulta") or f.get("url")
                _validar_fuente(f["fuente"], valor, f.get("patron"))
                con.execute(
                    "INSERT INTO fuentes (busqueda_id, tipo, valor, patron, activa) VALUES (?, ?, ?, ?, ?)",
                    (cursor.lastrowid, f["fuente"], valor, f.get("patron"), 1 if f.get("activa", True) else 0),
                )
    con.commit()
    return f"Importadas {nuevas} búsquedas nuevas desde {ruta}."


# ---------------------------------------------------------------- ejecución

def toca_ejecutar(con, bid, frecuencia, ahora, forzar):
    if forzar:
        return True
    fila = con.execute("SELECT ultima FROM ejecuciones WHERE busqueda_id = ?", (bid,)).fetchone()
    if fila is None:
        return True
    return ahora - datetime.fromisoformat(fila[0]) >= frecuencia_a_delta(frecuencia)


def ejecutar_busqueda(con, busqueda, fuentes, frecuencia, ahora, forzar=False, descargar_fn=descargar):
    """Devuelve los items nuevos, [] si no toca o no hay novedades, o None si todas las fuentes fallaron."""
    if not toca_ejecutar(con, busqueda["id"], frecuencia, ahora, forzar):
        return []

    items, errores = [], 0
    for fuente in fuentes:
        try:
            items += obtener_fuente(fuente, descargar_fn)
        except Exception as error:  # red caída, feed roto, página cambiada...
            errores += 1
            print(f"[error] {busqueda['nombre']} ({fuente['tipo']} {fuente['valor']}): {error}", file=sys.stderr)
    if errores and not items:
        return None  # nada se descargó: no se marca como ejecutada y se reintenta luego

    nuevos = []
    for item in items:
        if not cumple_filtros(item, busqueda) or es_demasiado_antiguo(item, busqueda["antiguedad_max_dias"], ahora):
            continue
        clave = hashlib.sha256((item["enlace"] or item["titulo"]).encode("utf-8")).hexdigest()
        cursor = con.execute(
            "INSERT OR IGNORE INTO vistos (busqueda_id, clave, titulo, enlace, primera_vez) VALUES (?, ?, ?, ?, ?)",
            (busqueda["id"], clave, item["titulo"], item["enlace"], ahora.isoformat()),
        )
        if cursor.rowcount:
            nuevos.append(item)

    con.execute(
        "INSERT OR REPLACE INTO ejecuciones (busqueda_id, ultima) VALUES (?, ?)",
        (busqueda["id"], ahora.isoformat()),
    )
    con.commit()
    return nuevos


def ejecutar(con, ahora, forzar=False, temas=None, descargar_fn=descargar):
    """Ejecuta todas las búsquedas activas (o las de las temáticas indicadas) que toque ejecutar."""
    resultados, prioritarios = {}, []
    for tid, tnombre, tfrec in con.execute("SELECT id, nombre, frecuencia FROM temas WHERE activa = 1 ORDER BY nombre"):
        if temas and tnombre not in temas:
            continue
        busquedas = con.execute(
            "SELECT id, nombre, prioridad, frecuencia, incluir, excluir, antiguedad_max_dias FROM busquedas "
            "WHERE tema_id = ? AND activa = 1 ORDER BY nombre", (tid,),
        ).fetchall()
        for bid, bnombre, bprio, bfrec, binc, bexc, bmax in busquedas:
            fuentes = [
                {"tipo": t, "valor": v, "patron": p}
                for t, v, p in con.execute(
                    "SELECT tipo, valor, patron FROM fuentes WHERE busqueda_id = ? AND activa = 1 ORDER BY id", (bid,),
                )
            ]
            if not fuentes:
                continue
            busqueda = {"id": bid, "nombre": bnombre, "prioridad": bprio, "incluir": json.loads(binc),
                        "excluir": json.loads(bexc), "antiguedad_max_dias": bmax}
            nuevos = ejecutar_busqueda(con, busqueda, fuentes, bfrec or tfrec, ahora, forzar, descargar_fn)
            if not nuevos:
                continue
            resultados.setdefault(tnombre, []).append((busqueda, nuevos))
            if bprio == "alta":
                prioritarios.append((tnombre, bnombre, nuevos))
    return resultados, prioritarios


# ---------------------------------------------------------------- salidas

def escribir_resumen(carpeta, ahora, resultados):
    """Añade los nuevos al resumen del día (un fichero .md por día)."""
    carpeta.mkdir(parents=True, exist_ok=True)
    ruta = carpeta / f"{ahora:%Y-%m-%d}.md"
    lineas = [f"# Alarmas {ahora:%Y-%m-%d %H:%M} UTC", ""]
    for tema, bloques in resultados.items():
        lineas.append(f"## {tema}")
        lineas.append("")
        for busqueda, items in bloques:
            lineas.append(f"### {busqueda['nombre']} — {len(items)} nuevos (prioridad {busqueda['prioridad']})")
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
            prioridad = busqueda["prioridad"]
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


# ---------------------------------------------------------------- línea de comandos

def _construir_parser():
    # --db y --salida se aceptan antes o después del comando; los valores por defecto los da el padre.
    comunes = argparse.ArgumentParser(add_help=False)
    comunes.add_argument("--db", type=Path, default=argparse.SUPPRESS, help="base de datos SQLite")
    comunes.add_argument("--salida", type=Path, default=argparse.SUPPRESS, help="carpeta de resúmenes")

    p = argparse.ArgumentParser(prog="alarmas", description="Alertas por temáticas y fuentes")
    p.add_argument("--db", type=Path, default=DB_POR_DEFECTO, help="base de datos SQLite")
    p.add_argument("--salida", type=Path, default=SALIDA_POR_DEFECTO, help="carpeta de resúmenes")
    sub = p.add_subparsers(dest="orden", required=True)

    def hoja(grupo, nombre, **kwargs):
        return grupo.add_parser(nombre, parents=[comunes], **kwargs)

    t = sub.add_parser("tema", help="gestionar temáticas").add_subparsers(dest="accion", required=True)
    ta = hoja(t, "alta")
    ta.add_argument("nombre")
    ta.add_argument("--frecuencia", default="1d", help="6h, 1d, 1w...")
    hoja(t, "baja").add_argument("nombre")
    hoja(t, "pausar").add_argument("nombre")
    hoja(t, "reanudar").add_argument("nombre")

    b = sub.add_parser("busqueda", help="gestionar búsquedas (alertas)").add_subparsers(dest="accion", required=True)
    ba = hoja(b, "alta")
    ba.add_argument("tema")
    ba.add_argument("nombre")
    ba.add_argument("--prioridad", default="media", choices=["alta", "media", "baja"])
    ba.add_argument("--frecuencia", help="si no, la de la temática")
    ba.add_argument("--incluir", default="", help="palabras separadas por comas (basta una)")
    ba.add_argument("--excluir", default="", help="palabras separadas por comas (ninguna)")
    ba.add_argument("--antiguedad", type=int, help="descarta resultados con más de N días")
    for nombre in ("baja", "pausar", "reanudar"):
        bb = hoja(b, nombre)
        bb.add_argument("tema")
        bb.add_argument("nombre")

    f = sub.add_parser("fuente", help="gestionar fuentes de una búsqueda").add_subparsers(dest="accion", required=True)
    fa = hoja(f, "alta")
    fa.add_argument("tema")
    fa.add_argument("busqueda")
    fa.add_argument("tipo", choices=TIPOS_FUENTE)
    fa.add_argument("valor", help="consulta (google_news) o URL (rss, web)")
    fa.add_argument("--patron", help="regex que debe cumplir la URL de cada resultado (solo web)")
    for nombre in ("baja", "pausar", "reanudar"):
        fb = hoja(f, nombre)
        fb.add_argument("id", type=int)

    hoja(sub, "listar", help="ver temáticas, búsquedas y fuentes")
    hi = hoja(sub, "importar", help="cargar temas.yaml como semilla")
    hi.add_argument("ruta", nargs="?", default=str(CONFIG_POR_DEFECTO))
    he = hoja(sub, "ejecutar", help="buscar novedades y avisar (por defecto)")
    he.add_argument("--forzar", action="store_true", help="ignora la frecuencia")
    he.add_argument("--tema", action="append", help="solo esta temática (repetible)")
    return p


def _tiene_comando(argv):
    """True si hay un comando que no es el valor de una opción (p. ej. '--db ruta')."""
    return any(a in COMANDOS and (i == 0 or not argv[i - 1].startswith("--")) for i, a in enumerate(argv))


def main(argv=None):
    argv = list(sys.argv[1:] if argv is None else argv)
    if not _tiene_comando(argv):
        argv = ["ejecutar"] + argv  # sin comando se ejecuta la revisión, como un cron
    args = _construir_parser().parse_args(argv)
    con = abrir_base(args.db)
    try:
        if args.orden == "tema":
            mensaje = {"alta": lambda: tema_alta(con, args.nombre, args.frecuencia),
                       "baja": lambda: tema_baja(con, args.nombre),
                       "pausar": lambda: tema_activar(con, args.nombre, False),
                       "reanudar": lambda: tema_activar(con, args.nombre, True)}[args.accion]()
        elif args.orden == "busqueda":
            if args.accion == "alta":
                mensaje = busqueda_alta(con, args.tema, args.nombre, args.prioridad, args.frecuencia,
                                        args.incluir, args.excluir, args.antiguedad)
            elif args.accion == "baja":
                mensaje = busqueda_baja(con, args.tema, args.nombre)
            else:
                mensaje = busqueda_activar(con, args.tema, args.nombre, args.accion == "reanudar")
        elif args.orden == "fuente":
            if args.accion == "alta":
                mensaje = fuente_alta(con, args.tema, args.busqueda, args.tipo, args.valor, args.patron)
            elif args.accion == "baja":
                mensaje = fuente_baja(con, args.id)
            else:
                mensaje = fuente_activar(con, args.id, args.accion == "reanudar")
        elif args.orden == "listar":
            mensaje = listar(con)
        elif args.orden == "importar":
            mensaje = importar(con, args.ruta)
        else:  # ejecutar
            return _ejecutar_y_avisar(con, args)
        print(mensaje)
        return 0
    except AlarmaError as error:
        print(f"Error: {error}", file=sys.stderr)
        return 1
    finally:
        con.close()


def _ejecutar_y_avisar(con, args):
    ahora = datetime.now(timezone.utc)
    resultados, prioritarios = ejecutar(con, ahora, args.forzar, args.tema)
    if not resultados:
        print("Sin novedades.")
        return 0
    total = sum(len(items) for bloques in resultados.values() for _, items in bloques)
    ruta = escribir_resumen(args.salida, ahora, resultados)
    ruta_html = escribir_html(args.salida, ahora, resultados)
    for tema, nombre, items in prioritarios:
        print(f"!! [{tema} / {nombre}] {len(items)} nuevos:")
        for item in items:
            print(f"   - {item['titulo']}\n     {item['enlace']}")
    print(f"{total} novedades guardadas en {ruta}")
    print(f"Vista con fotos: {ruta_html}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
