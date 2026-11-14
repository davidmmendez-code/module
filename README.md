# PrestaShop Attribution

Módulo para medir de qué canal llegan visitas y pedidos (campañas de pago, orgánico, social, email, directo…).

## Qué hace (v0.2.0, PrestaShop 8.x)

- **Visitas (sesiones):** cada visita se registra con su canal de entrada. Una visita termina tras 30 min sin actividad.
- **Captura:** UTM, click IDs (`gclid`, `gbraid`, `wbraid`, `fbclid`, `msclkid`, `ttclid`) y referrer. Historial de touchpoints en cookie propia (90 días).
- **Pedidos:** primer y último clic, touchpoints completos y la visita que originó el pedido.
- **Panel:** Estadísticas → Attribution. KPIs de visitas, pedidos, ingresos y tasa de conversión; tabla por canal (visitas, pedidos, conversión, ingresos, ticket medio) y últimos pedidos. Filtro por fechas.
- **Tests:** `php tests/ChannelClassifierTest.php` (sin PrestaShop).

## Cómo se calcula la conversión

Las visitas se agrupan por su canal de entrada. Cada pedido se asigna a la visita que lo originó. Tasa de conversión = pedidos / visitas del mismo canal. Los pedidos sin visita asociada (cookies deshabilitadas) aparecen como `sin_sesion`.

## Limitaciones conocidas

- Sin consentimiento de cookies no hay visita asociada: los números son una estimación por debajo de la realidad.
- Safari/Firefox acortan las cookies: las visitas recurrentes pueden contarse como nuevas.
- Sin ROAS todavía (falta importar el gasto de las campañas).
- Sin Consent Mode v2 ni integración con CMP.

## Estructura

```
attribution/
├── attribution.php                      # Módulo: hooks, sesiones y guardado de pedidos
├── classes/ChannelClassifier.php        # Reglas de canal (sin dependencias de PS)
├── classes/AttributionReport.php        # Consultas del panel
├── controllers/admin/AdminAttributionController.php  # Página del admin
└── sql/install.php | uninstall.php      # Tablas attribution_session y attribution_order
tests/ChannelClassifierTest.php
```

## Actualizar y desinstalar

- **Actualizar:** sube el zip nuevo sobre la versión instalada. Los datos se conservan.
- **Desinstalar:** quita el módulo y su menú, pero **conserva las tablas y los datos**. Al reinstalarlo, el historial sigue ahí.
- Los cambios de esquema futuros deben llevar un script en `upgrade/`.

## Pendiente

- [ ] Consent Mode v2 / integración con CMP
- [ ] Reembolsos y cancelaciones (ingresos netos)
- [ ] Importación de costes (Windsor.ai) y ROAS
- [ ] PrestaShop 1.7
- [ ] Export a GA4 (Measurement Protocol)
