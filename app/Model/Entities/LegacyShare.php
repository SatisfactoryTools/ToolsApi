<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Entities;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use greeny\SatisfactoryTools\Api\Model\Entities\Attributes\Identifier;

/**
 * A production-line share carried over from the old Satisfactory Tools API
 * (api.satisfactorytools.com, table `shared`). Old planner links look like
 * https://www.satisfactorytools.com/1.0/production?share={slug}; the slug is the only
 * key. The payload is kept verbatim so GET /v1/legacy-shares/{slug} can serve exactly what
 * the old API served. Rows are written only by the legacy-shares:import command.
 */
#[ORM\Entity]
#[ORM\Table(name: 'legacy_share')]
class LegacyShare
{

	use Identifier;

	/** The old API's share slug: 20 alphanumeric characters (we accept up to 64). */
	#[ORM\Column(unique: true, length: 64)]
	public string $slug;

	/**
	 * The raw JSON document as stored by the old API, i.e. `{ "metadata": {...},
	 * "request": {...} }`. Not decoded or normalized on import, so a re-import of an
	 * unchanged row compares byte for byte.
	 */
	#[ORM\Column(type: Types::TEXT)]
	public string $data;

	/** When the row was first imported (not when the share was created originally). */
	#[ORM\Column]
	public DateTimeImmutable $createdAt;

}
