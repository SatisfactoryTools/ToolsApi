<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\RateLimit;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;

/** Fixed-window counters in the rate_limit table, keyed by a hash so no IP or login is stored in the clear. */
class RateLimiter
{

	private const int LOGIN_FAILURES_BEFORE_BACKOFF = 5;
	private const int LOGIN_BACKOFF_BASE = 30;
	private const int LOGIN_BACKOFF_MAX = 900;
	private const int LOGIN_FAILURE_MEMORY = 3600;

	/** @param array<string, array{limit: int, window: int}> $limits */
	public function __construct(
		private readonly Connection $connection,
		private readonly array $limits,
	)
	{
	}

	public function hasLimit(string $name): bool
	{
		return isset($this->limits[$name]);
	}

	/** @throws RateLimitExceeded */
	public function hit(string $name, string $subject): void
	{
		$limit = $this->limits[$name] ?? throw new InvalidArgumentException('Unknown rate limit: ' . $name);
		$now = new DateTimeImmutable();
		$counter = $this->increment($this->key($name, $subject), $now, $now->modify('+' . $limit['window'] . ' seconds'), false);

		if ($counter['hits'] > $limit['limit']) {
			throw new RateLimitExceeded(max(1, $counter['windowEndsAt']->getTimestamp() - $now->getTimestamp()));
		}

		// Expired rows only cost space, so they are swept now and then rather than on every hit.
		if (random_int(1, 100) === 1) {
			$this->connection->executeStatement(
				'DELETE FROM rate_limit WHERE window_ends_at < :before',
				['before' => $now->modify('-1 day')],
				['before' => Types::DATETIME_IMMUTABLE],
			);
		}
	}

	/** @throws RateLimitExceeded */
	public function assertLoginAllowed(string $login): void
	{
		$row = $this->connection->fetchAssociative(
			'SELECT hits, window_ends_at, last_hit_at FROM rate_limit WHERE id = :id',
			['id' => $this->loginKey($login)],
		);
		if ($row === false) {
			return;
		}

		$now = new DateTimeImmutable();
		$failures = (int) $row['hits'];
		if ($failures < self::LOGIN_FAILURES_BEFORE_BACKOFF || new DateTimeImmutable((string) $row['window_ends_at']) <= $now) {
			return;
		}

		$delay = min(self::LOGIN_BACKOFF_BASE * 2 ** min($failures - self::LOGIN_FAILURES_BEFORE_BACKOFF, 10), self::LOGIN_BACKOFF_MAX);
		$retryAfter = (new DateTimeImmutable((string) $row['last_hit_at']))->getTimestamp() + $delay - $now->getTimestamp();
		if ($retryAfter > 0) {
			throw new RateLimitExceeded($retryAfter, 'Too many failed sign-in attempts. Please try again in ' . $this->describe($retryAfter) . '.');
		}
	}

	public function recordLoginFailure(string $login): void
	{
		$now = new DateTimeImmutable();
		$this->increment($this->loginKey($login), $now, $now->modify('+' . self::LOGIN_FAILURE_MEMORY . ' seconds'), true);
	}

	public function clearLoginFailures(string $login): void
	{
		$this->connection->executeStatement('DELETE FROM rate_limit WHERE id = :id', ['id' => $this->loginKey($login)]);
	}

	/** @return array{hits: int, windowEndsAt: DateTimeImmutable} */
	private function increment(string $key, DateTimeImmutable $now, DateTimeImmutable $windowEnd, bool $slideWindow): array
	{
		// MySQL applies ON DUPLICATE KEY assignments left to right, so hits must be decided before window_ends_at changes.
		$this->connection->executeStatement(
			'INSERT INTO rate_limit (id, hits, window_ends_at, last_hit_at) VALUES (:id, 1, :windowEnd, :now)
			ON DUPLICATE KEY UPDATE
				hits = IF(window_ends_at <= :now, 1, hits + 1),
				window_ends_at = ' . ($slideWindow ? ':windowEnd' : 'IF(window_ends_at <= :now, :windowEnd, window_ends_at)') . ',
				last_hit_at = :now',
			['id' => $key, 'windowEnd' => $windowEnd, 'now' => $now],
			['windowEnd' => Types::DATETIME_IMMUTABLE, 'now' => Types::DATETIME_IMMUTABLE],
		);

		$row = $this->connection->fetchAssociative('SELECT hits, window_ends_at FROM rate_limit WHERE id = :id', ['id' => $key]);

		return [
			'hits' => $row === false ? 1 : (int) $row['hits'],
			'windowEndsAt' => $row === false ? $windowEnd : new DateTimeImmutable((string) $row['window_ends_at']),
		];
	}

	private function key(string $name, string $subject): string
	{
		return hash('sha256', $name . "\0" . $subject);
	}

	private function loginKey(string $login): string
	{
		return $this->key('login-failures', mb_strtolower(trim($login)));
	}

	private function describe(int $seconds): string
	{
		[$amount, $unit] = $seconds < 60 ? [$seconds, 'second'] : [(int) ceil($seconds / 60), 'minute'];

		return $amount . ' ' . $unit . ($amount === 1 ? '' : 's');
	}

}
