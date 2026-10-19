<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;

#[ORM\Entity]
#[ORM\Table(name: 'client_error')]
#[ORM\Index(name: 'client_error_notified', columns: ['notified_at'])]
#[ORM\Index(name: 'client_error_first_seen', columns: ['first_seen_at'])]
class ClientError
{

	use Identifier;

	#[ORM\Column(length: 64, unique: true)]
	public string $signature;

	#[ORM\Column(type: 'text')]
	public string $message;

	#[ORM\Column(type: 'text')]
	public string $details;

	#[ORM\Column(length: 100)]
	public string $app;

	#[ORM\Column(length: 100)]
	public string $build;

	#[ORM\Column]
	public int $count = 1;

	#[ORM\Column]
	public DateTimeImmutable $firstSeenAt;

	#[ORM\Column]
	public DateTimeImmutable $lastSeenAt;

	#[ORM\Column(nullable: true)]
	public ?DateTimeImmutable $notifiedAt = null;

	#[ORM\Column]
	public int $countAtNotification = 0;

}
