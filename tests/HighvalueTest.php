<?php
use PHPUnit\Framework\TestCase;

final class HighvalueTest extends TestCase {

    /** Umbral por defecto: por debajo del techo real observado ($4.870.000). */
    private const U = CCMCK_Highvalue::UMBRAL_DEFAULT;

    public function test_avisa_desde_el_umbral(): void {
        $this->assertTrue( CCMCK_Highvalue::should_warn( 4500000.0, self::U ), 'justo en el umbral debe avisar' );
        $this->assertTrue( CCMCK_Highvalue::should_warn( 9575000.0, self::U ), 'caso real #31294 que falló' );
        $this->assertTrue( CCMCK_Highvalue::should_warn( 15960000.0, self::U ), 'caso real #33025 que falló' );
    }

    public function test_no_molesta_en_compras_normales(): void {
        // El ticket promedio exitoso ronda $1M: ahí el aviso estorbaría.
        $this->assertFalse( CCMCK_Highvalue::should_warn( 1060544.0, self::U ) );
        $this->assertFalse( CCMCK_Highvalue::should_warn( 4499999.0, self::U ) );
        $this->assertFalse( CCMCK_Highvalue::should_warn( 0.0, self::U ) );
    }

    public function test_umbral_cero_desactiva(): void {
        $this->assertFalse( CCMCK_Highvalue::should_warn( 99999999.0, 0 ) );
        $this->assertFalse( CCMCK_Highvalue::should_warn( 99999999.0, -1 ) );
    }

    public function test_umbral_configurable(): void {
        // Bajarlo a $3M también es defendible: en $3M–$5M ya falla el 92%.
        $this->assertTrue( CCMCK_Highvalue::should_warn( 3200000.0, 3000000 ) );
        $this->assertFalse( CCMCK_Highvalue::should_warn( 3200000.0, 5000000 ) );
    }

    public function test_mensaje_lleva_el_monto_formateado(): void {
        $msg = CCMCK_Highvalue::build_message( 9575000.0 );
        $this->assertStringContainsString( '$9.575.000', $msg );
        $this->assertStringContainsString( 'coordinar el pago', $msg );
    }

    public function test_url_wa_valida_y_escapada(): void {
        $url = CCMCK_Highvalue::build_wa_url( '573178119077', 9575000.0 );
        $this->assertStringStartsWith( 'https://wa.me/573178119077?text=', $url );
        // El monto viaja urlencoded, no crudo.
        $this->assertStringNotContainsString( ' ', $url );
        $this->assertStringContainsString( rawurlencode( '$9.575.000' ), $url );
    }

    public function test_url_tolera_numero_con_formato(): void {
        $this->assertStringStartsWith(
            'https://wa.me/573178119077?',
            CCMCK_Highvalue::build_wa_url( '+57 317 811 9077', 5000000.0 )
        );
    }

    public function test_sin_numero_no_hay_url(): void {
        // Sin número configurado no se pinta nada, en vez de un enlace roto.
        $this->assertSame( '', CCMCK_Highvalue::build_wa_url( '', 9575000.0 ) );
        $this->assertSame( '', CCMCK_Highvalue::build_wa_url( 'abc', 9575000.0 ) );
    }
}
