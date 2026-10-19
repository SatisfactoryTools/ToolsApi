<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\Errors;

use greeny\SatisfactoryTools\Api\Model\Entities\ClientError;
use greeny\SatisfactoryTools\Api\Model\Services\Supporters\SupporterHttpClient;
use greeny\SatisfactoryTools\Api\Model\Services\Supporters\SupporterSyncException;
use Psr\Log\LoggerInterface;

/** Posts with the supporter bot, which needs View Channel + Send Messages in the error channel. */
class DiscordErrorNotifier
{

	private const string ApiUrl = 'https://discord.com/api/v10';

	private const int MessageLimit = 2000;

	public function __construct(
		private readonly SupporterHttpClient $http,
		private readonly LoggerInterface $logger,
		private readonly string $botToken,
		private readonly string $channelId,
	)
	{
	}

	public function isEnabled(): bool
	{
		return $this->botToken !== '' && $this->channelId !== '';
	}

	/** False when Discord did not take it, so the next occurrence tries again. */
	public function notify(ClientError $error, bool $repeat): bool
	{
		if (!$this->isEnabled()) {
			return false;
		}

		try {
			$response = $this->http->request(
				'POST',
				sprintf('%s/channels/%s/messages', self::ApiUrl, rawurlencode($this->channelId)),
				json_encode([
					'content' => $this->content($error, $repeat),
					// Reported text is user-controlled, so it must never ping anyone.
					'allowed_mentions' => ['parse' => []],
				], JSON_THROW_ON_ERROR),
				[
					'Authorization' => 'Bot ' . $this->botToken,
					'Content-Type' => 'application/json',
				],
			);
		} catch (SupporterSyncException $e) {
			$this->logger->warning('Could not post an error to Discord: ' . $e->getMessage());

			return false;
		}

		if ($response['status'] !== 200) {
			$this->logger->warning(sprintf('Discord refused an error notice: HTTP %d %s', $response['status'], (string) ($response['body']['message'] ?? '')));

			return false;
		}

		return true;
	}

	private function content(ClientError $error, bool $repeat): string
	{
		$header = $repeat
			? sprintf('**Error #%d again**: %d× since the last notice, %d× in total', $error->id, $error->count - $error->countAtNotification, $error->count)
			: sprintf('**New error #%d**', $error->id);
		$header .= sprintf(' · %s · %s', $error->app, $error->build);

		$message = '`' . $this->inline(mb_substr($error->message, 0, 300)) . '`';
		$room = self::MessageLimit - mb_strlen($header) - mb_strlen($message) - 20;
		$details = $this->block($error->details);
		if (mb_strlen($details) > $room) {
			$details = mb_substr($details, 0, max(0, $room - 2)) . "\n…";
		}

		return sprintf("%s\n%s\n```\n%s\n```", $header, $message, $details);
	}

	private function inline(string $text): string
	{
		return str_replace(['`', "\n", "\r"], ["'", ' ', ''], $text);
	}

	private function block(string $text): string
	{
		return str_replace('```', "'''", $text);
	}

}
