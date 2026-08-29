<?php
define( 'ABSPATH', __DIR__ );

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) {
        return trim( preg_replace( '/[\r\n\t ]+/', ' ', wp_strip_all_tags_stub( (string) $str ) ) );
    }
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
    function sanitize_textarea_field( $str ) {
        // Como WP: quita etiquetas pero conserva los saltos de línea.
        return trim( wp_strip_all_tags_stub( (string) $str ) );
    }
}
function wp_strip_all_tags_stub( string $s ): string {
    return preg_replace( '/<[^>]*>/', '', $s );
}
if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( $url ) {
        $url = trim( (string) $url );
        return preg_match( '#^(https?:|/|tel:|mailto:)#i', $url ) ? $url : '';
    }
}
if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( $url ) { return esc_url_raw( $url ); }
}
if ( ! function_exists( 'wp_kses_post' ) ) {
    function wp_kses_post( $s ) { return (string) $s; }
}
if ( ! function_exists( 'sanitize_hex_color' ) ) {
    function sanitize_hex_color( $color ) {
        $color = (string) $color;
        return preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $color ) ? $color : null;
    }
}
if ( ! function_exists( 'absint' ) ) {
    function absint( $n ) { return abs( (int) $n ); }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
}
if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) { return $text; }
}
if ( ! function_exists( '_n' ) ) {
    function _n( $single, $plural, $number, $domain = 'default' ) {
        return 1 === (int) $number ? $single : $plural;
    }
}
if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_js' ) ) {
    function esc_js( $s ) { return addslashes( (string) $s ); }
}
if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( $s, $d = 'default' ) { return $s; }
}
if ( ! function_exists( 'esc_attr__' ) ) {
    function esc_attr__( $s, $d = 'default' ) { return $s; }
}
if ( ! function_exists( 'esc_html_e' ) ) {
    function esc_html_e( $s, $d = 'default' ) { echo esc_html( $s ); }
}
if ( ! function_exists( 'esc_attr_e' ) ) {
    function esc_attr_e( $s, $d = 'default' ) { echo esc_attr( $s ); }
}
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $hook, $value ) { return $value; }
}
if ( ! function_exists( 'wp_unslash' ) ) {
    function wp_unslash( $value ) {
        return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
    }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
    // OJO: el wp_json_encode() real sanea UTF-8 inválido antes de codificar
    // (reintenta con datos "limpiados" si json_encode() falla); este stub NO
    // lo hace. Si algún día entra texto libre de cliente al material de una
    // clave de caché (p. ej. CCMCK_Coordinadora::cache_key()) y ese texto trae
    // bytes UTF-8 inválidos, json_encode() puede devolver false aquí donde WP
    // habría devuelto una cadena saneada — y md5(false) === md5('') colapsa
    // TODOS esos carritos en la misma clave. Ahora mismo el material de esa
    // clave es solo origen/destino/valoración/detalle (nunca texto libre), así
    // que no muerde; pero si eso cambia, este stub deja de ser fiel al WP real
    // justo en el caso que importaría.
    function wp_json_encode( $data, $options = 0, $depth = 512 ) {
        return json_encode( $data, $options, $depth );
    }
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $name, $default = false ) { return $default; }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
}
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public $errors = array();
        public $error_data = array();
        public function __construct( $code = '', $message = '', $data = '' ) {
            if ( '' !== $code ) { $this->add( $code, $message, $data ); }
        }
        public function add( $code, $message = '', $data = '' ) {
            $this->errors[ $code ][] = $message;
            if ( '' !== $data ) { $this->error_data[ $code ] = $data; }
        }
        public function remove( $code ) {
            unset( $this->errors[ $code ], $this->error_data[ $code ] );
        }
        public function get_error_codes() { return array_keys( $this->errors ); }
        public function get_error_messages( $code = '' ) {
            if ( '' === $code ) {
                $all = array();
                foreach ( $this->errors as $msgs ) { $all = array_merge( $all, $msgs ); }
                return $all;
            }
            return $this->errors[ $code ] ?? array();
        }
        /** Como WP real: el primer mensaje del primer código (o del código pedido). */
        public function get_error_message( $code = '' ) {
            if ( '' === $code ) {
                $code = $this->get_error_codes()[0] ?? '';
            }
            $msgs = $this->get_error_messages( $code );
            return $msgs[0] ?? '';
        }
    }
}

if ( ! class_exists( 'WC_Shipping_Rate' ) ) {
    class WC_Shipping_Rate {
        public $id; public $label; public $cost; public $taxes; public $method_id;
        public function __construct( $id = '', $label = '', $cost = 0, $taxes = array(), $method_id = '' ) {
            $this->id = $id; $this->label = $label; $this->cost = $cost;
            $this->taxes = $taxes; $this->method_id = $method_id;
        }
        public function get_id() { return $this->id; }
        public function get_label() { return $this->label; }
        public function get_cost() { return $this->cost; }
        public $meta_data = array();
        public function add_meta_data( $key, $value ) { $this->meta_data[ $key ] = $value; }
        public function get_meta_data() { return $this->meta_data; }
    }
}

// Stubs que necesitan CCMCK_Templates y CCMCK_Assets. Ninguno hace nada: las
// pruebas solo ejercitan las funciones PURAS de esas clases (la lista blanca y
// la decisión de encolar), no el encolado de verdad, que es de WordPress.
if ( ! function_exists( 'is_cart' ) ) {
    function is_cart() { return false; }
}
if ( ! function_exists( 'is_checkout' ) ) {
    function is_checkout() { return false; }
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
    function wp_enqueue_style( ...$a ) { return null; }
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
    function wp_enqueue_script( ...$a ) { return null; }
}
if ( ! function_exists( 'wp_localize_script' ) ) {
    function wp_localize_script( ...$a ) { return true; }
}
if ( ! function_exists( 'admin_url' ) ) {
    function admin_url( $path = '' ) { return 'https://ejemplo.test/wp-admin/' . ltrim( (string) $path, '/' ); }
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
    function wp_create_nonce( $action = -1 ) { return 'nonce-de-prueba'; }
}
if ( ! defined( 'CCMCK_DIR' ) ) {
    define( 'CCMCK_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'CCMCK_URL' ) ) {
    define( 'CCMCK_URL', 'https://ejemplo.test/wp-content/mu-plugins/ccm-checkout/' );
}

require_once dirname( __DIR__ ) . '/includes/class-ccmck-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-info-cards.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-faq.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-document.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-payments.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-thankyou.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-shipping.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-pickup.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-cities.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-whatsapp.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-dequeue.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-surcharge.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-coordinadora.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-guias.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-cotizar.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-reports.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-cart-redirect.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-templates.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-assets.php';

// Transients en memoria. `$GLOBALS['ccmck_test_transients']` guarda
// ['valor' => mixed, 'expira' => int] y las pruebas lo vacían en setUp().
if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( $key ) {
        $store = $GLOBALS['ccmck_test_transients'] ?? array();
        if ( ! isset( $store[ $key ] ) ) {
            return false;
        }
        return $store[ $key ]['valor'];
    }
}
if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( $key, $value, $expiration = 0 ) {
        $GLOBALS['ccmck_test_transients'][ $key ] = array( 'valor' => $value, 'expira' => (int) $expiration );
        return true;
    }
}

defined( 'HOUR_IN_SECONDS' )   || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );

// wp_remote_post() de mentira: cuenta llamadas HTTP reales y devuelve
// respuestas encoladas desde `$GLOBALS['ccmck_test_http']` (mismo estilo que
// los transients de arriba). Las pruebas cargan 'queue' en su setUp() y leen
// 'calls' para verificar que la caché evita la llamada de red. Un elemento de
// la cola puede ser {body,code} o una instancia de WP_Error, para simular una
// caída de red real.
if ( ! function_exists( 'wp_remote_post' ) ) {
    function wp_remote_post( $url, $args = array() ) {
        if ( ! isset( $GLOBALS['ccmck_test_http'] ) ) {
            $GLOBALS['ccmck_test_http'] = array( 'calls' => 0, 'queue' => array() );
        }
        $GLOBALS['ccmck_test_http']['calls']++;
        $queue = $GLOBALS['ccmck_test_http']['queue'];
        $next  = $queue ? array_shift( $queue ) : array( 'body' => '{}', 'code' => 200 );
        $GLOBALS['ccmck_test_http']['queue'] = $queue;
        if ( $next instanceof WP_Error ) {
            return $next;
        }
        return array(
            'body'     => (string) ( $next['body'] ?? '{}' ),
            'response' => array( 'code' => (int) ( $next['code'] ?? 200 ) ),
        );
    }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $response ) {
        return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
    }
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( $response ) {
        return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0;
    }
}

require_once dirname( __DIR__ ) . '/includes/class-ccmck-cart-shipping.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-cart-ajax.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-ubicacion.php';
