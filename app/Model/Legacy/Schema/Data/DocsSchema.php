<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Data;

/**
 * The game data as the old solver saw it. Ported from the old API
 * (app/Solver/Schema/Data/DocsSchema.php) without the schematics and materials, which the
 * legacy data files never carried (Schema::convertLegacyData left them empty) and the
 * solver never read.
 */
class DocsSchema
{

	/** @var Item[] */
	public array $items = [];

	/** @var Recipe[] */
	public array $recipes = [];

	/** @var Building[] */
	public array $buildings = [];

	/** @var string[] */
	public array $resources = [];

}
