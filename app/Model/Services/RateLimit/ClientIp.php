<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\RateLimit;

use Psr\Http\Message\ServerRequestInterface;

class ClientIp
{

	/** IPv6 is cut to its /64: one subscriber usually gets a whole prefix and could rotate through it freely. */
	public static function of(ServerRequestInterface $request): string
	{
		$address = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
		if (!str_contains($address, ':')) {
			return $address;
		}

		$packed = @inet_pton($address); // @ intentional
		if ($packed === false) {
			return $address;
		}

		return bin2hex(substr($packed, 0, 8)) . '::/64';
	}

}
