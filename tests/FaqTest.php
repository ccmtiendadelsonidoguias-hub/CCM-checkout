<?php
use PHPUnit\Framework\TestCase;

final class FaqTest extends TestCase {

	public function test_markup_empty_returns_empty_string(): void {
		$this->assertSame( '', CCMCK_Faq::markup( array() ) );
	}

	public function test_markup_skips_items_without_question(): void {
		$out = CCMCK_Faq::markup( array(
			array( 'q' => '', 'a' => 'sin pregunta' ),
		) );
		$this->assertSame( '', $out );
	}

	public function test_markup_emits_question_and_answer(): void {
		$out = CCMCK_Faq::markup( array(
			array( 'q' => '¿Cómo rastreo mi pedido?', 'a' => 'Con el número de guía.' ),
		) );
		$this->assertStringContainsString( '<div class="faq-section">', $out );
		$this->assertStringContainsString( 'faq-title', $out );
		$this->assertStringContainsString( '¿Cómo rastreo mi pedido?', $out );
		$this->assertStringContainsString( 'Con el número de guía.', $out );
	}

	public function test_markup_uses_question_mark_when_no_image(): void {
		$out = CCMCK_Faq::markup( array(
			array( 'q' => 'Pregunta', 'a' => 'Respuesta' ),
		) );
		$this->assertStringContainsString( '<div class="faq-icon" aria-hidden="true">?</div>', $out );
	}

	public function test_markup_renders_image_icon_when_set(): void {
		$out = CCMCK_Faq::markup( array(
			array( 'q' => 'Pregunta', 'a' => 'Respuesta', 'icon_image' => 'https://cdn.test/i.png' ),
		) );
		$this->assertStringContainsString( '<img src="https://cdn.test/i.png" alt="">', $out );
		$this->assertStringNotContainsString( '>?</div>', $out );
	}

	public function test_markup_escapes_question(): void {
		$out = CCMCK_Faq::markup( array(
			array( 'q' => '<script>x</script>', 'a' => 'a' ),
		) );
		$this->assertStringNotContainsString( '<script>', $out );
	}
}
