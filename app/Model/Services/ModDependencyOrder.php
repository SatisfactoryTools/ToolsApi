<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services;

use greeny\SatisfactoryTools\Api\Model\Entities\ModVersion;

/** Later mods overwrite earlier ones, so a mod must come after every mod it needs. */
class ModDependencyOrder
{

	/**
	 * @param ModVersion[] $modVersions in the order the user picked
	 * @return ModVersion[]|string the same versions with dependencies first, or an error when one is missing
	 */
	public function order(array $modVersions): array|string
	{
		$picked = [];
		foreach ($modVersions as $modVersion) {
			$picked[$modVersion->mod->id] = true;
		}

		foreach ($modVersions as $modVersion) {
			foreach ($modVersion->dependencies as $dependency) {
				if (!isset($picked[$dependency->mod->id])) {
					return sprintf('%s needs %s. Add it too.', $modVersion->mod->name, $dependency->mod->name);
				}
			}
		}

		$ordered = [];
		$placed = [];
		$remaining = array_values($modVersions);
		while ($remaining !== []) {
			$next = 0;
			foreach ($remaining as $index => $modVersion) {
				$ready = true;
				foreach ($modVersion->dependencies as $dependency) {
					if (!isset($placed[$dependency->mod->id])) {
						$ready = false;
						break;
					}
				}
				if ($ready) {
					$next = $index;
					break;
				}
			}
			$modVersion = $remaining[$next];
			array_splice($remaining, $next, 1);
			$ordered[] = $modVersion;
			$placed[$modVersion->mod->id] = true;
		}

		return $ordered;
	}

}
