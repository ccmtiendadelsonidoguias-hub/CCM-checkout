<?php
use PHPUnit\Framework\TestCase;

final class GuiasTest extends TestCase {
    private function args(): array {
        return array(
            'usuario'      => 'ccmtienda.ws',
            'clave_sha256' => str_repeat( 'a', 64 ),
            'id_cliente'   => 49444,
            'remitente'    => array( 'nombre' => 'CCM Tienda del Sonido', 'direccion' => 'Calle 45 30-50', 'telefono' => '3178119077', 'ciudad' => '08001000' ),
            'destinatario' => array( 'nombre' => 'Cliente Prueba', 'direccion' => 'Cra 1J 45-31 apto 2', 'ciudad_dane' => '05001000', 'telefono' => '3014373975', 'documento' => '1002157685' ),
            'valor_declarado' => 675000,
            'contenido'    => 'Equipos de sonido',
            'referencia'   => '1234',
            'observaciones'=> '',
            'detalle'      => array( array( 'ubl' => 0, 'alto' => 50.0, 'ancho' => 50.0, 'largo' => 50.0, 'peso' => 20.0, 'unidades' => 1 ) ),
        );
    }

    // --- build_guia_params: observaciones obligatorias de Coordinadora ---
    public function test_params_meet_coordinadora_observations(): void {
        $p = CCMCK_Guias::build_guia_params( $this->args() );
        $this->assertSame( '', $p['fecha'] );                    // obs 1
        $this->assertSame( '', $p['nit_remitente'] );            // obs 2
        $this->assertSame( 'CCM Tienda del Sonido', $p['nombre_remitente'] ); // obs 3
        $this->assertSame( '08001000', $p['ciudad_remitente'] ); // obs 4
        $this->assertSame( 'IMPRESO', $p['estado'] );
        $this->assertSame( 0, $p['id_remitente'] );
        $this->assertSame( array(), $p['recaudos'] );
        $this->assertSame( 2, $p['codigo_cuenta'] );
        $this->assertSame( 0, $p['codigo_producto'] );
        $this->assertSame( 1, $p['nivel_servicio'] );
        $this->assertSame( 49444, $p['id_cliente'] );
    }

    public function test_params_map_destinatario_and_detail(): void {
        $p = CCMCK_Guias::build_guia_params( $this->args() );
        $this->assertSame( 'Cliente Prueba', $p['nombre_destinatario'] );
        $this->assertSame( '05001000', $p['ciudad_destinatario'] );
        $this->assertSame( '1002157685', $p['nit_destinatario'] );
        $this->assertSame( '', $p['div_destinatario'] );
        $this->assertSame( 675000, $p['valor_declarado'] );
        $this->assertSame( '1234', $p['referencia'] );
        $this->assertCount( 1, $p['detalle'] );
        $this->assertSame( 'Caja', $p['detalle'][0]['nombre_empaque'] );
        $this->assertSame( '', $p['detalle'][0]['referencia'] );
        $this->assertSame( str_repeat( 'a', 64 ), $p['clave'] );
    }

    // --- should_generate ---
    private function ctx( array $over = array() ): array {
        return array_merge( array(
            'enabled'       => true,
            'usuario'       => 'ccmtienda.ws',
            'clave'         => 'x',
            'shipping_ids'  => array( 'ccmck_coordinadora' ),
            'existing_guia' => '',
            'has_lock'      => false,
        ), $over );
    }

    public function test_should_generate_ok(): void {
        $this->assertTrue( CCMCK_Guias::should_generate( $this->ctx() )['ok'] );
    }
    public function test_should_generate_blocks_disabled(): void {
        $r = CCMCK_Guias::should_generate( $this->ctx( array( 'enabled' => false ) ) );
        $this->assertFalse( $r['ok'] );
    }
    public function test_should_generate_blocks_pickup(): void {
        $r = CCMCK_Guias::should_generate( $this->ctx( array( 'shipping_ids' => array( 'ccmck_local_pickup' ) ) ) );
        $this->assertFalse( $r['ok'] );
        $this->assertStringContainsString( 'recogida', strtolower( $r['reason'] ) );
    }
    public function test_should_generate_blocks_existing_guia(): void {
        $this->assertFalse( CCMCK_Guias::should_generate( $this->ctx( array( 'existing_guia' => '33042000009' ) ) )['ok'] );
    }
    public function test_should_generate_blocks_lock_and_missing_creds(): void {
        $this->assertFalse( CCMCK_Guias::should_generate( $this->ctx( array( 'has_lock' => true ) ) )['ok'] );
        $this->assertFalse( CCMCK_Guias::should_generate( $this->ctx( array( 'clave' => '' ) ) )['ok'] );
    }
}
