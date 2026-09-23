<?php

namespace Victual\Tests\Pgsql;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Victual\Helpers\OutboundHostPolicy;
use Victual\Helpers\OutboundHostRefusedException;

/**
 * helpers/OutboundHostPolicy.php in isolation: no PHPUnit test in this file makes a real
 * network call (see tests/Pgsql/BarcodeLookupTest.php's own docblock, which this class's
 * subject supports), and this class is no exception - a resolver is injected wherever a
 * non-literal hostname is involved, so DNS is never queried either. Issue #459 (sweep
 * finding S14, docs/security-sweep.md) requires the barcode picture fetch to refuse
 * loopback, private, link-local, carrier-grade NAT, unspecified, multicast,
 * reserved/broadcast and the IPv6 equivalents (including IPv4-mapped/compatible forms and
 * DNS-rebinding-style host answers with one public and one private address), plus numeric
 * IPv4 literals in decimal, octal and hex notation - forms curl parses as an address
 * directly, bypassing DNS altogether, so the policy has to recognise them the same way.
 *
 * A file of its own, wired into phpunit.xml's "barcodelookup" testsuite alongside
 * BarcodeLookupTest.php: PHPUnit's file-based test loading resolves a <file> entry to the
 * one class whose name matches the file, so a second TestCase class declared inside
 * BarcodeLookupTest.php (as this one briefly was) is never actually collected or run.
 */
class OutboundHostPolicyTest extends TestCase
{
	/** @return array<string, array{0: string}> A URL whose literal or resolved host must be refused. */
	public static function refusedUrlProvider(): array
	{
		return [
			'IPv4 loopback' => ['http://127.0.0.1/x.jpg'],
			'IPv4 loopback, non-default host in range' => ['http://127.255.255.254/x.jpg'],
			'IPv4 private 10/8' => ['http://10.1.2.3/x.jpg'],
			'IPv4 private 172.16/12' => ['http://172.31.0.1/x.jpg'],
			'IPv4 private 192.168/16' => ['http://192.168.1.1/x.jpg'],
			'IPv4 link-local' => ['http://169.254.1.1/x.jpg'],
			'IPv4 cloud metadata' => ['http://169.254.169.254/latest/meta-data/x.jpg'],
			'IPv4 carrier-grade NAT' => ['http://100.64.0.1/x.jpg'],
			'IPv4 unspecified' => ['http://0.0.0.0/x.jpg'],
			'IPv4 multicast' => ['http://224.0.0.1/x.jpg'],
			'IPv4 reserved/broadcast' => ['http://255.255.255.255/x.jpg'],
			'IPv6 loopback' => ['http://[::1]/x.jpg'],
			'IPv6 unspecified' => ['http://[::]/x.jpg'],
			'IPv6 unique local (ULA)' => ['http://[fc00::1]/x.jpg'],
			'IPv6 link-local' => ['http://[fe80::1]/x.jpg'],
			'IPv6 multicast' => ['http://[ff02::1]/x.jpg'],
			'IPv4-mapped IPv6 of a private address' => ['http://[::ffff:127.0.0.1]/x.jpg'],
			'IPv4-mapped IPv6 of the cloud metadata address' => ['http://[::ffff:169.254.169.254]/x.jpg'],
			'IPv4-compatible IPv6 of a private address' => ['http://[::10.0.0.5]/x.jpg'],
			'decimal IPv4 literal for loopback' => ['http://2130706433/x.jpg'],
			'octal IPv4 literal for loopback' => ['http://017700000001/x.jpg'],
			'hex IPv4 literal for loopback' => ['http://0x7f000001/x.jpg'],
			'partial hex/decimal IPv4 literal for loopback' => ['http://0x7f.1/x.jpg'],
			'shorthand two-part IPv4 literal for loopback' => ['http://127.1/x.jpg'],
			'userinfo naming a decoy host ahead of the real, private one' => ['http://good.example.org@127.0.0.1/x.jpg'],
			'non-http(s) scheme naming an otherwise-fine host' => ['ftp://93.184.216.34/x.jpg'],
			'file scheme' => ['file:///etc/passwd'],
			'IPv4 IETF protocol assignments' => ['http://192.0.0.8/x.jpg'],
			'IPv4 benchmarking' => ['http://198.19.0.1/x.jpg'],
			'IPv6 NAT64 well-known prefix embedding a private address' => ['http://[64:ff9b::7f00:1]/x.jpg'],
			'IPv6 NAT64 local-use prefix (RFC 8215)' => ['http://[64:ff9b:1::1]/x.jpg'],
			'IPv6 6to4 (RFC 3056), even embedding a public address' => ['http://[2002:5db8:d822::1]/x.jpg'],
			'IPv6 deprecated site-local' => ['http://[fec0::1]/x.jpg'],
		];
	}

	#[DataProvider('refusedUrlProvider')]
	public function testAssertAllowedRefusesEveryHostileForm(string $url): void
	{
		$this->expectException(OutboundHostRefusedException::class);

		(new OutboundHostPolicy())->AssertAllowed($url);
	}

	/** @return array<string, array{0: string}> A URL whose literal host must be accepted. */
	public static function acceptedLiteralUrlProvider(): array
	{
		return [
			'public IPv4 literal' => ['https://93.184.216.34/x.jpg'],
			'public IPv6 literal' => ['https://[2001:4860:4860::8888]/x.jpg'],
			// The NAT64 well-known prefix (64:ff9b::/96) is checked by the IPv4 address it
			// embeds, unlike 6to4 (2002::/16) above, which is refused wholesale - see
			// OutboundHostPolicy::RefusedIpv6Ranges()'s docblock for why the two differ.
			'IPv6 NAT64 well-known prefix embedding a public address' => ['https://[64:ff9b::5db8:d822]/x.jpg'],
		];
	}

	#[DataProvider('acceptedLiteralUrlProvider')]
	public function testAssertAllowedAcceptsAPublicLiteralAddress(string $url): void
	{
		$addresses = (new OutboundHostPolicy())->AssertAllowed($url);

		self::assertNotEmpty($addresses, 'the accepted address(es) are returned for the caller to pin the connection to');
	}

	/** A hostname is accepted when every address the injected resolver returns for it is public. */
	public function testAssertAllowedAcceptsAHostnameResolvingOnlyToPublicAddresses(): void
	{
		$policy = new OutboundHostPolicy(fn (string $host) => $host === 'images.example.org' ? ['93.184.216.34', '2001:4860:4860::8888'] : []);

		$addresses = $policy->AssertAllowed('https://images.example.org/x.jpg');

		self::assertSame(['93.184.216.34', '2001:4860:4860::8888'], $addresses);
	}

	/**
	 * DNS rebinding is not a second request seeing a different answer - it is a single
	 * *hostname* resolving to more than one address, one of which is refused. Checking only
	 * the first address a resolver returns would let this through; every address returned
	 * must be examined.
	 */
	public function testAssertAllowedRefusesAHostnameThatResolvesToOnePublicAndOnePrivateAddress(): void
	{
		$policy = new OutboundHostPolicy(fn (string $host) => ['93.184.216.34', '10.0.0.5']);

		$this->expectException(OutboundHostRefusedException::class);

		$policy->AssertAllowed('https://mixed.example.org/x.jpg');
	}

	/** A hostname the resolver cannot resolve at all is refused, not silently skipped. */
	public function testAssertAllowedRefusesAHostnameThatResolvesToNothing(): void
	{
		$policy = new OutboundHostPolicy(fn (string $host) => []);

		$this->expectException(OutboundHostRefusedException::class);

		$policy->AssertAllowed('https://nowhere.example.org/x.jpg');
	}

	/** The injected resolver is never consulted for a host that is already a literal address. */
	public function testAssertAllowedDoesNotConsultTheResolverForALiteralAddress(): void
	{
		$resolverCalls = [];
		$policy = new OutboundHostPolicy(function (string $host) use (&$resolverCalls) {
			$resolverCalls[] = $host;
			return [];
		});

		$policy->AssertAllowed('https://93.184.216.34/x.jpg');

		self::assertSame([], $resolverCalls, 'a literal IPv4 address needs no resolution');
	}

	/**
	 * A trailing dot is the fully qualified form of the same name, and curl connects to the
	 * address it spells: "127.0.0.1." is loopback, not a hostname to look up.
	 */
	public function testAssertAllowedRefusesALoopbackLiteralWrittenWithATrailingDot(): void
	{
		$policy = new OutboundHostPolicy(fn (string $host) => ['93.184.216.34']);

		$this->expectException(OutboundHostRefusedException::class);

		$policy->AssertAllowed('http://127.0.0.1./x.jpg');
	}

	/** @return array<string, array{0: string}> Hosts that look numeric but are not an IPv4 literal curl would dial. */
	public static function notAnIpv4LiteralProvider(): array
	{
		return [
			'five dotted parts' => ['1.2.3.4.5'],
			'an empty part' => ['1..2'],
			'a hex prefix with no digits' => ['0x.1'],
			'a single part above 2^32 - 1' => ['4294967296'],
			'a leading part above 255' => ['256.1.1'],
			'a last part too wide for its position' => ['1.16777216'],
		];
	}

	/**
	 * A host that fails the numeric-literal rules is a name, so it goes to the resolver and
	 * is decided by the addresses that come back - here a public one, so it is accepted. The
	 * point is that it is resolved, not read as some address it does not spell.
	 */
	#[DataProvider('notAnIpv4LiteralProvider')]
	public function testAHostThatIsNotANumericLiteralIsResolvedAsAName(string $host): void
	{
		$resolverCalls = [];
		$policy = new OutboundHostPolicy(function (string $asked) use (&$resolverCalls) {
			$resolverCalls[] = $asked;
			return ['93.184.216.34'];
		});

		self::assertSame(['93.184.216.34'], $policy->AssertAllowed('http://' . $host . '/x.jpg'));
		self::assertSame([$host], $resolverCalls, 'the host was handed to the resolver as a name');
	}

	/** Anything that is not an IP address at all is refused rather than let through unexamined. */
	public function testIsRefusedAddressRefusesSomethingThatIsNotAnAddress(): void
	{
		self::assertTrue(OutboundHostPolicy::IsRefusedAddress('not-an-address'));
		self::assertFalse(OutboundHostPolicy::IsRefusedAddress('93.184.216.34'));
	}
}
