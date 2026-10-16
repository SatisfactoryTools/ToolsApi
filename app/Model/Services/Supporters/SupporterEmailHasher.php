<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Model\Services\Supporters;

class SupporterEmailHasher
{

	public function normalize(string $email): ?string
	{
		$email = mb_strtolower(trim($email));

		return filter_var($email, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) !== false ? $email : null;
	}

	public function hash(string $email): string
	{
		return hash('sha256', mb_strtolower(trim($email)));
	}

}
