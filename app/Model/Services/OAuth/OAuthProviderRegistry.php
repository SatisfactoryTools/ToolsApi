<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\OAuth;

class OAuthProviderRegistry
{

	/** @var array<string, OAuthProviderInterface> */
	private array $providers = [];

	/** @param iterable<OAuthProviderInterface> $providers */
	public function __construct(iterable $providers)
	{
		foreach ($providers as $provider) {
			$this->providers[$provider->getKey()] = $provider;
		}
	}

	public function has(string $key): bool
	{
		return isset($this->providers[$key]);
	}

	/** @throws OAuthException when the provider key is unknown */
	public function get(string $key): OAuthProviderInterface
	{
		return $this->providers[$key] ?? throw new OAuthException('Unknown OAuth provider: ' . $key);
	}

	/** @return list<string> */
	public function keys(): array
	{
		return array_keys($this->providers);
	}

}
