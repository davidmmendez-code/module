import json
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path

import yaml

import alarmas

AHORA = datetime(2026, 11, 18, 12, 0, tzinfo=timezone.utc)

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

FEED_CON_FOTO = """<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/"><channel>
<item>
  <title>Piso con foto</title>
  <link>https://ejemplo.com/foto</link>
  <media:content url="https://img.ejemplo.com/1.jpg" medium="image"/>
</item>
</channel></rss>""".encode("utf-8")

WEB = """<html><body>
<nav><a href="/inicio">Inicio</a></nav>
<a href="/subasta/123">Piso embargado en Getafe, subasta judicial 2026</a>
<a href="https://otro.es/lote/9">Vehículo turismo subastado por la AEAT</a>
<a href="/subasta/123">Piso embargado en Getafe, subasta judicial 2026</a>
<a href="mailto:x@y.es">Escríbenos para cualquier duda sobre subastas</a>
<a href="/lote/7"><img src="/img/7.jpg" alt="Local comercial en Sevilla subasta"></a>
</body></html>""".encode("utf-8")


def base_de_prueba():
    """Base en memoria con una temática, una búsqueda y un RSS."""
    con = alarmas.abrir_base(":memory:")
    alarmas.tema_alta(con, "Subastas", "1d")
    alarmas.busqueda_alta(con, "Subastas", "Pisos", incluir="subasta", excluir="coche,vehiculo", antiguedad=60)
    alarmas.fuente_alta(con, "Subastas", "Pisos", "rss", "https://ejemplo.com/feed")
    return con


def busqueda_de(con, nombre="Pisos"):
    """Reconstruye la búsqueda y sus fuentes como las usa ejecutar()."""
    bid, bprio, binc, bexc, bmax = con.execute(
        "SELECT id, prioridad, incluir, excluir, antiguedad_max_dias FROM busquedas WHERE nombre = ?", (nombre,)
    ).fetchone()
    fuentes = [{"tipo": t, "valor": v, "patron": p} for t, v, p in
               con.execute("SELECT tipo, valor, patron FROM fuentes WHERE busqueda_id = ?", (bid,))]
    return {"id": bid, "nombre": nombre, "prioridad": bprio, "incluir": json.loads(binc),
            "excluir": json.loads(bexc), "antiguedad_max_dias": bmax}, fuentes


class NormalizarTest(unittest.TestCase):
    def test_quita_tildes_y_mayusculas(self):
        self.assertEqual(alarmas.normalizar("SUBÁSTA Vehículo"), "subasta vehiculo")

    def test_frecuencias(self):
        self.assertEqual(alarmas.frecuencia_a_delta("6h"), timedelta(hours=6))
        self.assertEqual(alarmas.frecuencia_a_delta("1w"), timedelta(weeks=1))
        with self.assertRaises(alarmas.AlarmaError):
            alarmas.frecuencia_a_delta("cada rato")


class LectoresTest(unittest.TestCase):
    def test_rss_extrae_campos_y_limpia_html(self):
        items = alarmas.leer_rss(FEED)
        self.assertEqual(len(items), 3)
        self.assertEqual(items[0]["resumen"], "Vivienda con cargas")
        self.assertEqual(items[0]["fuente"], "Fuente A")
        self.assertEqual(items[0]["publicado"].year, 2026)

    def test_rss_imagen_de_media_content(self):
        self.assertEqual(alarmas.leer_rss(FEED_CON_FOTO)[0]["imagen"], "https://img.ejemplo.com/1.jpg")

    def test_web_enlaces_absolutos_sin_duplicados_ni_menus(self):
        items = alarmas.leer_enlaces(WEB, "https://portal.es/listado")
        self.assertEqual([i["enlace"] for i in items],
                         ["https://portal.es/subasta/123", "https://otro.es/lote/9", "https://portal.es/lote/7"])
        self.assertEqual(items[0]["fuente"], "portal.es")

    def test_web_patron_filtra_por_url(self):
        items = alarmas.leer_enlaces(WEB, "https://portal.es/listado", patron=r"/subasta/")
        self.assertEqual([i["enlace"] for i in items], ["https://portal.es/subasta/123"])

    def test_web_imagen_y_titulo_desde_alt(self):
        item = alarmas.leer_enlaces(WEB, "https://portal.es/listado")[2]
        self.assertEqual(item["titulo"], "Local comercial en Sevilla subasta")
        self.assertEqual(item["imagen"], "https://portal.es/img/7.jpg")


class GestionTest(unittest.TestCase):
    def test_no_se_duplican_temas_ni_busquedas(self):
        con = base_de_prueba()
        with self.assertRaises(alarmas.AlarmaError):
            alarmas.tema_alta(con, "Subastas")
        with self.assertRaises(alarmas.AlarmaError):
            alarmas.busqueda_alta(con, "Subastas", "Pisos")

    def test_busqueda_en_tema_inexistente(self):
        with self.assertRaises(alarmas.AlarmaError):
            alarmas.busqueda_alta(base_de_prueba(), "Nope", "X")

    def test_fuente_web_necesita_url_http(self):
        con = base_de_prueba()
        with self.assertRaises(alarmas.AlarmaError):
            alarmas.fuente_alta(con, "Subastas", "Pisos", "web", "ftp://no-valido")

    def test_patron_invalido_se_rechaza(self):
        con = base_de_prueba()
        with self.assertRaises(alarmas.AlarmaError):
            alarmas.fuente_alta(con, "Subastas", "Pisos", "web", "https://a.es", patron="([")

    def test_baja_de_tema_borra_todo_lo_suyo(self):
        con = base_de_prueba()
        alarmas.tema_baja(con, "Subastas")
        for tabla in ("temas", "busquedas", "fuentes"):
            self.assertEqual(con.execute(f"SELECT COUNT(*) FROM {tabla}").fetchone()[0], 0, tabla)

    def test_pausar_fuente_y_busqueda_no_ejecuta(self):
        con = base_de_prueba()
        alarmas.busqueda_activar(con, "Subastas", "Pisos", False)
        resultados, _ = alarmas.ejecutar(con, AHORA, descargar_fn=lambda u: FEED)
        self.assertEqual(resultados, {})
        alarmas.busqueda_activar(con, "Subastas", "Pisos", True)
        fid = con.execute("SELECT id FROM fuentes").fetchone()[0]
        alarmas.fuente_activar(con, fid, False)
        resultados, _ = alarmas.ejecutar(con, AHORA, descargar_fn=lambda u: FEED)
        self.assertEqual(resultados, {})

    def test_listar_muestra_ids_y_estado(self):
        con = base_de_prueba()
        texto = alarmas.listar(con)
        self.assertIn("Subastas", texto)
        self.assertIn("Pisos", texto)
        self.assertIn("incluir: subasta", texto)
        self.assertIn("fuente [1] rss", texto)

    def test_importar_no_duplica_al_repetir(self):
        con = alarmas.abrir_base(":memory:")
        ruta = alarmas.CONFIG_POR_DEFECTO
        alarmas.importar(con, ruta)
        primera = con.execute("SELECT COUNT(*) FROM fuentes").fetchone()[0]
        alarmas.importar(con, ruta)
        self.assertEqual(con.execute("SELECT COUNT(*) FROM fuentes").fetchone()[0], primera)
        self.assertGreater(primera, 10)

    def test_config_de_ejemplo_es_valida(self):
        config = yaml.safe_load(alarmas.CONFIG_POR_DEFECTO.read_text(encoding="utf-8"))
        for tema in config["temas"]:
            for busqueda in tema["busquedas"]:
                for fuente in busqueda.get("fuentes", [busqueda]):
                    self.assertIn(fuente["fuente"], alarmas.TIPOS_FUENTE, busqueda["nombre"])
                    if fuente["fuente"] in {"rss", "web"}:
                        self.assertTrue(fuente["url"].startswith("https://"), busqueda["nombre"])


class EjecucionTest(unittest.TestCase):
    def test_solo_avisa_de_nuevos(self):
        con = base_de_prueba()
        primera, _ = alarmas.ejecutar(con, AHORA, descargar_fn=lambda u: FEED)
        self.assertEqual([i["titulo"] for i in primera["Subastas"][0][1]],
                         ["Subasta de piso en Madrid - Juzgado 3"])
        segunda, _ = alarmas.ejecutar(con, AHORA + timedelta(days=1), descargar_fn=lambda u: FEED)
        self.assertEqual(segunda, {})

    def test_respeta_la_frecuencia_y_forzar(self):
        con = base_de_prueba()
        alarmas.ejecutar(con, AHORA, descargar_fn=lambda u: FEED)
        pronto = AHORA + timedelta(hours=2)
        self.assertEqual(alarmas.ejecutar(con, pronto, descargar_fn=lambda u: FEED)[0], {})
        # forzar ignora la frecuencia: se ejecuta, aunque no haya novedades
        self.assertEqual(alarmas.ejecutar(con, pronto, forzar=True, descargar_fn=lambda u: FEED)[0], {})

    def test_error_de_red_no_marca_como_ejecutada(self):
        con = base_de_prueba()

        def fallo(url):
            raise OSError("sin red")

        self.assertEqual(alarmas.ejecutar(con, AHORA, descargar_fn=fallo)[0], {})
        resultados, _ = alarmas.ejecutar(con, AHORA, descargar_fn=lambda u: FEED)
        self.assertEqual(len(resultados["Subastas"][0][1]), 1)

    def test_una_fuente_caida_no_bloquea_a_las_demas(self):
        con = base_de_prueba()
        alarmas.fuente_alta(con, "Subastas", "Pisos", "web", "https://caida.es/listado")

        def descargar(url):
            if "caida" in url:
                raise OSError("sin red")
            return FEED

        resultados, _ = alarmas.ejecutar(con, AHORA, descargar_fn=descargar)
        self.assertEqual(len(resultados["Subastas"][0][1]), 1)

    def test_prioritarios_se_devuelven_aparte(self):
        con = base_de_prueba()
        alarmas.busqueda_alta(con, "Subastas", "Urgente", prioridad="alta")
        alarmas.fuente_alta(con, "Subastas", "Urgente", "rss", "https://ejemplo.com/feed")
        _, prioritarios = alarmas.ejecutar(con, AHORA, descargar_fn=lambda u: FEED)
        self.assertEqual([p[1] for p in prioritarios], ["Urgente"])

    def test_filtros_de_busqueda_se_aplican(self):
        con = base_de_prueba()
        busqueda, fuentes = busqueda_de(con)
        nuevos = alarmas.ejecutar_busqueda(con, busqueda, fuentes, "1d", AHORA, descargar_fn=lambda u: FEED)
        self.assertNotIn("coche", " ".join(i["titulo"] for i in nuevos).lower())


class SalidaTest(unittest.TestCase):
    def test_html_escapa_el_texto_remoto(self):
        item = {"titulo": "<script>alert(1)</script>", "enlace": "https://a.es/x", "resumen": "<b>x</b>",
                "fuente": "a.es", "publicado": None, "imagen": "javascript:alert(1)"}
        with tempfile.TemporaryDirectory() as carpeta:
            ruta = alarmas.escribir_html(Path(carpeta), AHORA, {"T": [({"nombre": "B", "prioridad": "alta"}, [item])]})
            contenido = ruta.read_text(encoding="utf-8")
        self.assertNotIn("<script>alert", contenido)
        self.assertIn("&lt;script&gt;", contenido)
        self.assertIn("Sin imagen", contenido)  # javascript: no se usa como imagen


class CliTest(unittest.TestCase):
    def test_flujo_completo_por_linea_de_comandos(self):
        with tempfile.TemporaryDirectory() as carpeta:
            db = str(Path(carpeta) / "t.db")
            self.assertEqual(alarmas.main(["--db", db, "tema", "alta", "Subastas"]), 0)
            self.assertEqual(alarmas.main(["--db", db, "busqueda", "alta", "Subastas", "Pisos", "--incluir", "piso"]), 0)
            self.assertEqual(alarmas.main(["--db", db, "fuente", "alta", "Subastas", "Pisos", "web",
                                           "https://portal.es/x", "--patron", "/subasta/"]), 0)
            self.assertEqual(alarmas.main(["--db", db, "tema", "alta", "Subastas"]), 1)  # duplicada
            self.assertEqual(alarmas.main(["--db", db, "fuente", "alta", "Subastas", "Pisos", "web", "ftp://x"]), 1)
            self.assertEqual(alarmas.main(["--db", db, "fuente", "baja", "99"]), 1)
            self.assertEqual(alarmas.main(["--db", db, "listar"]), 0)


if __name__ == "__main__":
    unittest.main()
