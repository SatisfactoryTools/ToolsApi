<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\OAuth;

class OAuthUserInfo
{

	/** OAuthIdentity::$nickname column width. */
	public const NicknameMaxLength = 100;

	/** OAuthIdentity::$avatarUrl column width. */
	public const AvatarUrlMaxLength = 512;

	public readonly ?string $nickname;

	public readonly ?string $avatarUrl;

	public function __construct(
		public readonly string $providerUserId,
		public readonly ?string $email = null,
		?string $nickname = null,
		?string $avatarUrl = null,
	)
	{
		$this->nickname = self::normalizeNickname($nickname);
		$this->avatarUrl = self::normalizeAvatarUrl($avatarUrl);
	}

	/** @return array{providerUserId: string, email: string|null, nickname: string|null, avatarUrl: string|null} */
	public function toArray(): array
	{
		return [
			'providerUserId' => $this->providerUserId,
			'email' => $this->email,
			'nickname' => $this->nickname,
			'avatarUrl' => $this->avatarUrl,
		];
	}

	/** @param array<string, mixed> $data */
	public static function fromArray(array $data): ?self
	{
		$id = $data['providerUserId'] ?? null;
		if (!is_string($id) || $id === '') {
			return null;
		}

		return new self(
			$id,
			is_string($data['email'] ?? null) ? $data['email'] : null,
			is_string($data['nickname'] ?? null) ? $data['nickname'] : null,
			is_string($data['avatarUrl'] ?? null) ? $data['avatarUrl'] : null,
		);
	}

	private static function normalizeNickname(?string $nickname): ?string
	{
		if ($nickname === null) {
			return null;
		}

		$clean = trim((string) preg_replace('/[\p{C}]+/u', '', $nickname));
		if ($clean === '') {
			return null;
		}

		return mb_substr($clean, 0, self::NicknameMaxLength);
	}

	private static function normalizeAvatarUrl(?string $url): ?string
	{
		if ($url === null) {
			return null;
		}

		$url = trim($url);
		if ($url === '' || strlen($url) > self::AvatarUrlMaxLength || !str_starts_with($url, 'https://')) {
			return null;
		}

		return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
	}

}
