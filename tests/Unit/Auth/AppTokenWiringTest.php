<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * H2 wiring (2026-11-16): the revocation rule is only useful if every piece reaches it. These guards read the
 * source of each piece: the JWT check, the logout endpoint, the admin "Phones" control and the iPhone sign-out.
 * The behaviour itself is in AppTokenServiceTest.
 */
class AppTokenWiringTest extends TestCase
{
    private function src(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        $this->assertFileExists($path);
        return (string)file_get_contents($path);
    }

    public function testEveryJwtRequestIsCheckedAgainstTheSignInRecord(): void
    {
        $this->assertStringContainsString('AppTokenService::refusal(', $this->src('app/Core/Auth/JwtAuth.php'));
    }

    public function testEveryIssuerRecordsTheSignInBeforeHandingOutTheToken(): void
    {
        $this->assertStringContainsString('AppTokenService::issue(', $this->src('app/Core/Auth/PhoneSignIn.php'));
        $this->assertStringContainsString('AppTokenService::issue(', $this->src('app/Core/Auth/JwtService.php'));
    }

    public function testLogoutEndpointRevokesOnlyTheCallersOwnSignIn(): void
    {
        $src = $this->src('public/api/auth/logout.php');
        $this->assertStringContainsString('AppTokenService::revoke(', $src);
        $this->assertStringContainsString("(int)\$jwtUser['id']", $src, 'the revoke is limited to the caller');
    }

    public function testAdminCanSignOutOnePhoneFromTheUsersPage(): void
    {
        $src = $this->src('public/crm/users_appstack.php');
        $this->assertStringContainsString("case 'sign_out_app_sign_in':", $src);
        $this->assertStringContainsString('AppTokenService::revoke(', $src);
        $this->assertStringContainsString('AppTokenService::activeFor(', $src);
    }

    public function testIphoneSignOutTellsTheServerAndHasAnEndpointCase(): void
    {
        $session = $this->src('ios/MowologyCRM/MowologyCRM/Core/Auth/AuthSession.swift');
        $this->assertStringContainsString('endServerSignIn(jwt:', $session);
        $endpoints = $this->src('ios/MowologyCRM/MowologyCRM/Core/Network/APIEndpoints.swift');
        // The case must appear in all three switch blocks (url, requiresAuth, httpMethod).
        $this->assertSame(3, substr_count($endpoints, 'case .authLogout'), 'authLogout in url, requiresAuth and httpMethod');
    }
}
