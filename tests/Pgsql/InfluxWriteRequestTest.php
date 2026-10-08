<?php

namespace Victual\Tests\Pgsql;

use PHPUnit\Framework\TestCase;
use Victual\Services\Influx\InfluxEventWriter;

/**
 * InfluxEventWriter::BuildRequest(): the three InfluxDB write dialects. Pure, no network.
 * A home lab commonly keeps an InfluxDB 1.x (Home Assistant's history) beside newer ones.
 */
class InfluxWriteRequestTest extends TestCase
{
	public function testVersion1WritesToSlashWriteWithBasicAuth(): void
	{
		[$url, $query, $headers, $auth] = InfluxEventWriter::BuildRequest(1, 'http://ha:8086/', 'pw', 'victual', 'ignored', 'homeassistant');

		self::assertSame('http://ha:8086/write', $url);
		self::assertSame(['db' => 'homeassistant', 'precision' => 'ns'], $query);
		self::assertSame([], $headers);
		self::assertSame(['victual', 'pw'], $auth);
	}

	public function testVersion1WithoutAUsernameIsUnauthenticated(): void
	{
		[, , $headers, $auth] = InfluxEventWriter::BuildRequest(1, 'http://ha:8086', 'pw', '', '', 'db');

		self::assertSame([], $headers);
		self::assertNull($auth);
	}

	public function testVersion2WritesToApiV2WithTokenHeader(): void
	{
		[$url, $query, $headers, $auth] = InfluxEventWriter::BuildRequest(2, 'http://i:8086', 'tok', '', 'home', 'victual');

		self::assertSame('http://i:8086/api/v2/write', $url);
		self::assertSame(['org' => 'home', 'bucket' => 'victual', 'precision' => 'ns'], $query);
		self::assertSame(['Authorization' => 'Token tok'], $headers);
		self::assertNull($auth);
	}

	public function testVersion3WritesToWriteLpWithBearerAndNoOrg(): void
	{
		[$url, $query, $headers, $auth] = InfluxEventWriter::BuildRequest(3, 'http://i:8181', 'tok', '', 'ignored', 'victual');

		self::assertSame('http://i:8181/api/v3/write_lp', $url);
		self::assertSame(['db' => 'victual', 'precision' => 'nanosecond'], $query);
		self::assertSame(['Authorization' => 'Bearer tok'], $headers);
		self::assertNull($auth);
	}

	public function testVersion3WithoutATokenSendsNoAuthorization(): void
	{
		[, , $headers] = InfluxEventWriter::BuildRequest(3, 'http://i:8181', '', '', '', 'victual');

		self::assertSame([], $headers);
	}
}
