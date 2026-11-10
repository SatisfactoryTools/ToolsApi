<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\Meta;

/** Map landing pages (/{version}/map/{topic}). Mirrors sftools-frontend: src/app/Model/Map/MapTopicCatalog.ts - keep the texts in sync. */
final class MapTopicCatalog
{

	public const string MAP_HEADING = 'Interactive map';
	public const string MAP_DESCRIPTION = 'Interactive map of Satisfactory (%s) with every resource node, well and geyser, plus power slugs, Somersloops, Mercer Spheres, hard drives, plants and creatures.';

	private const string COLLECTED = ' Load your save to mark what you have already collected.';

	/** Slug => resource name, for the resources shown as nodes. */
	private const array NODE_RESOURCES = [
		'iron-ore' => 'Iron Ore',
		'copper-ore' => 'Copper Ore',
		'limestone' => 'Limestone',
		'coal' => 'Coal',
		'caterium-ore' => 'Caterium Ore',
		'raw-quartz' => 'Raw Quartz',
		'sulfur' => 'Sulfur',
		'bauxite' => 'Bauxite',
		'uranium' => 'Uranium',
		'sam' => 'SAM',
	];

	/**
	 * Slug => [heading, description with %s for the version name].
	 * @return array<string, array{string, string}>
	 */
	public static function topics(): array
	{
		$topics = [
			'somersloops' => ['Somersloop locations', 'Every Somersloop in Satisfactory (%s) on an interactive map.' . self::COLLECTED],
			'mercer-spheres' => ['Mercer Sphere locations', 'Every Mercer Sphere in Satisfactory (%s) on an interactive map.' . self::COLLECTED],
			'power-slugs' => ['Power slug locations', 'Every blue, yellow and purple power slug in Satisfactory (%s) on an interactive map.' . self::COLLECTED],
			'hard-drives' => ['Hard drive locations', 'Every crash site with a hard drive in Satisfactory (%s) on an interactive map.' . self::COLLECTED],
			'plants' => ['Plant locations', 'Every Paleberry, Beryl Nut and Bacon Agaric in Satisfactory (%s) on an interactive map.' . self::COLLECTED],
			'creatures' => ['Creature locations', 'Where every creature spawns in Satisfactory (%s), from hogs and stingers to the harmless ones, on an interactive map.'],
		];
		foreach (self::NODE_RESOURCES as $slug => $name) {
			$topics[$slug] = [$name . ' nodes', 'Every ' . $name . ' node in Satisfactory (%s) and its purity, on an interactive map.'];
		}

		return $topics + [
			'crude-oil' => ['Crude Oil nodes and wells', 'Every Crude Oil node and resource well in Satisfactory (%s) and its purity, on an interactive map.'],
			'water' => ['Water wells', 'Every Water resource well in Satisfactory (%s) and its purity, on an interactive map.'],
			'nitrogen-gas' => ['Nitrogen Gas wells', 'Every Nitrogen Gas resource well in Satisfactory (%s) and its purity, on an interactive map.'],
			'geysers' => ['Geyser locations', 'Every geyser in Satisfactory (%s) and its purity, on an interactive map.'],
		];
	}

}
