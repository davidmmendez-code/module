import sqlite3
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path

import yaml

import alarmas

FEED = """<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"><channel><title>t</title>
<item>
  <title>Subasta de piso en Madrid - Juzgado 3</title>
  <link>https://ejemplo.com/a</link>
  <pubDate>Mon, 16 Nov 2026 10:00:00 GMT</pubDate>
  <description>&lt;a href="x"&gt;Vivienda&lt;/a&gt; con cargas</description>
  <source url="https://fuente.es">Fuente A</source>
</item>
<item>
  <title>Subasta de coche usado</title>
  <link>https://ejemplo.com/b</link>
  <pubDate>Mon, 16 Nov 2026 09:00:00 GMT</pubDate>
  <description>Vehículo turismo</description>
</item>
<item>
  <title>Noticia muy antigua sobre subasta</title>
  <link>https://ejemplo.com/c</link>
  <pubDate>Mon, 01 Jan 2024 09:00:00 GMT</pubDate>
  <description>piso</description>
</item>
</channel></rss>""".encode("utf-8")

AHORA = datetime(2026, 11, 18, 12, 0, tzinfo=timezone.utc)
TEMA = {"nombre": "Subastas", "frecuencia": "1d"}
BUSQUEDA = {
    "nombre": "Pisos",
    "fuente": "rss",
    "url": "https://ejemplo.com/feed",
    "incluir": ["subasta"],
    "excluir": ["coche", "vehiculo"],
    "antiguedad_max_dias": 60,
}


def base_en_memoria():
    con = sqlite3.connect(":memory:")
    con.executescript("""
        CREATE TABLE vistos (busqueda TEXT, clave TEXT, titulo TEXT, enlace TEXT, primera_vez TEXT,
                             PRIMARY KEY (busqueda, clave));
        CREATE TABLE ejecuciones (busqueda TEXT PRIMARY KEY, ultima TEXT NOT NULL);
    """)
    return con


class NormalizarTest(unittest.TestCase):
    def test_quita_tildes_y_mayusculas(self):
        self.assertEqual(alarmas.normalizar("SUBÁSTA Vehículo"), "subasta vehiculo")

    def test_frecuencias(self):
        self.assertEqual(alarmas.frecuencia_a_delta("6h"), timedelta(hours=6))
        self.assertEqual(alarmas.frecuencia_a_delta("1w"), timedelta(weeks=1))
        with self.assertRaises(ValueError):
            alarmas.frecuencia_a_delta("cada rato")


class LeerRssTest(unittest.TestCase):
    def test_extrae_campos_y_limpia_html(self):
        items = alarmas.leer_rss(FEED)
        self.assertEqual(len(items), 3)
        self.assertEqual(items[0]["titulo"], "Subasta de piso en Madrid - Juzgado 3")
        self.assertEqual(items[0]["resumen"], "Vivienda con cargas")
        self.assertEqual(items[0]["fuente"], "Fuente A")
        self.assertEqual(items[0]["publicado"].year, 2026)


class FiltrosTest(unittest.TestCase):
    def test_incluir_excluir(self):
        items = alarmas.leer_rss(FEED)
        self.assertTrue(alarmas.cumple_filtros(items[0], BUSQUEDA))
        self.assertFalse(alarmas.cumple_filtros(items[1], BUSQUEDA))  # contiene "coche"

    def test_antiguedad(self):
        items = alarmas.leer_rss(FEED)
        self.assertFalse(alarmas.es_demasiado_antiguo(items[0], 60, AHORA))
        self.assertTrue(alarmas.es_demasiado_antiguo(items[2], 60, AHORA))


class EjecucionTest(unittest.TestCase):
    def test_solo_devuelve_nuevos_la_segunda_vez(self):
        con = base_en_memoria()
        primera = alarmas.ejecutar_busqueda(con, TEMA, BUSQUEDA, AHORA, descargar_fn=lambda u: FEED)
        self.assertEqual([i["titulo"] for i in primera], ["Subasta de piso en Madrid - Juzgado 3"])

        despues = AHORA + timedelta(days=1)
        segunda = alarmas.ejecutar_busqueda(con, TEMA, BUSQUEDA, despues, descargar_fn=lambda u: FEED)
        self.assertEqual(segunda, [])

    def test_respeta_la_frecuencia(self):
        con = base_en_memoria()
        alarmas.ejecutar_busqueda(con, TEMA, BUSQUEDA, AHORA, descargar_fn=lambda u: FEED)
        pronto = AHORA + timedelta(hours=2)
        self.assertEqual(alarmas.ejecutar_busqueda(con, TEMA, BUSQUEDA, pronto, descargar_fn=lambda u: FEED), [])

    def test_forzar_ignora_la_frecuencia(self):
        con = base_en_memoria()
        alarmas.ejecutar_busqueda(con, TEMA, BUSQUEDA, AHORA, descargar_fn=lambda u: FEED)
        pronto = AHORA + timedelta(hours=2)
        nuevos = alarmas.ejecutar_busqueda(con, TEMA, BUSQUEDA, pronto, forzar=True,
                                           descargar_fn=lambda u: FEED)
        self.assertEqual(nuevos, [])  # ya estaban vistos, pero se ejecutó

    def test_error_de_red_no_marca_como_ejecutada(self):
        con = base_en_memoria()

        def fallo(url):
            raise OSError("sin red")

        self.assertIsNone(alarmas.ejecutar_busqueda(con, TEMA, BUSQUEDA, AHORA, descargar_fn=fallo))
        self.assertEqual(
            alarmas.ejecutar_busqueda(con, TEMA, BUSQUEDA, AHORA, descargar_fn=lambda u: FEED)[0]["titulo"],
            "Subasta de piso en Madrid - Juzgado 3",
        )


WEB = """<html><body>
<nav><a href="/inicio">Inicio</a></nav>
<a href="/subasta/123">Piso embargado en Getafe, subasta judicial 2026</a>
<a href="https://otro.es/lote/9">Vehículo turismo subastado por la AEAT</a>
<a href="/subasta/123">Piso embargado en Getafe, subasta judicial 2026</a>
<a href="mailto:x@y.es">Escríbenos para cualquier duda sobre subastas</a>
</body></html>""".encode("utf-8")


class WebTest(unittest.TestCase):
    def test_extrae_enlaces_absolutos_sin_duplicados_ni_menus(self):
        items = alarmas.leer_enlaces(WEB, "https://portal.es/listado")
        self.assertEqual([i["enlace"] for i in items],
                         ["https://portal.es/subasta/123", "https://otro.es/lote/9"])
        self.assertEqual(items[0]["fuente"], "portal.es")

    def test_ejecucion_web_avisa_solo_de_nuevos(self):
        con = base_en_memoria()
        busqueda = {"nombre": "Portal", "fuente": "web", "url": "https://portal.es/listado",
                    "incluir": ["subasta", "vehículo", "piso"]}
        primera = alarmas.ejecutar_busqueda(con, TEMA, busqueda, AHORA, descargar_fn=lambda u: WEB)
        self.assertEqual(len(primera), 2)
        segunda = alarmas.ejecutar_busqueda(con, TEMA, busqueda, AHORA + timedelta(days=1),
                                            descargar_fn=lambda u: WEB)
        self.assertEqual(segunda, [])


class MultiFuenteTest(unittest.TestCase):
    def test_una_fuente_caida_no_bloquea_a_las_demas(self):
        con = base_en_memoria()
        busqueda = {
            "nombre": "Mixta",
            "incluir": ["subasta"],
            "excluir": ["coche"],
            "antiguedad_max_dias": 60,
            "fuentes": [
                {"fuente": "web", "url": "https://caida.es/listado"},
                {"fuente": "rss", "url": "https://ok.es/feed"},
            ],
        }

        def descargar(url):
            if "caida" in url:
                raise OSError("sin red")
            return FEED

        nuevos = alarmas.ejecutar_busqueda(con, TEMA, busqueda, AHORA, descargar_fn=descargar)
        self.assertEqual([i["titulo"] for i in nuevos], ["Subasta de piso en Madrid - Juzgado 3"])

    def test_todas_caidas_no_marca_como_ejecutada(self):
        con = base_en_memoria()
        busqueda = {"nombre": "Mixta", "fuentes": [{"fuente": "rss", "url": "https://a.es"},
                                                   {"fuente": "web", "url": "https://b.es"}]}

        def fallo(url):
            raise OSError("sin red")

        self.assertIsNone(alarmas.ejecutar_busqueda(con, TEMA, busqueda, AHORA, descargar_fn=fallo))


class ConfigTest(unittest.TestCase):
    def test_config_de_ejemplo_es_valida(self):
        config = yaml.safe_load(Path(alarmas.AQUI / "temas.yaml").read_text(encoding="utf-8"))
        for tema in config["temas"]:
            for busqueda in tema["busquedas"]:
                fuentes = busqueda.get("fuentes", [busqueda])
                self.assertTrue(fuentes, busqueda["nombre"])
                for fuente in fuentes:
                    self.assertIn(fuente["fuente"], {"google_news", "rss", "web"}, busqueda["nombre"])
                    if fuente["fuente"] in {"rss", "web"}:
                        self.assertTrue(fuente["url"].startswith("https://"), busqueda["nombre"])
                    if fuente["fuente"] == "google_news":
                        self.assertTrue(fuente["consulta"], busqueda["nombre"])
                if "frecuencia" in busqueda:
                    alarmas.frecuencia_a_delta(busqueda["frecuencia"])
                if "frecuencia" in tema:
                    alarmas.frecuencia_a_delta(tema["frecuencia"])


if __name__ == "__main__":
    unittest.main()
