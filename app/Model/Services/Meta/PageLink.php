<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\Meta;

final class PageLink
{

	public function __construct(
		public readonly string $href,
		public readonly string $label,
	)
	{
	}

	/** @return array{href: string, label: string} */
	public function toArray(): array
	{
		return ['href' => $this->href, 'label' => $this->label];
	}

}
