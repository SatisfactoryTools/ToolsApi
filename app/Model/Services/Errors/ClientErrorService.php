<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\Errors;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use greeny\SatisfactoryTools\Api\Model\Entities\ClientError;
use greeny\SatisfactoryTools\Api\Model\Repositories\ClientErrorRepository;

/** The endpoint is anonymous: the per-IP limit (RateLimitMiddleware) slows one sender down, and the hourly caps bound what a flood from many can do to the table and the channel. */
class ClientErrorService
{

	private const int NewErrorsPerHour = 200;

	private const int NoticesPerHour = 20;

	/** A known error is mentioned again once a day while it keeps happening. */
	private const string RepeatNoticeAfter = '-1 day';

	/** Errors not seen for this long are deleted. */
	private const string RetainFor = '-90 days';

	public function __construct(
		private readonly ClientErrorRepository $repository,
		private readonly DiscordErrorNotifier $notifier,
	)
	{
	}

	public function record(string $message, string $details, string $app, string $build): void
	{
		$now = new DateTimeImmutable();
		$signature = hash('sha256', $message);
		$error = $this->repository->getBySignature($signature);

		if ($error === null) {
			if ($this->repository->countFirstSeenSince($now->modify('-1 hour')) >= self::NewErrorsPerHour) {
				return;
			}
			$error = new ClientError();
			$error->signature = $signature;
			$error->message = $message;
			// Only the first report's details are kept: anyone can post a known message again with made-up details.
			$error->details = $details;
			$error->app = $app;
			$error->build = $build;
			$error->firstSeenAt = $now;
		} else {
			$error->count++;
		}
		$error->lastSeenAt = $now;

		if (random_int(1, 100) === 1) {
			$this->repository->deleteLastSeenBefore($now->modify(self::RetainFor));
		}

		try {
			$this->repository->save($error);
		} catch (UniqueConstraintViolationException) {
			// Another request inserted the same error a moment earlier; that one notifies.
			return;
		}

		$repeat = $error->notifiedAt !== null;
		if ($repeat && $error->notifiedAt > $now->modify(self::RepeatNoticeAfter)) {
			return;
		}
		if ($this->repository->countNotifiedSince($now->modify('-1 hour')) >= self::NoticesPerHour) {
			return;
		}
		if ($this->notifier->notify($error, $repeat)) {
			$error->notifiedAt = $now;
			$error->countAtNotification = $error->count;
			$this->repository->save($error);
		}
	}

}
