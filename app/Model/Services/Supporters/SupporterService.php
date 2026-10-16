<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\Supporters;

use DateTimeImmutable;
use greeny\SatisfactoryTools\Api\Model\Entities\Supporter;
use greeny\SatisfactoryTools\Api\Model\Entities\SupporterEmail;
use greeny\SatisfactoryTools\Api\Model\Entities\User;
use greeny\SatisfactoryTools\Api\Model\Repositories\OAuthIdentityRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\SupporterEmailRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\SupporterRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\UserRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * User::$email of a password account was never verified, so it could claim someone else's status - accepted,
 * because the list is not public and a guess would need both the exact email and the knowledge that its owner paid.
 */
class SupporterService
{

	public const array SOURCES = ['patreon', 'paypal', 'legacy', 'manual'];

	public const int LIST_NAME_MAX_LENGTH = 50;

	private const int BATCH_SIZE = 500;

	public function __construct(
		private readonly SupporterRepository $supporterRepository,
		private readonly SupporterEmailRepository $emailRepository,
		private readonly OAuthIdentityRepository $identityRepository,
		private readonly UserRepository $userRepository,
		private readonly SupporterEmailHasher $hasher,
		private readonly DiscordRoleService $discordRoles,
		private readonly LoggerInterface $logger,
	)
	{
	}

	/** @param iterable<SupporterEmailEntry> $entries */
	public function addEmails(iterable $entries): SupporterImportResult
	{
		$result = new SupporterImportResult();
		$batch = [];
		foreach ($entries as $entry) {
			$email = $this->hasher->normalize($entry->email);
			if ($email === null || !in_array($entry->source, self::SOURCES, true)) {
				$result->invalid++;
				continue;
			}
			$batch[$this->hasher->hash($email)] ??= $entry;
			if (count($batch) >= self::BATCH_SIZE) {
				$result->merge($this->addBatch($batch));
				$batch = [];
			}
		}
		if ($batch !== []) {
			$result->merge($this->addBatch($batch));
		}

		return $result;
	}

	public function syncUser(User $user): ?Supporter
	{
		$existing = $this->supporterRepository->getByUser($user);
		if ($existing !== null) {
			$this->syncDiscordRoleQuietly($existing);

			return $existing;
		}

		$hashes = [$this->hasher->hash($user->email)];
		foreach ($this->identityRepository->findAllForUser($user) as $identity) {
			if ($identity->email !== null) {
				$hashes[] = $this->hasher->hash($identity->email);
			}
		}
		$matches = $this->emailRepository->getByHashes($hashes);
		if ($matches === []) {
			return null;
		}

		return $this->grant($user, $this->earliest($matches)->source);
	}

	public function grant(User $user, string $source): Supporter
	{
		$supporter = $this->supporterRepository->getByUser($user);
		if ($supporter === null) {
			$supporter = new Supporter();
			$supporter->user = $user;
			$supporter->source = $source;
			$supporter->since = new DateTimeImmutable();
			$this->supporterRepository->save($supporter);
		}
		$this->syncDiscordRoleQuietly($supporter);

		return $supporter;
	}

	/** The Discord role stays: removing it is left to the server's moderators. */
	public function revoke(User $user, bool $forgetEmails): bool
	{
		if ($forgetEmails) {
			$addresses = [$user->email];
			foreach ($this->identityRepository->findAllForUser($user) as $identity) {
				if ($identity->email !== null) {
					$addresses[] = $identity->email;
				}
			}
			foreach ($addresses as $address) {
				$email = $this->emailRepository->getByHash($this->hasher->hash($address));
				if ($email !== null) {
					$this->emailRepository->delete($email);
				}
			}
		}

		$supporter = $this->supporterRepository->getByUser($user);
		if ($supporter === null) {
			return false;
		}
		$this->supporterRepository->delete($supporter);

		return true;
	}

	/** @return int roles handed out */
	public function syncAllDiscordRoles(): int
	{
		if (!$this->discordRoles->isEnabled()) {
			return 0;
		}

		$granted = 0;
		foreach ($this->supporterRepository->findAll() as $supporter) {
			try {
				if ($this->syncDiscordRole($supporter)) {
					$granted++;
				}
			} catch (SupporterSyncException $e) {
				$this->logger->warning($e->getMessage(), ['exception' => $e]);
			}
		}

		return $granted;
	}

	/** @return bool whether the role was handed out just now */
	public function syncDiscordRole(Supporter $supporter): bool
	{
		if (!$this->discordRoles->isEnabled()) {
			return false;
		}

		$identity = $this->identityRepository->getByUserAndProvider($supporter->user, 'discord');
		if ($identity === null || $identity->providerUserId === $supporter->discordRoleUserId) {
			return false;
		}
		if (!$this->discordRoles->grant($identity->providerUserId)) {
			return false;
		}

		$supporter->discordRoleUserId = $identity->providerUserId;
		$this->supporterRepository->save($supporter);

		return true;
	}

	public function normalizeListName(mixed $raw): ?string
	{
		if (!is_string($raw)) {
			return null;
		}
		$name = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\p{C}+/u', '', $raw)));
		if ($name === '' || mb_strlen($name) > self::LIST_NAME_MAX_LENGTH) {
			return null;
		}

		return $name;
	}

	/** @param array<string, SupporterEmailEntry> $batch keyed by email hash */
	private function addBatch(array $batch): SupporterImportResult
	{
		$result = new SupporterImportResult();
		$existing = $this->emailRepository->getByHashes(array_keys($batch));
		$now = new DateTimeImmutable();

		foreach ($batch as $hash => $entry) {
			if (isset($existing[$hash])) {
				$result->known++;
				continue;
			}
			$email = new SupporterEmail();
			$email->emailHash = $hash;
			$email->source = $entry->source;
			$email->externalId = $entry->externalId !== null ? mb_substr($entry->externalId, 0, 191) : null;
			$email->createdAt = $now;
			$this->emailRepository->save($email, flush: false);
			$result->added++;
		}
		$this->emailRepository->flush();

		/** @var array<int, array{User, string}> $matches user id => [user, matched hash] */
		$matches = [];
		foreach ($this->identityRepository->findByEmailHashes(array_keys($batch), $this->hasher) as $identity) {
			$matches[$identity->user->id] ??= [$identity->user, $this->hasher->hash((string) $identity->email)];
		}
		foreach ($this->userRepository->findByEmailHashes(array_keys($batch), $this->hasher) as $user) {
			$matches[$user->id] ??= [$user, $this->hasher->hash($user->email)];
		}

		// Known emails are matched again too: an account linked since the first import picks them up here.
		foreach ($matches as [$user, $hash]) {
			if ($this->supporterRepository->getByUser($user) !== null) {
				continue;
			}
			$this->grant($user, $existing[$hash]->source ?? $batch[$hash]->source);
			$result->accountsMarked++;
		}

		return $result;
	}

	/** @param array<string, SupporterEmail> $matches */
	private function earliest(array $matches): SupporterEmail
	{
		usort($matches, static fn (SupporterEmail $a, SupporterEmail $b): int => $a->createdAt <=> $b->createdAt);

		return $matches[0];
	}

	/** A Discord outage must not break signing in or an import. */
	private function syncDiscordRoleQuietly(Supporter $supporter): void
	{
		try {
			$this->syncDiscordRole($supporter);
		} catch (Throwable $e) {
			$this->logger->warning('Could not hand out the Discord supporter role: ' . $e->getMessage(), ['exception' => $e]);
		}
	}

}
