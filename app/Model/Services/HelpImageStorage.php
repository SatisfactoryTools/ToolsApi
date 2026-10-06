<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services;

use Ramsey\Uuid\UuidInterface;
use RuntimeException;
use finfo;

class HelpImageStorage
{

	public const int MAX_SIZE = 4 * 1024 * 1024;

	private const array EXTENSIONS = [
		'image/png' => 'png',
		'image/jpeg' => 'jpg',
		'image/webp' => 'webp',
		'image/gif' => 'gif',
	];

	private const string DIR = 'data/help/images';

	public function __construct(
		private readonly string $wwwDir,
	)
	{
	}

	/** Sniffed from the real content, never from what the editor called the file. */
	public function extensionFor(string $bytes): ?string
	{
		$mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
		return $mimeType === false ? null : (self::EXTENSIONS[$mimeType] ?? null);
	}

	public function store(UuidInterface $uuid, string $bytes, string $extension): string
	{
		$relativePath = self::DIR . '/' . $uuid->toString() . '.' . $extension;
		$absolute = $this->absolutePath($relativePath);

		$dir = dirname($absolute);
		if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) { // @ intentional
			throw new RuntimeException(sprintf('Could not create directory: %s', $dir));
		}

		if (@file_put_contents($absolute, $bytes) === false) { // @ intentional
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
			@unlink($absolute); // @ intentional
		}
	}

	private function absolutePath(string $relativePath): string
	{
		return rtrim($this->wwwDir, '/') . '/' . ltrim($relativePath, '/');
	}

}
