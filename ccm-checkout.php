<?php
/**
 * CCM Checkout — bootstrap.
 * Ubicación: /wp-content/mu-plugins/ccm-checkout/ccm-checkout.php
 *
 * @package CCM_Checkout
 */

defined( 'ABSPATH' ) || exit;

define( 'CCMCK_VERSION', '1.2.0' );
define( 'CCMCK_DIR', trailingslashit( dirname( __FILE__ ) ) );
define( 'CCMCK_URL', plugin_dir_url( __FILE__ ) );

require_once CCMCK_DIR . 'includes/class-ccmck-settings.php';
require_once CCMCK_DIR . 'includes/class-ccmck-branding.php';
require_once CCMCK_DIR . 'includes/class-ccmck-templates.php';
require_once CCMCK_DIR . 'includes/class-ccmck-assets.php';
require_once CCMCK_DIR . 'includes/class-ccmck-document.php';
require_once CCMCK_DIR . 'includes/class-ccmck-payments.php';
require_once CCMCK_DIR . 'includes/class-ccmck-cart-ajax.php';
require_once CCMCK_DIR . 'includes/class-ccmck-faq.php';
require_once CCMCK_DIR . 'includes/class-ccmck-info-cards.php';
require_once CCMCK_DIR . 'includes/class-ccmck-whatsapp.php';
require_once CCMCK_DIR . 'includes/class-ccmck-thankyou.php';
require_once CCMCK_DIR . 'includes/class-ccmck-shipping.php';
require_once CCMCK_DIR . 'includes/class-ccmck-pickup.php';
require_once CCMCK_DIR . 'includes/class-ccmck-cities.php';
require_once CCMCK_DIR . 'includes/class-ccmck-dequeue.php';
require_once CCMCK_DIR . 'includes/class-ccmck-wompi.php';
require_once CCMCK_DIR . 'includes/class-ccmck-newsletter.php';
require_once CCMCK_DIR . 'includes/class-ccmck-surcharge.php';
require_once CCMCK_DIR . 'includes/class-ccmck-coordinadora.php';
require_once CCMCK_DIR . 'includes/class-ccmck-cart-shipping.php';
require_once CCMCK_DIR . 'includes/class-ccmck-guias.php';
require_once CCMCK_DIR . 'includes/class-ccmck-cotizar.php';
require_once CCMCK_DIR . 'includes/class-ccmck-reports.php';
require_once CCMCK_DIR . 'includes/class-ccmck-cart-redirect.php';

add_action( 'plugins_loaded', 'ccmck_boot', 20 );
function ccmck_boot(): void {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>'
                . esc_html( 'CCM Checkout requiere WooCommerce activo.' )
                . '</p></div>';
        } );
        return;
    }

    CCMCK_Settings::init();
    CCMCK_Branding::init();
    CCMCK_Templates::init();
    CCMCK_Assets::init();
    CCMCK_Document::init();
    CCMCK_Payments::init();
    CCMCK_Cart_Ajax::init();
    CCMCK_Faq::init();
    CCMCK_Info_Cards::init();
    CCMCK_Thankyou::init();
    CCMCK_Shipping::init();
    CCMCK_Pickup::init();
    CCMCK_Cities::init();
    CCMCK_Whatsapp::init();
    CCMCK_Dequeue::init();
    CCMCK_Wompi::init();
    CCMCK_Newsletter::init();
    CCMCK_Surcharge::init();
    CCMCK_Coordinadora::init();
    CCMCK_Cart_Shipping::init();
    CCMCK_Guias::init();
    CCMCK_Cotizar::init();
    CCMCK_Reports::init();
    CCMCK_Cart_Redirect::init();
}
