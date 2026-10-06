<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Data;

use greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Data\Parts\Color;
use greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Data\Parts\EquipSlot;
use greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Data\Parts\ItemForm;
use greeny\SatisfactoryTools\Api\Model\Legacy\Schema\Data\Parts\StackSize;

class Item
{

	use ClassName;

	public ?string $icon = null;
	public string $name = '';
	public string $description = '';
	public ?string $abbr = null;
	public bool $canBeTrashed = true;
	public float $energy = 0.0;
	public float $radioactiveDecay = 0.0;
	public ItemForm $form = ItemForm::Solid;
	public string $smallIcon = '';
	public string $bigIcon = '';
	public Color $fluidColor;
	public Color $gasColor;
	public int $sinkPoints = 0;
	public StackSize $stackSize = StackSize::None;

	public bool $consumable = false;
	public int $healthGain = 0;
	public bool $isBiomass = false;
	public bool $isAlien = false;

	public EquipSlot $equipSlot = EquipSlot::None;

	/** @var string[] */
	public array $compatibleWeapons = [];
	/** @var string[] */
	public array $compatibleAmmo = [];
	public int $magazineSize = 0;
	public float $fireRate = 0;
	public int $minShots = 0;
	public int $maxShots = 0;
	public float $reloadTime = 0;

}
