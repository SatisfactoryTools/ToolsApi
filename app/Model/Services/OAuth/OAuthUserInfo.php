<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\OAuth;

/**
 * What we read from a third-party provider: the stable account id, a verified email
 * (where available) used to match against existing users, and — display only — the
 * provider's nickname and avatar so the frontend can greet users who have no login of
 * their own choosing. Nickname and avatar are never used for matching.
 */
class OAuthUserInfo
{

	/** Longest nickname we store (OAuthIdentity::$nickname column width). */
	public const NicknameMaxLength = 100;

	/** Longest avatar URL we store (OAuthIdentity::$avatarUrl column width). */
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

		// Providers return arbitrary user-typed text; strip control characters and
		// surrounding whitespace, and cap to the column width.
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
		// Only https URLs are worth handing to a browser; anything else is dropped.
		if ($url === '' || strlen($url) > self::AvatarUrlMaxLength || !str_starts_with($url, 'https://')) {
			return null;
		}

		return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
	}

}
