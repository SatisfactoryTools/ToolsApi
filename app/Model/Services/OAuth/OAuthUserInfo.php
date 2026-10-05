<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\OAuth;

/** Nickname and avatar are display only, never used for matching. */
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
