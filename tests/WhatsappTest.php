<?php
use PHPUnit\Framework\TestCase;

final class WhatsappTest extends TestCase {

    public function test_build_message_with_name(): void {
        $msg = CCMCK_Whatsapp::build_message( 'William Pérez', '12345', 'ccmtiendadelsonido.com' );
        $this->assertSame(
            'Hola, soy William Pérez. Acabo de realizar el pedido #12345 en ccmtiendadelsonido.com y quiero confirmar mi compra.',
            $msg
        );
    }

    public function test_build_message_without_name(): void {
        $msg = CCMCK_Whatsapp::build_message( '  ', '99', 'ccmtiendadelsonido.com' );
        $this->assertSame(
            'Hola. Acabo de realizar el pedido #99 en ccmtiendadelsonido.com y quiero confirmar mi compra.',
            $msg
        );
    }

    /**
     * Razón de ser del cambio: en staging el host es dev.ccmtiendadelsonido.com
     * y el mensaje de WhatsApp debe reflejarlo (antes salía hardcodeado a
     * producción, y alguien lo parcheaba a mano en el servidor).
     */
    public function test_build_message_usa_el_host_de_staging(): void {
        $msg = CCMCK_Whatsapp::build_message( 'Ana', '1', 'dev.ccmtiendadelsonido.com' );
        $this->assertStringContainsString( 'dev.ccmtiendadelsonido.com', $msg );
    }

    public function test_build_wa_url_encodes_message(): void {
        $url = CCMCK_Whatsapp::build_wa_url( '573178119077', 'Ana', '77', 'ccmtiendadelsonido.com' );
        $this->assertStringStartsWith( 'https://wa.me/573178119077?text=', $url );
        $this->assertStringContainsString( rawurlencode( 'Hola, soy Ana.' ), $url );
        $this->assertStringContainsString( rawurlencode( '#77' ), $url );
        $this->assertStringNotContainsString( ' ', $url );
    }

    public function test_build_wa_url_strips_non_digits(): void {
        $url = CCMCK_Whatsapp::build_wa_url( '+57 317 811-9077', 'Ana', '1', 'ccmtiendadelsonido.com' );
        $this->assertStringStartsWith( 'https://wa.me/573178119077?text=', $url );
    }

    public function test_build_wa_url_empty_number_returns_empty(): void {
        $this->assertSame( '', CCMCK_Whatsapp::build_wa_url( 'abc', 'Ana', '1', 'ccmtiendadelsonido.com' ) );
    }
}
