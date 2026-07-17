# Rendimiento del checkout — auditoría y recomendaciones (TTFB / CDN)

Documento para el equipo de hosting/infraestructura. Resume el estado de Core Web
Vitals del checkout `/pago/` y qué queda **fuera del plugin** (servidor/CDN).

## 1. Medición de referencia (dev, chrome-devtools, sin throttling)

| Métrica | Valor | Lectura |
|---|---|---|
| **LCP** | ~3.58 s | Malo, pero **dominado por TTFB** (ver desglose). |
| ↳ TTFB | ~3.09 s | **Cuello de botella real** (respuesta del servidor). |
| ↳ Render delay | ~0.49 s | Bien: una vez llega el HTML, pinta casi de inmediato. |
| **CLS** | 0.01 | Bien (≤0.1). |

**Conclusión:** el LCP NO está limitado por assets ni por el front del plugin, sino
por el **tiempo de respuesta del servidor (TTFB ≈ 3 s)**. El insight `DocumentLatency`
de Chrome estima ~3 s de ahorro potencial actuando sobre la respuesta del documento.

## 2. Lo que el plugin YA resuelve (no tocar)

- **Assets contextuales:** CSS/JS solo se encolan en `is_checkout()` (`CCMCK_Assets`).
- **Limpieza de terceros:** `CCMCK_Dequeue` desencola Elementor/EAEL/lightslider/etc.
  en el checkout (menos render y menos JS basura).
- **Logo (LCP) priorizado:** `fetchpriority="high"`, `loading="eager"`, `decoding="async"`,
  `width/height/srcset` + `<link rel="preload" as="image">` en `wp_head`.
- **Miniaturas de producto:** lazy nativo (`wp_get_attachment_image`).
- **CLS:** estable en 0.01 (logo con dimensiones + `min-height` del header).
- **Cache-busting de assets:** `filemtime()` en `CCMCK_Assets::asset_version()` +
  `force_version()` que reañade `?ver=` aunque un stripper global lo quite.

## 3. Recomendaciones de TTFB (servidor) — prioridad ALTA

El checkout es **dinámico y personalizado** (carrito, sesión, nonces): NO se puede
cachear la página completa. Por eso el TTFB depende de lo rápido que PHP genere el HTML.

1. **OPcache activado y bien dimensionado** (PHP). Tras desplegar cambios PHP hay que
   purgarlo; ya es parte del flujo de deploy.
2. **Object Cache persistente (Redis o Memcached).** Es lo de mayor impacto en TTFB de
   WooCommerce: cachea queries de opciones/transients/sesiones. Hostinger/hPanel ofrece
   Redis en planes superiores; activarlo + `redis-cache` (plugin) o equivalente.
3. **PHP 8.1+ y workers suficientes.** Verificar versión y número de PHP-FPM workers;
   en checkout concurrente, pocos workers disparan el TTFB.
4. **Base de datos:** revisar `wp_options` (autoload), limpiar transients huérfanos,
   índices. WooCommerce con muchas opciones autoload infla cada request.
5. **HPOS (High-Performance Order Storage)** de WooCommerce activado (tablas propias de
   pedidos en vez de `wp_posts`) → menos carga en checkout/admin.
6. **Menos plugins activos en la ruta del checkout.** El dequeue del front ya quita el
   CSS/JS, pero el **PHP** de esos plugins sigue ejecutándose. Auditar qué plugins
   corren en `/pago/` y desactivar los que no aporten (p. ej. el mu-plugin viejo
   `disable-elementor-pro-on-checkout.php` no dispara porque busca `/checkout` y la URL
   real es `/pago/` — corregir su condición para descargar también el PHP de Elementor Pro).
7. **Compresión y HTTP/2/3** en el servidor (gzip/brotli, ya parece activo: `content-encoding: br`).

## 4. Recomendaciones de CDN / edge — prioridad MEDIA

**Regla de oro:** cachear en el borde los **assets estáticos**, NUNCA el HTML del checkout.

- **NO** cachear como página completa: `/pago/`, `/finalizar-compra/`, carrito,
  mi-cuenta, order-received. Llevan nonces/carrito/sesión; cachearlas filtra datos entre
  usuarios y rompe la seguridad. WooCommerce marca estas rutas con `DONOTCACHEPAGE`; si
  hay un page-cache/CDN agresivo, **excluir explícitamente** esas URLs y respetar las
  cookies de WooCommerce (`woocommerce_items_in_cart`, `woocommerce_cart_hash`,
  `wp_woocommerce_session_*`, `wordpress_logged_in_*`).
- **SÍ** servir por CDN con caché larga los estáticos (`.css`, `.js`, imágenes, fuentes):
  `Cache-Control: public, max-age=31536000, immutable`. Es seguro porque el plugin hace
  cache-busting por `filemtime` (`?ver=<mtime>`), así que al editar un asset cambia la URL.
- **OJO con el stripper de `?ver=`:** en este sitio un optimizador (probable snippet/tema)
  elimina el query string de los assets. El plugin lo contrarresta SOLO para sus 2 assets
  (`force_version`). Si se pone un CDN que ignore query strings, el cache-busting de
  ccmck seguirá funcionando, pero conviene **localizar y desactivar ese stripper** para
  el resto del sitio (busca en `functions.php` del tema / Code Snippets un filtro sobre
  `style_loader_src`/`script_loader_src`).
- **`preconnect`/`dns-prefetch`** a los hosts de terceros que sí se usan en checkout
  (pasarelas: `secure-fields.mercadopago.com`, `checkout.wompi.co`, `api.addi.com`,
  `mediodepago.sistecredito.com`) para acelerar el handoff de pago. Se pueden añadir en
  `wp_head` (similar a `CCMCK_Assets::preload_lcp()`), solo en `is_checkout()`.

## 5. Qué NO perseguir

- No intentar bajar el LCP optimizando imágenes/JS del checkout: el render delay ya es
  ~0.5 s; el resto es servidor. El retorno está en TTFB (sección 3), no en el front.
- No cachear el HTML del checkout en CDN/page-cache (sección 4).

---

*Mediciones tomadas en `dev.dev.ccmtiendadelsonido.com/pago/`. En producción, validar con
campo real (CrUX/RUM) además del lab.*
