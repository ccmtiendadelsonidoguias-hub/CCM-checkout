<?php
/**
 * Settings page view for CCM Checkout.
 *
 * Variables available in scope (provided by CCMCK_Settings::render_page()):
 *   $s  (array) — current settings merged with defaults.
 *
 * All fields are named under ccmck_settings[...] so the sanitize callback
 * receives the full structure via WordPress Settings API.
 *
 * @package CCM_Checkout
 */

defined( 'ABSPATH' ) || exit;
?>

<style>
/* Pestañas de la página de ajustes (agrupan las secciones por categoría). */
.ccmck-tab-panel { display: none; }
.ccmck-tab-panel.is-active { display: block; }
.ccmck-tabs.nav-tab-wrapper { margin-bottom: 1em; }
</style>

<h2 class="nav-tab-wrapper ccmck-tabs">
	<a href="#diseno"      class="nav-tab nav-tab-active" data-tab="diseno"><?php esc_html_e( 'Marca y diseño', 'ccm-checkout' ); ?></a>
	<a href="#contenido"   class="nav-tab" data-tab="contenido"><?php esc_html_e( 'Contenido', 'ccm-checkout' ); ?></a>
	<a href="#postgeneral" class="nav-tab" data-tab="postgeneral"><?php esc_html_e( 'Post-compra y general', 'ccm-checkout' ); ?></a>
	<a href="#pagos"       class="nav-tab" data-tab="pagos"><?php esc_html_e( 'Pagos', 'ccm-checkout' ); ?></a>
</h2>

<?php /* ===== PESTAÑA: MARCA Y DISEÑO ===== */ ?>
<div class="ccmck-tab-panel is-active" data-tab="diseno">

<?php /* ============================================================
   SECCIÓN 1 — MARCA
   ============================================================ */ ?>
<h2><?php esc_html_e( 'Marca', 'ccm-checkout' ); ?></h2>
<table class="form-table" role="presentation">
    <tr>
        <th scope="row"><label for="ccmck-logo-text-1"><?php esc_html_e( 'Texto logo (1ª línea)', 'ccm-checkout' ); ?></label></th>
        <td>
            <input type="text" id="ccmck-logo-text-1" name="ccmck_settings[logo_text_1]"
                   value="<?php echo esc_attr( $s['logo_text_1'] ); ?>" class="regular-text">
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="ccmck-logo-text-2"><?php esc_html_e( 'Texto logo (2ª línea)', 'ccm-checkout' ); ?></label></th>
        <td>
            <input type="text" id="ccmck-logo-text-2" name="ccmck_settings[logo_text_2]"
                   value="<?php echo esc_attr( $s['logo_text_2'] ); ?>" class="regular-text">
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="ccmck-logo-image"><?php esc_html_e( 'URL imagen logo', 'ccm-checkout' ); ?></label></th>
        <td>
            <input type="text" id="ccmck-logo-image" name="ccmck_settings[logo_image]"
                   value="<?php echo esc_url( $s['logo_image'] ); ?>" class="large-text ccmck-url-input">
            <button type="button" class="button ccmck-upload" data-target="ccmck-logo-image">
                <?php esc_html_e( 'Subir', 'ccm-checkout' ); ?>
            </button>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="ccmck-accent-color"><?php esc_html_e( 'Color de acento', 'ccm-checkout' ); ?></label></th>
        <td>
            <input type="text" id="ccmck-accent-color" name="ccmck_settings[accent_color]"
                   value="<?php echo esc_attr( $s['accent_color'] ); ?>" class="ccmck-color">
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="ccmck-sidebar-color"><?php esc_html_e( 'Color de barra lateral', 'ccm-checkout' ); ?></label></th>
        <td>
            <input type="text" id="ccmck-sidebar-color" name="ccmck_settings[sidebar_color]"
                   value="<?php echo esc_attr( $s['sidebar_color'] ); ?>" class="ccmck-color">
        </td>
    </tr>
</table>

</div><?php /* /panel diseno */ ?>

<?php /* ===== PESTAÑA: CONTENIDO ===== */ ?>
<div class="ccmck-tab-panel" data-tab="contenido">

<?php /* ============================================================
   SECCIÓN 2 — CABECERA (enlaces)
   ============================================================ */ ?>
<h2><?php esc_html_e( 'Cabecera', 'ccm-checkout' ); ?></h2>
<p class="description"><?php esc_html_e( 'Enlaces que aparecen en la cabecera del checkout.', 'ccm-checkout' ); ?></p>

<div class="ccmck-repeater" data-field="header_links" id="ccmck-repeater-header_links">
    <?php foreach ( $s['header_links'] as $i => $row ) : ?>
    <div class="ccmck-row" data-index="<?php echo esc_attr( (string) $i ); ?>">
        <input type="text"
               name="ccmck_settings[header_links][<?php echo esc_attr( (string) $i ); ?>][label]"
               value="<?php echo esc_attr( $row['label'] ); ?>"
               placeholder="<?php esc_attr_e( 'Etiqueta', 'ccm-checkout' ); ?>"
               class="regular-text">
        <input type="url"
               name="ccmck_settings[header_links][<?php echo esc_attr( (string) $i ); ?>][url]"
               value="<?php echo esc_attr( esc_url( $row['url'] ) ); ?>"
               placeholder="https://"
               class="regular-text">
        <button type="button" class="button ccmck-remove-row"><?php esc_html_e( 'Eliminar', 'ccm-checkout' ); ?></button>
    </div>
    <?php endforeach; ?>

    <div class="ccmck-row-template ccmck-row" style="display:none" data-index="__i__">
        <input type="text"
               name="ccmck_settings[header_links][__i__][label]"
               value=""
               placeholder="<?php esc_attr_e( 'Etiqueta', 'ccm-checkout' ); ?>"
               class="regular-text">
        <input type="url"
               name="ccmck_settings[header_links][__i__][url]"
               value=""
               placeholder="https://"
               class="regular-text">
        <button type="button" class="button ccmck-remove-row"><?php esc_html_e( 'Eliminar', 'ccm-checkout' ); ?></button>
    </div>

    <button type="button" class="button ccmck-add-row" data-repeater="ccmck-repeater-header_links">
        <?php esc_html_e( '+ Añadir enlace', 'ccm-checkout' ); ?>
    </button>
</div>

<?php /* ============================================================
   SECCIÓN 3 — FOOTER (enlaces)
   ============================================================ */ ?>
<h2><?php esc_html_e( 'Footer', 'ccm-checkout' ); ?></h2>
<p class="description"><?php esc_html_e( 'Enlaces que aparecen en el pie de página del checkout.', 'ccm-checkout' ); ?></p>

<div class="ccmck-repeater" data-field="footer_links" id="ccmck-repeater-footer_links">
    <?php foreach ( $s['footer_links'] as $i => $row ) : ?>
    <div class="ccmck-row" data-index="<?php echo esc_attr( (string) $i ); ?>">
        <input type="text"
               name="ccmck_settings[footer_links][<?php echo esc_attr( (string) $i ); ?>][label]"
               value="<?php echo esc_attr( $row['label'] ); ?>"
               placeholder="<?php esc_attr_e( 'Etiqueta', 'ccm-checkout' ); ?>"
               class="regular-text">
        <input type="url"
               name="ccmck_settings[footer_links][<?php echo esc_attr( (string) $i ); ?>][url]"
               value="<?php echo esc_attr( esc_url( $row['url'] ) ); ?>"
               placeholder="https://"
               class="regular-text">
        <button type="button" class="button ccmck-remove-row"><?php esc_html_e( 'Eliminar', 'ccm-checkout' ); ?></button>
    </div>
    <?php endforeach; ?>

    <div class="ccmck-row-template ccmck-row" style="display:none" data-index="__i__">
        <input type="text"
               name="ccmck_settings[footer_links][__i__][label]"
               value=""
               placeholder="<?php esc_attr_e( 'Etiqueta', 'ccm-checkout' ); ?>"
               class="regular-text">
        <input type="url"
               name="ccmck_settings[footer_links][__i__][url]"
               value=""
               placeholder="https://"
               class="regular-text">
        <button type="button" class="button ccmck-remove-row"><?php esc_html_e( 'Eliminar', 'ccm-checkout' ); ?></button>
    </div>

    <button type="button" class="button ccmck-add-row" data-repeater="ccmck-repeater-footer_links">
        <?php esc_html_e( '+ Añadir enlace', 'ccm-checkout' ); ?>
    </button>
</div>

<?php /* ============================================================
   SECCIÓN 4 — WHATSAPP
   ============================================================ */ ?>
<h2><?php esc_html_e( 'WhatsApp', 'ccm-checkout' ); ?></h2>
<table class="form-table" role="presentation">
    <tr>
        <th scope="row"><?php esc_html_e( 'Activar botón WhatsApp', 'ccm-checkout' ); ?></th>
        <td>
            <label>
                <input type="checkbox" name="ccmck_settings[whatsapp_enabled]" value="1"
                    <?php checked( $s['whatsapp_enabled'] ); ?>>
                <?php esc_html_e( 'Mostrar botón de WhatsApp en el checkout', 'ccm-checkout' ); ?>
            </label>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="ccmck-whatsapp-number"><?php esc_html_e( 'Número de WhatsApp', 'ccm-checkout' ); ?></label></th>
        <td>
            <input type="text" id="ccmck-whatsapp-number" name="ccmck_settings[whatsapp_number]"
                   value="<?php echo esc_attr( $s['whatsapp_number'] ); ?>" class="regular-text">
            <p class="description"><?php esc_html_e( 'Solo dígitos, con código de país. Ej: 573178119077', 'ccm-checkout' ); ?></p>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="ccmck-whatsapp-title"><?php esc_html_e( 'Título', 'ccm-checkout' ); ?></label></th>
        <td>
            <input type="text" id="ccmck-whatsapp-title" name="ccmck_settings[whatsapp_title]"
                   value="<?php echo esc_attr( $s['whatsapp_title'] ); ?>" class="large-text">
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="ccmck-whatsapp-subtitle"><?php esc_html_e( 'Subtítulo', 'ccm-checkout' ); ?></label></th>
        <td>
            <input type="text" id="ccmck-whatsapp-subtitle" name="ccmck_settings[whatsapp_subtitle]"
                   value="<?php echo esc_attr( $s['whatsapp_subtitle'] ); ?>" class="large-text">
        </td>
    </tr>
</table>

<?php /* ============================================================
   SECCIÓN 5 — FAQ
   ============================================================ */ ?>
<h2><?php esc_html_e( 'FAQ', 'ccm-checkout' ); ?></h2>
<table class="form-table" role="presentation">
    <tr>
        <th scope="row"><?php esc_html_e( 'Activar sección FAQ', 'ccm-checkout' ); ?></th>
        <td>
            <label>
                <input type="checkbox" name="ccmck_settings[faq_enabled]" value="1"
                    <?php checked( $s['faq_enabled'] ); ?>>
                <?php esc_html_e( 'Mostrar sección de preguntas frecuentes', 'ccm-checkout' ); ?>
            </label>
        </td>
    </tr>
</table>

<p class="description"><?php esc_html_e( 'Preguntas frecuentes del checkout.', 'ccm-checkout' ); ?></p>
<div class="ccmck-repeater" data-field="faq_items" id="ccmck-repeater-faq_items">
    <?php foreach ( $s['faq_items'] as $i => $row ) : ?>
    <div class="ccmck-row ccmck-row--faq" data-index="<?php echo esc_attr( (string) $i ); ?>">
        <input type="text"
               name="ccmck_settings[faq_items][<?php echo esc_attr( (string) $i ); ?>][q]"
               value="<?php echo esc_attr( $row['q'] ); ?>"
               placeholder="<?php esc_attr_e( 'Pregunta', 'ccm-checkout' ); ?>"
               class="large-text">
        <textarea name="ccmck_settings[faq_items][<?php echo esc_attr( (string) $i ); ?>][a]"
                  rows="3" class="large-text"
                  placeholder="<?php esc_attr_e( 'Respuesta', 'ccm-checkout' ); ?>"><?php echo esc_textarea( $row['a'] ); ?></textarea>
        <button type="button" class="button ccmck-remove-row"><?php esc_html_e( 'Eliminar', 'ccm-checkout' ); ?></button>
    </div>
    <?php endforeach; ?>

    <div class="ccmck-row-template ccmck-row ccmck-row--faq" style="display:none" data-index="__i__">
        <input type="text"
               name="ccmck_settings[faq_items][__i__][q]"
               value=""
               placeholder="<?php esc_attr_e( 'Pregunta', 'ccm-checkout' ); ?>"
               class="large-text">
        <textarea name="ccmck_settings[faq_items][__i__][a]"
                  rows="3" class="large-text"
                  placeholder="<?php esc_attr_e( 'Respuesta', 'ccm-checkout' ); ?>"></textarea>
        <button type="button" class="button ccmck-remove-row"><?php esc_html_e( 'Eliminar', 'ccm-checkout' ); ?></button>
    </div>

    <button type="button" class="button ccmck-add-row" data-repeater="ccmck-repeater-faq_items">
        <?php esc_html_e( '+ Añadir pregunta', 'ccm-checkout' ); ?>
    </button>
</div>

<?php /* ============================================================
   SECCIÓN 6 — ENVÍO (tarjetas informativas)
   ============================================================ */ ?>
<h2><?php esc_html_e( 'Envío', 'ccm-checkout' ); ?></h2>
<p class="description"><?php esc_html_e( 'Tarjetas informativas de métodos / políticas de envío.', 'ccm-checkout' ); ?></p>

<div class="ccmck-repeater" data-field="shipping_cards" id="ccmck-repeater-shipping_cards">
    <?php foreach ( $s['shipping_cards'] as $i => $row ) : ?>
    <div class="ccmck-row ccmck-row--card" data-index="<?php echo esc_attr( (string) $i ); ?>">
        <input type="text"
               name="ccmck_settings[shipping_cards][<?php echo esc_attr( (string) $i ); ?>][icon]"
               value="<?php echo esc_attr( $row['icon'] ); ?>"
               placeholder="<?php esc_attr_e( 'Icono (dashicon o clase CSS)', 'ccm-checkout' ); ?>"
               class="regular-text">
        <input type="text"
               name="ccmck_settings[shipping_cards][<?php echo esc_attr( (string) $i ); ?>][title]"
               value="<?php echo esc_attr( $row['title'] ); ?>"
               placeholder="<?php esc_attr_e( 'Título', 'ccm-checkout' ); ?>"
               class="regular-text">
        <input type="text"
               name="ccmck_settings[shipping_cards][<?php echo esc_attr( (string) $i ); ?>][text]"
               value="<?php echo esc_attr( $row['text'] ); ?>"
               placeholder="<?php esc_attr_e( 'Descripción', 'ccm-checkout' ); ?>"
               class="large-text">
        <button type="button" class="button ccmck-remove-row"><?php esc_html_e( 'Eliminar', 'ccm-checkout' ); ?></button>
    </div>
    <?php endforeach; ?>

    <div class="ccmck-row-template ccmck-row ccmck-row--card" style="display:none" data-index="__i__">
        <input type="text"
               name="ccmck_settings[shipping_cards][__i__][icon]"
               value=""
               placeholder="<?php esc_attr_e( 'Icono (dashicon o clase CSS)', 'ccm-checkout' ); ?>"
               class="regular-text">
        <input type="text"
               name="ccmck_settings[shipping_cards][__i__][title]"
               value=""
               placeholder="<?php esc_attr_e( 'Título', 'ccm-checkout' ); ?>"
               class="regular-text">
        <input type="text"
               name="ccmck_settings[shipping_cards][__i__][text]"
               value=""
               placeholder="<?php esc_attr_e( 'Descripción', 'ccm-checkout' ); ?>"
               class="large-text">
        <button type="button" class="button ccmck-remove-row"><?php esc_html_e( 'Eliminar', 'ccm-checkout' ); ?></button>
    </div>

    <button type="button" class="button ccmck-add-row" data-repeater="ccmck-repeater-shipping_cards">
        <?php esc_html_e( '+ Añadir tarjeta', 'ccm-checkout' ); ?>
    </button>
</div>

<table class="form-table" role="presentation">
    <tr>
        <th scope="row"><label for="ccmck-secure-badge"><?php esc_html_e( 'Texto insignia de seguridad', 'ccm-checkout' ); ?></label></th>
        <td>
            <input type="text" id="ccmck-secure-badge" name="ccmck_settings[secure_badge]"
                   value="<?php echo esc_attr( $s['secure_badge'] ); ?>" class="large-text">
        </td>
    </tr>
</table>

</div><?php /* /panel contenido */ ?>

<?php /* ===== PESTAÑA: POST-COMPRA Y GENERAL ===== */ ?>
<div class="ccmck-tab-panel" data-tab="postgeneral">

<?php /* ============================================================
   SECCIÓN 7 — GRACIAS / TRACKER
   ============================================================ */ ?>
<h2><?php esc_html_e( 'Página de gracias', 'ccm-checkout' ); ?></h2>
<table class="form-table" role="presentation">
    <tr>
        <th scope="row"><?php esc_html_e( 'Activar tracker de pedido', 'ccm-checkout' ); ?></th>
        <td>
            <label>
                <input type="checkbox" name="ccmck_settings[tracker_enabled]" value="1"
                    <?php checked( $s['tracker_enabled'] ); ?>>
                <?php esc_html_e( 'Mostrar barra de progreso del pedido', 'ccm-checkout' ); ?>
            </label>
        </td>
    </tr>
    <?php
    $tracker_defaults = array( 'Pedido recibido', 'Pago confirmado', 'En preparación', 'Enviado' );
    $tracker_labels   = array_slice( (array) $s['tracker_labels'], 0, 4 );
    // Pad to 4 with defaults if fewer saved.
    while ( count( $tracker_labels ) < 4 ) {
        $tracker_labels[] = $tracker_defaults[ count( $tracker_labels ) ];
    }
    for ( $i = 0; $i < 4; $i++ ) :
    ?>
    <tr>
        <th scope="row">
            <label for="ccmck-tracker-label-<?php echo esc_attr( (string) $i ); ?>">
                <?php
                /* translators: %d: step number 1-4 */
                printf( esc_html__( 'Etiqueta paso %d', 'ccm-checkout' ), $i + 1 );
                ?>
            </label>
        </th>
        <td>
            <input type="text"
                   id="ccmck-tracker-label-<?php echo esc_attr( (string) $i ); ?>"
                   name="ccmck_settings[tracker_labels][<?php echo esc_attr( (string) $i ); ?>]"
                   value="<?php echo esc_attr( $tracker_labels[ $i ] ); ?>"
                   class="regular-text">
        </td>
    </tr>
    <?php endfor; ?>
    <tr>
        <th scope="row"><label for="ccmck-thankyou-message"><?php esc_html_e( 'Mensaje de agradecimiento', 'ccm-checkout' ); ?></label></th>
        <td>
            <input type="text" id="ccmck-thankyou-message" name="ccmck_settings[thankyou_message]"
                   value="<?php echo esc_attr( $s['thankyou_message'] ); ?>" class="large-text">
        </td>
    </tr>
</table>

<?php /* ============================================================
   SECCIÓN 8 — GENERAL
   ============================================================ */ ?>
<h2><?php esc_html_e( 'General', 'ccm-checkout' ); ?></h2>
<table class="form-table" role="presentation">
    <tr>
        <th scope="row"><?php esc_html_e( 'Newsletter', 'ccm-checkout' ); ?></th>
        <td>
            <label>
                <input type="checkbox" name="ccmck_settings[newsletter_enabled]" value="1"
                    <?php checked( $s['newsletter_enabled'] ); ?>>
                <?php esc_html_e( 'Mostrar opción de suscripción a newsletter', 'ccm-checkout' ); ?>
            </label>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="ccmck-newsletter-text"><?php esc_html_e( 'Texto del checkbox newsletter', 'ccm-checkout' ); ?></label></th>
        <td>
            <input type="text" id="ccmck-newsletter-text" name="ccmck_settings[newsletter_text]"
                   value="<?php echo esc_attr( $s['newsletter_text'] ); ?>" class="large-text">
        </td>
    </tr>
</table>

</div><?php /* /panel postgeneral */ ?>

<?php /* ===== PESTAÑA: PAGOS ===== */ ?>
<div class="ccmck-tab-panel" data-tab="pagos">

<?php /* ============================================================
   SECCIÓN — ORDEN DEL CHECKOUT (experimental)
   ============================================================ */ ?>
<h2><?php esc_html_e( 'Orden del checkout', 'ccm-checkout' ); ?></h2>
<table class="form-table" role="presentation">
    <tr>
        <th scope="row"><?php esc_html_e( 'Métodos de pago primero', 'ccm-checkout' ); ?></th>
        <td>
            <label>
                <input type="checkbox" name="ccmck_settings[checkout_payment_first]" value="1"
                    <?php checked( ! empty( $s['checkout_payment_first'] ) ); ?>>
                <?php esc_html_e( 'Mostrar los métodos de pago al inicio del checkout (experimental)', 'ccm-checkout' ); ?>
            </label>
            <p class="description">
                <?php esc_html_e( 'Sube la selección de método de pago al inicio de la columna; el botón "Realizar el pedido" se mantiene al final. El total y las pasarelas se recalculan igual al cambiar dirección/envío.', 'ccm-checkout' ); ?>
            </p>
        </td>
    </tr>
</table>

<?php /* ============================================================
   SECCIÓN — RECARGO POR FINANCIACIÓN
   ============================================================ */ ?>
<h2><?php esc_html_e( 'Recargo por financiación', 'ccm-checkout' ); ?></h2>
<table class="form-table" role="presentation">
    <tr>
        <th scope="row"><label for="ccmck-surcharge-rate"><?php esc_html_e( 'Porcentaje de recargo (%)', 'ccm-checkout' ); ?></label></th>
        <td>
            <input type="number" id="ccmck-surcharge-rate" name="ccmck_settings[surcharge_rate]"
                   value="<?php echo esc_attr( (string) $s['surcharge_rate'] ); ?>"
                   class="small-text" step="0.01" min="0" max="100">
            <span>%</span>
            <p class="description">
                <?php esc_html_e( 'Se aplica al precio de los productos cuando se paga con Addi o Sistecrédito (sin línea de recargo aparte; sube el subtotal y el total). Usa 0 para desactivarlo.', 'ccm-checkout' ); ?>
            </p>
        </td>
    </tr>
</table>

<?php /* ============================================================
   SECCIÓN 9 — MÉTODOS DE PAGO
   ============================================================ */ ?>
<h2><?php esc_html_e( 'Métodos de pago', 'ccm-checkout' ); ?></h2>
<p class="description">
    <?php esc_html_e( 'Arrastra las filas para reordenar. Los íconos y colores de cada método se personalizan aquí.', 'ccm-checkout' ); ?>
</p>

<?php
// Guard: WooCommerce must be loaded to iterate gateways.
$gateways = array();
if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
    $gateways = WC()->payment_gateways()->payment_gateways();
}

// Determine display order: payment_order first (saved), then remaining gateways.
$ordered_ids   = array_filter( (array) $s['payment_order'], 'is_string' );
$remaining_ids = array_diff( array_keys( $gateways ), $ordered_ids );
$display_ids   = array_merge( $ordered_ids, $remaining_ids );
// Only include ids that actually exist.
$display_ids   = array_values( array_filter( $display_ids, function ( $id ) use ( $gateways ) {
    return isset( $gateways[ $id ] );
} ) );
?>

<?php if ( empty( $gateways ) ) : ?>
    <p class="description" style="color:#787c82;">
        <?php esc_html_e( 'WooCommerce no está disponible o no hay pasarelas de pago registradas.', 'ccm-checkout' ); ?>
    </p>
<?php else : ?>

<div id="ccmck-payment-gateways-list" class="ccmck-gateways-list">
    <?php foreach ( $display_ids as $gw_id ) :
        /** @var WC_Payment_Gateway $gw */
        $gw      = $gateways[ $gw_id ];
        $icons   = isset( $s['payment_icons'][ $gw_id ] ) ? $s['payment_icons'][ $gw_id ] : array();
        $icon_label = isset( $icons['label'] ) ? $icons['label'] : '';
        $icon_image = isset( $icons['image'] ) ? $icons['image'] : '';
        $icon_bg    = isset( $icons['bg'] )    ? $icons['bg']    : '';
    ?>
    <div class="ccmck-gateway-row" data-gateway-id="<?php echo esc_attr( $gw_id ); ?>">
        <span class="ccmck-gateway-handle dashicons dashicons-menu" title="<?php esc_attr_e( 'Arrastrar para reordenar', 'ccm-checkout' ); ?>"></span>

        <div class="ccmck-gateway-info">
            <strong><?php echo esc_html( $gw->get_title() ); ?></strong>
            <code><?php echo esc_html( $gw_id ); ?></code>
        </div>

        <div class="ccmck-gateway-fields">
            <label>
                <input type="checkbox"
                       name="ccmck_settings[payment_hidden][]"
                       value="<?php echo esc_attr( $gw_id ); ?>"
                    <?php checked( in_array( $gw_id, (array) $s['payment_hidden'], true ) ); ?>>
                <?php esc_html_e( 'Ocultar', 'ccm-checkout' ); ?>
            </label>

            <label>
                <?php esc_html_e( 'Etiqueta icono:', 'ccm-checkout' ); ?>
                <input type="text"
                       name="ccmck_settings[payment_icons][<?php echo esc_attr( $gw_id ); ?>][label]"
                       value="<?php echo esc_attr( $icon_label ); ?>"
                       class="regular-text"
                       placeholder="<?php esc_attr_e( 'Ej: Visa', 'ccm-checkout' ); ?>">
            </label>

            <label>
                <?php esc_html_e( 'Imagen icono (URL):', 'ccm-checkout' ); ?>
                <input type="text"
                       name="ccmck_settings[payment_icons][<?php echo esc_attr( $gw_id ); ?>][image]"
                       value="<?php echo esc_url( $icon_image ); ?>"
                       class="regular-text ccmck-url-input"
                       placeholder="https://">
                <button type="button"
                        class="button ccmck-upload"
                        data-target-name="ccmck_settings[payment_icons][<?php echo esc_attr( $gw_id ); ?>][image]">
                    <?php esc_html_e( 'Subir', 'ccm-checkout' ); ?>
                </button>
            </label>

            <label>
                <?php esc_html_e( 'Color fondo icono:', 'ccm-checkout' ); ?>
                <input type="text"
                       name="ccmck_settings[payment_icons][<?php echo esc_attr( $gw_id ); ?>][bg]"
                       value="<?php echo esc_attr( $icon_bg ); ?>"
                       class="ccmck-color">
            </label>
        </div>

        <?php /* Hidden input updated by JS on submit to reflect current sort order. */ ?>
        <input type="hidden" class="ccmck-payment-order-input" name="ccmck_settings[payment_order][]"
               value="<?php echo esc_attr( $gw_id ); ?>">
    </div>
    <?php endforeach; ?>
</div>

<?php endif; ?>

</div><?php /* /panel pagos */ ?>
