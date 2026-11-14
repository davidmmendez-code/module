# PrestaShop Attribution

Módulo para medir de qué canal llega cada pedido (campañas de pago, orgánico, social, email, directo…).

## Estado

- [x] **PrestaShop 8.x** – MVP en progreso
  - Captura de UTM y click IDs (`gclid`, `fbclid`, `msclkid`, `ttclid`…) y referrer
  - Historial de touchpoints en cookie propia (90 días, máx. 20)
  - Atribución de primer y último clic guardada al validar el pedido
- [ ] Panel de administración (informes por canal)
- [ ] Consent Mode v2 / integración con CMP
- [ ] Reembolsos y cancelaciones (ingresos netos)
- [ ] Importación de costes (Windsor.ai) y ROAS
- [ ] PrestaShop 1.7
- [ ] Export de GA4 (Measurement Protocol) como opción

## Estructura

```
attribution/
├── attribution.php              # Módulo: hooks y guardado
├── classes/ChannelClassifier.php # Reglas de canal (sin dependencias de PS)
└── sql/install.php|uninstall.php
```

## Notas

- Los canales se calculan en `ChannelClassifier`, así que se pueden ajustar sin tocar la captura.
- Una visita sin ninguna señal (navegación directa o interna) no sobrescribe la atribución anterior.
