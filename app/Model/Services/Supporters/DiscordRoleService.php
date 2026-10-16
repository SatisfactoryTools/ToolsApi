<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\Supporters;

/** Needs a bot in the server with Manage Roles, and the bot's own role placed above the supporter role. */
class DiscordRoleService
{

	private const string ApiUrl = 'https://discord.com/api/v10';

	private const int UnknownMember = 10007;

	public function __construct(
		private readonly SupporterHttpClient $http,
		private readonly string $botToken,
		private readonly string $guildId,
		private readonly string $roleId,
	)
	{
	}

	public function isEnabled(): bool
	{
		return $this->botToken !== '' && $this->guildId !== '' && $this->roleId !== '';
	}

	/** False when the user is not in the server (yet); the nightly sync tries again. */
	public function grant(string $discordUserId): bool
	{
		if (!$this->isEnabled() || preg_match('~^\d{1,30}$~D', $discordUserId) !== 1) {
			return false;
		}

		$response = $this->http->request(
			'PUT',
			sprintf('%s/guilds/%s/members/%s/roles/%s', self::ApiUrl, rawurlencode($this->guildId), $discordUserId, rawurlencode($this->roleId)),
			'',
			[
				'Authorization' => 'Bot ' . $this->botToken,
				'X-Audit-Log-Reason' => 'Satisfactory Tools supporter',
			],
		);

		if ($response['status'] === 204 || $response['status'] === 200) {
			return true;
		}
		if ($response['status'] === 404 && ($response['body']['code'] ?? null) === self::UnknownMember) {
			return false;
		}

		throw new SupporterSyncException(sprintf(
			'Discord refused the supporter role for %s: HTTP %d %s',
			$discordUserId,
			$response['status'],
			(string) ($response['body']['message'] ?? ''),
		));
	}

}
