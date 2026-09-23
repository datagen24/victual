<?php

namespace Victual\Services\Storage;

use Victual\Services\DatabaseService;
use Victual\Services\FilesService;

/**
 * Keeps files as BYTEA rows in the files table, so that the application directory needs
 * no persistent volume and one pg_dump captures a file and the row that points at it
 * together. PostgreSQL only - see migrations/0258.pgsql.sql and plan 01.
 *
 * Every statement here is raw PDO rather than LessQL, which is not a style choice.
 * LessQL builds its SQL by quoting values into the statement text, and PDO::quote()
 * returns false for a string that is not valid UTF-8 - so a JPEG becomes an empty value
 * and the insert dies as a syntax error ("VALUES ('x', )"). Measured on this tree, not
 * assumed. PDO::PARAM_LOB is the binding that actually moves bytes.
 */
class DatabaseStorage extends FileStorage
{
	/**
	 * How much of a BYTEA stream is buffered in memory before php://temp spills it to
	 * disk. PDO hands the column back as a stream tied to its statement, so it has to be
	 * copied somewhere the response can still read from after the statement is gone.
	 */
	const TEMP_STREAM_MEMORY_LIMIT = 2097152;

	/**
	 * The digest GetContentDigest() computes, spelled as PHP's hash() names it.
	 *
	 * Named once so that the two halves of a comparison cannot drift apart: the caller
	 * hashes a file with this, the server hashes the column with the SQL function of the
	 * same name, and changing one without the other is a compile-time-visible edit rather
	 * than a silent mismatch.
	 */
	const CONTENT_DIGEST_ALGORITHM = 'sha256';

	/** @var \PDO The raw connection, shared with the rest of the application */
	private $Pdo;

	public function __construct()
	{
		$this->Pdo = DatabaseService::GetInstance()->GetDbConnectionRaw();
	}

	public function Exists(string $group, string $name): bool
	{
		$statement = $this->Pdo->prepare('SELECT 1 FROM files WHERE file_group = ? AND name = ?');
		$statement->execute([$group, $name]);

		return $statement->fetchColumn() !== false;
	}

	public function Read(string $group, string $name)
	{
		$statement = $this->Pdo->prepare('SELECT content FROM files WHERE file_group = ? AND name = ?');
		$statement->execute([$group, $name]);
		$statement->bindColumn(1, $content, \PDO::PARAM_LOB);

		if ($statement->fetch(\PDO::FETCH_BOUND) === false)
		{
			return null;
		}

		// The stream PDO hands back belongs to the statement that produced it. Slim reads
		// the response body long after this method has returned, so the bytes are copied
		// into a stream this application owns rather than handed over as a borrowed one.
		// php://temp keeps small files in memory and spills larger ones to disk.
		$copy = fopen('php://temp/maxmemory:' . self::TEMP_STREAM_MEMORY_LIMIT, 'w+b');

		if (is_resource($content))
		{
			stream_copy_to_stream($content, $copy);
		}
		else
		{
			fwrite($copy, (string)$content);
		}

		rewind($copy);

		return $copy;
	}

	public function Create(string $group, string $name, $source): void
	{
		self::AssertValidName($name);

		$buffer = $this->BufferSource($source);

		try
		{
			// A plain insert, letting UNIQUE(file_group, name) be what says "exists".
			// Checking first and then inserting would be both slower and racy; this
			// mirrors what fopen($path, 'xb') did.
			$this->Insert($group, $name, $buffer);
		}
		catch (\PDOException $ex)
		{
			// 23505 is unique_violation
			if ($ex->getCode() === '23505')
			{
				throw new \Exception("Error while creating file $name");
			}

			throw $ex;
		}
		finally
		{
			if (is_resource($buffer))
			{
				fclose($buffer);
			}
		}
	}

	public function Write(string $group, string $name, $source): void
	{
		self::AssertValidName($name);

		$buffer = $this->BufferSource($source);

		try
		{
			$statement = $this->Pdo->prepare('INSERT INTO files (file_group, name, mime_type, size_bytes, is_derivative, content)
				VALUES (?, ?, ?, ?, ?, ?)
				ON CONFLICT (file_group, name) DO UPDATE SET
					mime_type = EXCLUDED.mime_type,
					size_bytes = EXCLUDED.size_bytes,
					is_derivative = EXCLUDED.is_derivative,
					content = EXCLUDED.content');

			$this->BindRow($statement, $group, $name, $buffer);
			$statement->execute();
		}
		finally
		{
			if (is_resource($buffer))
			{
				fclose($buffer);
			}
		}
	}

	public function Delete(string $group, string $name): void
	{
		$statement = $this->Pdo->prepare('DELETE FROM files WHERE file_group = ? AND name = ?');
		$statement->execute([$group, $name]);
	}

	public function ListNames(string $group, string $prefix): array
	{
		$statement = $this->Pdo->prepare("SELECT name FROM files WHERE file_group = ? AND name LIKE ? ESCAPE '\\'");
		$statement->execute([$group, self::EscapeLikePrefix($prefix) . '%']);

		return $statement->fetchAll(\PDO::FETCH_COLUMN);
	}

	public function GetMimeType(string $group, string $name): ?string
	{
		$statement = $this->Pdo->prepare('SELECT mime_type FROM files WHERE file_group = ? AND name = ?');
		$statement->execute([$group, $name]);

		$mimeType = $statement->fetchColumn();

		return $mimeType === false ? null : $mimeType;
	}

	/**
	 * The stored size of a file in bytes, or null when there is no such row.
	 *
	 * Not part of the FileStorage contract - nothing in the request path asks how big a
	 * stored file is. bin/victual-files-import does, as the cheap half of deciding whether
	 * a file is already imported: unequal lengths settle it without hashing anything, and
	 * equal ones settle nothing, which is what GetContentDigest() is for.
	 */
	public function GetSizeBytes(string $group, string $name): ?int
	{
		$statement = $this->Pdo->prepare('SELECT size_bytes FROM files WHERE file_group = ? AND name = ?');
		$statement->execute([$group, $name]);

		$size = $statement->fetchColumn();

		return $size === false ? null : (int)$size;
	}

	/**
	 * The digest of a stored file's bytes as lowercase hex, or null when there is no row.
	 *
	 * Not part of the FileStorage contract either, and for the same reason: nothing in the
	 * request path asks. bin/victual-files-import asks, because length is not identity - a
	 * source file can change while keeping its length, and a row killed halfway can hold
	 * the wrong bytes under the right size_bytes - and a command whose whole purpose is to
	 * let an operator delete the old volume has to be able to prove the bytes arrived.
	 *
	 * Computed by the server over the column, deliberately, rather than kept in a column
	 * of its own. A stored digest is written by the same statement as the bytes, so it
	 * agrees with them by construction: it can prove a row is self consistent and never
	 * that the row holds the file. Hashing content proves what is actually in it. It also
	 * costs the schema nothing and every write path nothing, and because the hashing
	 * happens in the server, verification moves no bytes over the wire and buffers none in
	 * PHP - the same streaming discipline Read() and BufferSource() keep.
	 *
	 * sha256(bytea) is a built-in function from PostgreSQL 11 on and needs no extension;
	 * this table is PostgreSQL-only by construction, so there is no portability cost.
	 */
	public function GetContentDigest(string $group, string $name): ?string
	{
		$statement = $this->Pdo->prepare("SELECT encode(sha256(content), 'hex') FROM files WHERE file_group = ? AND name = ?");
		$statement->execute([$group, $name]);

		$digest = $statement->fetchColumn();

		return $digest === false ? null : (string)$digest;
	}

	/**
	 * Escapes the wildcards in a literal prefix so that a file name containing "%" or "_"
	 * matches itself rather than everything.
	 *
	 * Every downscale prefix is a user supplied file name, so this is not hypothetical:
	 * "a_b" would otherwise also match "axb".
	 */
	public static function EscapeLikePrefix(string $prefix): string
	{
		return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix);
	}

	/**
	 * Whether this name is one of the cached downscaled copies rather than an original.
	 *
	 * Derived from the name because that is where the information already is - the
	 * downscale path has always encoded it there - and storing it as a column is what
	 * makes "drop every thumbnail" one statement.
	 */
	private static function IsDerivativeName(string $name): bool
	{
		return str_contains($name, FilesService::DOWNSCALED_INFIX);
	}

	/**
	 * Copies a source into a php://temp stream positioned at 0.
	 *
	 * Buffered rather than streamed straight into the statement because the row needs the
	 * size and the content type as well as the bytes, and both are answers about the whole
	 * file. php://temp keeps that bounded: small files stay in memory, large ones spill.
	 *
	 * @param string|resource $source
	 * @return resource
	 */
	private function BufferSource($source)
	{
		$buffer = fopen('php://temp/maxmemory:' . self::TEMP_STREAM_MEMORY_LIMIT, 'w+b');

		try
		{
			$this->CopySource($source, $buffer);
		}
		catch (\Throwable $ex)
		{
			// Nothing was inserted, so nothing has to be cleaned up in the database - only
			// the buffer, which is what this drops.
			fclose($buffer);
			throw $ex;
		}

		rewind($buffer);

		return $buffer;
	}

	/**
	 * Inserts a new row, letting the unique constraint refuse a name that is taken.
	 *
	 * @param resource $buffer A readable stream positioned at 0
	 */
	private function Insert(string $group, string $name, &$buffer): void
	{
		$statement = $this->Pdo->prepare('INSERT INTO files (file_group, name, mime_type, size_bytes, is_derivative, content)
			VALUES (?, ?, ?, ?, ?, ?)');

		$this->BindRow($statement, $group, $name, $buffer);
		$statement->execute();
	}

	/**
	 * Binds the six columns both write statements share.
	 *
	 * The content is bound as PDO::PARAM_LOB from the stream: pdo_pgsql reads it during
	 * execute() and sends it as a bytea literal. Everything else is an ordinary value.
	 *
	 * @param resource $buffer A readable stream positioned at 0
	 */
	private function BindRow(\PDOStatement $statement, string $group, string $name, &$buffer): void
	{
		$size = fstat($buffer)['size'];

		// finfo_buffer over the leading bytes rather than mime_content_type over a path,
		// which is the one thing in this plan that could change a response: the two are the
		// same magic database and were verified to agree on a real JPEG, PNG, PDF and text
		// file, plus HTML wearing a .png name.
		$head = $size > 0 ? fread($buffer, min($size, self::TEMP_STREAM_MEMORY_LIMIT)) : '';
		rewind($buffer);
		$mimeType = finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $head === false ? '' : $head);

		$statement->bindValue(1, $group);
		$statement->bindValue(2, $name);
		$statement->bindValue(3, $mimeType === false ? null : $mimeType);
		$statement->bindValue(4, $size, \PDO::PARAM_INT);
		$statement->bindValue(5, self::IsDerivativeName($name) ? 1 : 0, \PDO::PARAM_INT);
		$statement->bindParam(6, $buffer, \PDO::PARAM_LOB);
	}
}
