<?php

namespace Victual\Helpers;

/**
 * Refuses a URL whose scheme is not http(s), or whose host is, or resolves to, an address
 * this deployment must never originate a request toward - loopback, private (RFC 1918),
 * link-local (including the 169.254.169.254 cloud metadata address), carrier-grade NAT
 * (100.64.0.0/10), unspecified, multicast, reserved/broadcast, and the IPv6 equivalents
 * (unique local fc00::/7, link-local fe80::/10, ::1, ::) including IPv4-mapped/compatible
 * IPv6 forms of any refused IPv4 address (::ffff:127.0.0.1).
 *
 * This exists because sweep finding S14 (docs/security-sweep.md) requires the fetch host to
 * be checked before services/StockService.php::ExternalBarcodeLookup() downloads a barcode
 * source's __image_url - a value the source chooses, not the deployment. AGENTS.md forbids a
 * *user-configurable* outbound URL; this class enforces a fixed policy, not a setting, so it
 * does not create one.
 *
 * A caller is expected to:
 *  1. Call AssertAllowed($url), which resolves the host and refuses it (or the URL itself)
 *     under any of the reasons above. Every address the host resolves to is checked, not
 *     just the first, so a host answering with one public and one private address is
 *     refused.
 *  2. Pin the actual request to one of the addresses AssertAllowed() returned (e.g. via
 *     Guzzle's CURLOPT_RESOLVE), so a second DNS answer at request time - the
 *     time-of-check/time-of-use gap known as DNS rebinding - cannot be used instead of the
 *     one just validated.
 *
 * The resolver is injectable so tests can supply canned answers instead of making real DNS
 * queries; DefaultResolve() is used when none is given.
 *
 * Decimal, octal and hex-notation IPv4 literals (http://2130706433/, http://017700000001/,
 * http://0x7f.1/) are recognised and evaluated as the IPv4 address they represent, because
 * curl - what Guzzle eventually calls - parses a URL host in exactly this permissive way and
 * would connect to that address directly, without ever going through DNS or this class's
 * resolver.
 */
final class OutboundHostPolicy
{
	private const ALLOWED_SCHEMES = ['http', 'https'];

	/** @var callable(string): array<int, string> */
	private $Resolver;

	/**
	 * @param callable(string): array<int, string>|null $resolver Given a hostname, returns
	 *        every IPv4/IPv6 address it resolves to (empty array for none). Defaults to
	 *        DefaultResolve(), which performs a real DNS lookup.
	 */
	public function __construct(?callable $resolver = null)
	{
		$this->Resolver = $resolver ?? [self::class, 'DefaultResolve'];
	}

	/**
	 * @param string $url
	 * @return array<int, string> Every address $url's host resolves to (or the host itself,
	 *                            when it already is an address), all confirmed allowed. Use
	 *                            one of these to pin the actual connection.
	 * @throws OutboundHostRefusedException When the scheme is not http(s), the host cannot
	 *         be determined or resolves to nothing, or any resolved address is refused.
	 */
	public function AssertAllowed(string $url): array
	{
		$parts = parse_url($url);

		if ($parts === false || empty($parts['scheme']) || empty($parts['host']))
		{
			throw new OutboundHostRefusedException("URL '$url' could not be parsed into a scheme and host");
		}

		$scheme = strtolower($parts['scheme']);
		if (!in_array($scheme, self::ALLOWED_SCHEMES, true))
		{
			throw new OutboundHostRefusedException("Scheme '$scheme' is not allowed");
		}

		// parse_url() does not return the brackets around an IPv6 literal host on any
		// currently supported PHP version, but stripping them here costs nothing and covers
		// the case defensively.
		$host = trim($parts['host'], '[]');

		$addresses = $this->ResolveAllAddresses($host);

		if (empty($addresses))
		{
			throw new OutboundHostRefusedException("Host '$host' did not resolve to any address");
		}

		foreach ($addresses as $address)
		{
			if (self::IsRefusedAddress($address))
			{
				throw new OutboundHostRefusedException("Host '$host' resolves to refused address '$address'");
			}
		}

		return $addresses;
	}

	/**
	 * @return array<int, string>
	 */
	private function ResolveAllAddresses(string $host): array
	{
		// curl (and therefore Guzzle) accepts decimal/octal/hex IPv4 literals as a host and
		// connects to the address they encode directly, bypassing DNS. Recognising that form
		// here - before it would otherwise be handed to the injected resolver as if it were a
		// domain name - means the check sees the same address curl would actually dial.
		$numericIpv4 = self::ParseNumericIpv4($host);
		if ($numericIpv4 !== null)
		{
			return [$numericIpv4];
		}

		if (filter_var($host, FILTER_VALIDATE_IP) !== false)
		{
			return [$host];
		}

		$resolved = ($this->Resolver)($host);
		$valid = array_filter((array)$resolved, static fn ($address) => is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false);

		return array_values(array_unique($valid));
	}

	/**
	 * The default resolver: a real forward DNS lookup (A and AAAA records) for $host.
	 *
	 * @return array<int, string>
	 */
	public static function DefaultResolve(string $host): array
	{
		$addresses = [];

		$ipv4Addresses = @gethostbynamel($host);
		if (is_array($ipv4Addresses))
		{
			$addresses = array_merge($addresses, $ipv4Addresses);
		}

		if (function_exists('dns_get_record'))
		{
			$records = @dns_get_record($host, DNS_AAAA);
			if (is_array($records))
			{
				foreach ($records as $record)
				{
					if (!empty($record['ipv6']))
					{
						$addresses[] = $record['ipv6'];
					}
				}
			}
		}

		return $addresses;
	}

	/**
	 * Whether $address (already a resolved IPv4 or IPv6 literal, not a hostname) falls in a
	 * range this policy refuses.
	 */
	public static function IsRefusedAddress(string $address): bool
	{
		if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)
		{
			return self::IsRefusedIpv4($address);
		}

		if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false)
		{
			return self::IsRefusedIpv6($address);
		}

		// Not a recognisable IP address at all: refuse rather than let an unclassifiable
		// form through unexamined.
		return true;
	}

	/** @return array<int, string> CIDR ranges (a.b.c.d/n) this policy refuses for IPv4. */
	private static function RefusedIpv4Ranges(): array
	{
		return [
			'0.0.0.0/8',       // unspecified / "this network"
			'10.0.0.0/8',      // RFC 1918 private
			'100.64.0.0/10',   // CGNAT (RFC 6598)
			'127.0.0.0/8',     // loopback
			'169.254.0.0/16',  // link-local, including the 169.254.169.254 cloud metadata address
			'172.16.0.0/12',   // RFC 1918 private
			'192.168.0.0/16',  // RFC 1918 private
			'192.0.0.0/24',    // IETF protocol assignments (RFC 6890)
			'198.18.0.0/15',   // benchmarking (RFC 2544)
			'224.0.0.0/4',     // multicast
			'240.0.0.0/4',     // reserved, including 255.255.255.255/32 broadcast
		];
	}

	/**
	 * CIDR ranges (addr/n) this policy refuses outright for IPv6 - as opposed to the
	 * embedded-IPv4 forms ExtractEmbeddedIpv4() and IsRefusedIpv6() check by their IPv4
	 * address instead. 2002::/16 (6to4) is refused wholesale rather than by its embedded
	 * address: 6to4 is deprecated (RFC 7526), and unlike a NAT64 well-known prefix its
	 * embedded address names the tunnel's own relay, not a final destination whose
	 * reachability this policy would otherwise decide on its own terms.
	 *
	 * @return array<int, string>
	 */
	private static function RefusedIpv6Ranges(): array
	{
		return [
			'::/128',         // unspecified
			'::1/128',        // loopback
			'64:ff9b:1::/48', // NAT64, local use (RFC 8215) - refused wholesale; RFC 6052's
			                  // variable-length embedding for a /48 prefix is not the simple
			                  // fixed-offset form ExtractEmbeddedIpv4() decodes for /96.
			'2002::/16',      // 6to4 (RFC 3056, deprecated by RFC 7526)
			'fc00::/7',       // unique local (ULA)
			'fe80::/10',      // link-local
			'fec0::/10',      // site-local (deprecated, RFC 3879)
			'ff00::/8',       // multicast
		];
	}

	private static function IsRefusedIpv4(string $ip): bool
	{
		foreach (self::RefusedIpv4Ranges() as $range)
		{
			if (self::Ipv4InCidr($ip, $range))
			{
				return true;
			}
		}

		return false;
	}

	private static function IsRefusedIpv6(string $ip): bool
	{
		// An IPv4-mapped (::ffff:a.b.c.d) or IPv4-compatible (::a.b.c.d) address is refused
		// exactly when the IPv4 address it embeds would be, so the IPv6 form cannot be used
		// to reach an address the IPv4 rules above already refuse.
		$embeddedIpv4 = self::ExtractEmbeddedIpv4($ip);
		if ($embeddedIpv4 !== null && self::IsRefusedIpv4($embeddedIpv4))
		{
			return true;
		}

		foreach (self::RefusedIpv6Ranges() as $range)
		{
			if (self::Ipv6InCidr($ip, $range))
			{
				return true;
			}
		}

		return false;
	}

	private static function ExtractEmbeddedIpv4(string $ip): ?string
	{
		$binary = @inet_pton($ip);
		if ($binary === false || strlen($binary) !== 16)
		{
			return null;
		}

		// IPv4-mapped: ::ffff:a.b.c.d - the first 80 bits are zero, the next 16 are one.
		if (substr($binary, 0, 10) === str_repeat("\x00", 10) && substr($binary, 10, 2) === "\xff\xff")
		{
			return inet_ntop(substr($binary, 12, 4));
		}

		// IPv4-compatible (deprecated): ::a.b.c.d - the first 96 bits are zero. Excludes ::
		// and ::1, which are handled by the IPv6-native unspecified/loopback ranges instead.
		if (substr($binary, 0, 12) === str_repeat("\x00", 12))
		{
			$embedded = substr($binary, 12, 4);
			if ($embedded !== "\x00\x00\x00\x00" && $embedded !== "\x00\x00\x00\x01")
			{
				return inet_ntop($embedded);
			}
		}

		// NAT64 well-known prefix (RFC 6052): 64:ff9b::/96 embeds the IPv4 destination in its
		// last 32 bits exactly as the mapped form above does, so it is checked against the
		// same IPv4 rules rather than refused wholesale.
		$nat64Prefix = @inet_pton('64:ff9b::');
		if ($nat64Prefix !== false && substr($binary, 0, 12) === substr($nat64Prefix, 0, 12))
		{
			return inet_ntop(substr($binary, 12, 4));
		}

		return null;
	}

	private static function Ipv4InCidr(string $ip, string $cidr): bool
	{
		[$subnet, $bits] = explode('/', $cidr);
		$bits = (int)$bits;

		$ipLong = ip2long($ip);
		$subnetLong = ip2long($subnet);
		if ($ipLong === false || $subnetLong === false)
		{
			return false;
		}

		$mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;

		return ($ipLong & $mask) === ($subnetLong & $mask);
	}

	private static function Ipv6InCidr(string $ip, string $cidr): bool
	{
		[$subnet, $bits] = explode('/', $cidr);
		$bits = (int)$bits;

		$ipBinary = @inet_pton($ip);
		$subnetBinary = @inet_pton($subnet);
		if ($ipBinary === false || $subnetBinary === false)
		{
			return false;
		}

		$fullBytes = intdiv($bits, 8);
		$remainderBits = $bits % 8;

		if ($fullBytes > 0 && substr($ipBinary, 0, $fullBytes) !== substr($subnetBinary, 0, $fullBytes))
		{
			return false;
		}

		if ($remainderBits > 0)
		{
			$mask = (~0 << (8 - $remainderBits)) & 0xFF;
			if ((ord($ipBinary[$fullBytes]) & $mask) !== (ord($subnetBinary[$fullBytes]) & $mask))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Recognises $host as a decimal, octal or hex-notation IPv4 literal - including the
	 * shorthand forms with fewer than four dot-separated parts, where the last part absorbs
	 * the remaining bits (e.g. "127.1" == "127.0.0.1", "0x7f.1" == "127.0.0.1") - following
	 * the same parsing rules curl and the WHATWG URL standard use, and returns the dotted-
	 * quad address it encodes. Returns null when $host is not one of these forms (an
	 * ordinary domain name, or already a dotted-quad, both fall through here unchanged).
	 */
	private static function ParseNumericIpv4(string $host): ?string
	{
		if ($host === '')
		{
			return null;
		}

		$parts = explode('.', $host);

		if (end($parts) === '' && count($parts) > 1)
		{
			array_pop($parts);
		}

		$partCount = count($parts);
		if ($partCount === 0 || $partCount > 4)
		{
			return null;
		}

		$numbers = [];
		foreach ($parts as $part)
		{
			if ($part === '')
			{
				return null;
			}

			$radix = 10;
			$digits = $part;

			if (preg_match('/^0[xX]/', $part))
			{
				$radix = 16;
				$digits = substr($part, 2);
			}
			elseif (strlen($part) > 1 && $part[0] === '0')
			{
				$radix = 8;
				$digits = substr($part, 1);
			}

			if ($digits === '')
			{
				return null;
			}

			$validDigitsPattern = $radix === 16 ? '/^[0-9a-fA-F]+$/' : ($radix === 8 ? '/^[0-7]+$/' : '/^[0-9]+$/');
			if (!preg_match($validDigitsPattern, $digits))
			{
				return null;
			}

			$value = intval($digits, $radix);
			if ($value < 0 || $value > 4294967295)
			{
				return null;
			}

			$numbers[] = $value;
		}

		$count = count($numbers);
		for ($i = 0; $i < $count - 1; $i++)
		{
			if ($numbers[$i] > 255)
			{
				return null;
			}
		}

		$last = $numbers[$count - 1];
		$maxLast = (256 ** (5 - $count)) - 1;
		if ($last > $maxLast)
		{
			return null;
		}

		$ipv4 = $last;
		for ($i = 0; $i < $count - 1; $i++)
		{
			$ipv4 += $numbers[$i] * (256 ** (3 - $i));
		}

		if ($ipv4 < 0 || $ipv4 > 4294967295)
		{
			return null;
		}

		return long2ip($ipv4);
	}
}
