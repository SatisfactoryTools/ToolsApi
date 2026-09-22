<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services;

use Ramsey\Uuid\UuidInterface;
use RuntimeException;
use finfo;

/**
 * Stores screenshots used in help articles. The editor sends the bytes base64-encoded in
 * a JSON body (the rest of the API is JSON too, and screenshots are small), so what
 * arrives here is the decoded file. Files are written under a generated uuid name, so an
 * editor's file name never reaches the filesystem or a URL. Paths are relative to the web
 * root, matching HelpImage::$path.
 */
class HelpImageStorage
{

	/** Decoded size limit; a screenshot has no business being larger. */
	public const MAX_SIZE = 4 * 1024 * 1024;

	/** Accepted uploads, by sniffed MIME type, with the extension each one gets. */
	private const EXTENSIONS = [
		'image/png' => 'png',
		'image/jpeg' => 'jpg',
		'image/webp' => 'webp',
		'image/gif' => 'gif',
	];

	private const DIR = 'data/help/images';

	public function __construct(
		private readonly string $wwwDir,
	)
	{
	}

	/**
	 * The extension for these bytes, from their real content - never from what the
	 * editor called the file. Null when it is not an image type help articles accept.
	 */
	public function extensionFor(string $bytes): ?string
	{
		$mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
		return $mimeType === false ? null : (self::EXTENSIONS[$mimeType] ?? null);
	}

	/**
	 * Writes the image and returns its web-root-relative path.
	 *
	 * @throws RuntimeException when the file cannot be written
	 */
	public function store(UuidInterface $uuid, string $bytes, string $extension): string
	{
		$relativePath = self::DIR . '/' . $uuid->toString() . '.' . $extension;
		$absolute = $this->absolutePath($relativePath);

		$dir = dirname($absolute);
		if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
			throw new RuntimeException(sprintf('Could not create directory: %s', $dir));
		}

		if (@file_put_contents($absolute, $bytes) === false) {
			throw new RuntimeException(sprintf('Could not write help image: %s', $relativePath));
		}

		return $relativePath;
	}

	public function delete(?string $relativePath): void
	{
		if ($relativePath === null) {
			return;
		}

		$absolute = $this->absolutePath($relativePath);
		if (is_file($absolute)) {
			@unlink($absolute);
		}
	}

	private function absolutePath(string $relativePath): string
	{
		return rtrim($this->wwwDir, '/') . '/' . ltrim($relativePath, '/');
	}

}
