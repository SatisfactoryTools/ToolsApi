<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Request;

use greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Request\Parts\ProductionInput;
use greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Request\Parts\ProductionItem;

class ProductionRequest
{

	public string $gameVersion = '';
	/** @var array<string, int|float> */
	public array $resourceMax = [];
	/** @var array<string, int|float> */
	public array $resourceWeight = [];
	/** @var string[] */
	public array $blockedResources = [];
	/** @var string[] */
	public array $blockedRecipes = [];
	/** @var string[] */
	public array $allowedAlternateRecipes = [];
	/** @var string[] */
	public array $sinkableResources = [];
	/** @var ProductionItem[] */
	public array $production = [];
	/** @var ProductionInput[] */
	public array $input = [];

}
