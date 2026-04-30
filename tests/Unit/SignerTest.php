<?php

declare( strict_types=1 );

namespace LicenseKit\Tests\Unit;

use LicenseKit\Services\Signer;
use PHPUnit\Framework\TestCase;

final class SignerTest extends TestCase {

	public function test_envelope_round_trips(): void {
		$payload  = [ 'b' => 2, 'a' => 1, 'nested' => [ 'z' => 9, 'a' => 0 ] ];
		$envelope = Signer::sign_envelope( $payload );
		$this->assertArrayHasKey( 'signature', $envelope );
		$this->assertTrue( Signer::verify_envelope( $envelope ) );
	}

	public function test_tampered_envelope_fails(): void {
		$envelope        = Signer::sign_envelope( [ 'a' => 1 ] );
		$envelope['a']   = 999;
		$this->assertFalse( Signer::verify_envelope( $envelope ) );
	}

	public function test_reordering_keys_still_verifies(): void {
		$envelope  = Signer::sign_envelope( [ 'b' => 2, 'a' => 1 ] );
		// Rebuild with different insertion order; signature should still verify.
		$reordered = [ 'signature' => $envelope['signature'], 'a' => 1, 'b' => 2 ];
		$this->assertTrue( Signer::verify_envelope( $reordered ) );
	}

	public function test_missing_signature_fails(): void {
		$payload = [ 'a' => 1, 'signature' => '' ];
		$this->assertFalse( Signer::verify_envelope( $payload ) );
	}

	public function test_download_token_round_trips(): void {
		$token    = Signer::mint_download_token( 42, 7, 'salt-abc', 60 );
		$verified = Signer::verify_download_token( $token, 'salt-abc' );
		$this->assertNotNull( $verified );
		$this->assertSame( 42, $verified['release_id'] );
		$this->assertSame( 7, $verified['license_id'] );
		$this->assertGreaterThan( time(), $verified['expires_at'] );
	}

	public function test_download_token_wrong_salt_fails(): void {
		$token = Signer::mint_download_token( 42, 7, 'salt-abc', 60 );
		$this->assertNull( Signer::verify_download_token( $token, 'salt-xyz' ) );
	}

	public function test_download_token_expired_fails(): void {
		$expired = Signer::mint_download_token( 42, 7, 'salt-abc', -10 );
		$this->assertNull( Signer::verify_download_token( $expired, 'salt-abc' ) );
	}

	public function test_download_token_garbage_fails(): void {
		$this->assertNull( Signer::verify_download_token( 'garbage!!!', 'salt' ) );
		$this->assertNull( Signer::verify_download_token( '', 'salt' ) );
	}
}
