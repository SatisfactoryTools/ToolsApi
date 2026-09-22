<?php declare(strict_types = 1);

namespace greeny\SatisfactoryTools\Api\Modules\V1Module;

use Apitte\Core\Annotation\Controller\Method;
use Apitte\Core\Annotation\Controller\Path;
use Apitte\Core\Annotation\Controller\RequestParameter;
use Apitte\Core\Http\ApiRequest;
use Apitte\Core\Http\ApiResponse;
use Apitte\Core\Schema\EndpointParameter;
use DateTimeImmutable;
use greeny\SatisfactoryTools\Api\Model\Entities\HelpArticle;
use greeny\SatisfactoryTools\Api\Model\Entities\HelpCategory;
use greeny\SatisfactoryTools\Api\Model\Entities\HelpImage;
use greeny\SatisfactoryTools\Api\Model\Entities\User;
use greeny\SatisfactoryTools\Api\Model\Repositories\HelpArticleRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\HelpCategoryRepository;
use greeny\SatisfactoryTools\Api\Model\Repositories\HelpImageRepository;
use greeny\SatisfactoryTools\Api\Model\Services\HelpImageStorage;
use greeny\SatisfactoryTools\Api\Model\Services\HelpSnapshotService;
use greeny\SatisfactoryTools\Api\Schema\Responses\HelpArticleResponse;
use greeny\SatisfactoryTools\Api\Schema\Responses\HelpCategoryResponse;
use greeny\SatisfactoryTools\Api\Schema\Responses\HelpImageResponse;
use Nette\Http\IResponse;
use Ramsey\Uuid\Uuid;

/**
 * Help articles (tutorials). Readers normally never reach this controller - they load the
 * static snapshot under data/help/ that HelpSnapshotService writes after every change;
 * the two public endpoints here serve the same JSON as a fallback. Everything under
 * /help/editor requires an account with the helpEditor flag.
 */
#[Path('/help')]
class HelpController extends BaseV1Controller
{

	private const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

	/** Dot-separated, e.g. planner.overclocking - what a <help-button> in the app names. */
	private const TOPIC_PATTERN = '/^[a-z0-9]+(?:[.-][a-z0-9]+)*$/';

	public function __construct(
		private readonly HelpArticleRepository $articleRepository,
		private readonly HelpCategoryRepository $categoryRepository,
		private readonly HelpImageRepository $imageRepository,
		private readonly HelpSnapshotService $snapshotService,
		private readonly HelpImageStorage $imageStorage,
	)
	{
	}

	#[Path('/')]
	#[Method('GET')]
	public function manifest(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		return $response->writeJsonBody($this->snapshotService->manifest($this->articleRepository->getPublished()));
	}

	#[Path('/article/{slug}')]
	#[Method('GET')]
	#[RequestParameter(name: 'slug', type: 'string', in: EndpointParameter::IN_PATH)]
	public function article(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		$article = $this->articleRepository->getBySlug((string) $request->getParameter('slug'));
		if ($article === null || !$article->published) {
			return $this->notFound($response, 'Article not found');
		}

		return $response->writeJsonBody($this->snapshotService->articleFile($article));
	}

	#[Path('/editor/articles')]
	#[Method('GET')]
	public function listArticles(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		if ($this->requireEditor($request) === null) {
			return $this->forbidden($response);
		}

		return $response->writeJsonBody(array_map(
			static fn (HelpArticle $article): array => HelpArticleResponse::fromEntity($article, includeBody: false)->toArray(),
			$this->articleRepository->getAll(),
		));
	}

	#[Path('/editor/articles/{uuid}')]
	#[Method('GET')]
	#[RequestParameter(name: 'uuid', type: 'uuid', in: EndpointParameter::IN_PATH)]
	public function getArticle(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		if ($this->requireEditor($request) === null) {
			return $this->forbidden($response);
		}

		$article = $this->articleRepository->getByUuid((string) $this->getUuidParameter($request));
		if ($article === null) {
			return $this->notFound($response, 'Article not found');
		}

		return $response->writeJsonBody(HelpArticleResponse::fromEntity($article)->toArray());
	}

	#[Path('/editor/articles')]
	#[Method('POST')]
	public function createArticle(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		$user = $this->requireEditor($request);
		if ($user === null) {
			return $this->forbidden($response);
		}

		$body = $this->parseBody($request);
		$article = new HelpArticle();
		$article->uuid = Uuid::uuid4();
		$article->createdAt = new DateTimeImmutable();
		$article->slug = '';
		$article->title = '';

		$error = $this->fill($article, $body, requireSlug: true);
		if ($error !== null) {
			return $error($response);
		}

		$article->author = $user;
		$article->updatedAt = new DateTimeImmutable();
		$this->articleRepository->save($article);
		$this->snapshotService->rebuild();

		return $response->withStatus(IResponse::S201_Created)
			->writeJsonBody(HelpArticleResponse::fromEntity($article)->toArray());
	}

	#[Path('/editor/articles/{uuid}')]
	#[Method('PUT')]
	#[RequestParameter(name: 'uuid', type: 'uuid', in: EndpointParameter::IN_PATH)]
	public function updateArticle(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		$user = $this->requireEditor($request);
		if ($user === null) {
			return $this->forbidden($response);
		}

		$article = $this->articleRepository->getByUuid((string) $this->getUuidParameter($request));
		if ($article === null) {
			return $this->notFound($response, 'Article not found');
		}

		$error = $this->fill($article, $this->parseBody($request), requireSlug: false);
		if ($error !== null) {
			return $error($response);
		}

		$article->author = $user;
		$article->updatedAt = new DateTimeImmutable();
		$this->articleRepository->save($article);
		$this->snapshotService->rebuild();

		return $response->writeJsonBody(HelpArticleResponse::fromEntity($article)->toArray());
	}

	#[Path('/editor/articles/{uuid}')]
	#[Method('DELETE')]
	#[RequestParameter(name: 'uuid', type: 'uuid', in: EndpointParameter::IN_PATH)]
	public function deleteArticle(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		if ($this->requireEditor($request) === null) {
			return $this->forbidden($response);
		}

		$article = $this->articleRepository->getByUuid((string) $this->getUuidParameter($request));
		if ($article === null) {
			return $this->notFound($response, 'Article not found');
		}

		$this->articleRepository->delete($article);
		$this->snapshotService->rebuild();

		return $response->withStatus(IResponse::S204_NoContent);
	}

	#[Path('/editor/categories')]
	#[Method('GET')]
	public function listCategories(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		if ($this->requireEditor($request) === null) {
			return $this->forbidden($response);
		}

		return $response->writeJsonBody(array_map(
			static fn (HelpCategory $category): array => HelpCategoryResponse::fromEntity($category)->toArray(),
			$this->categoryRepository->getAll(),
		));
	}

	#[Path('/editor/categories')]
	#[Method('POST')]
	public function createCategory(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		if ($this->requireEditor($request) === null) {
			return $this->forbidden($response);
		}

		$body = $this->parseBody($request);
		$category = new HelpCategory();
		$category->uuid = Uuid::uuid4();
		$category->slug = '';
		$category->name = '';

		$error = $this->fillCategory($category, $body, requireSlug: true);
		if ($error !== null) {
			return $error($response);
		}

		$this->categoryRepository->save($category);
		$this->snapshotService->rebuild();

		return $response->withStatus(IResponse::S201_Created)
			->writeJsonBody(HelpCategoryResponse::fromEntity($category)->toArray());
	}

	#[Path('/editor/categories/{uuid}')]
	#[Method('PUT')]
	#[RequestParameter(name: 'uuid', type: 'uuid', in: EndpointParameter::IN_PATH)]
	public function updateCategory(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		if ($this->requireEditor($request) === null) {
			return $this->forbidden($response);
		}

		$category = $this->categoryRepository->getByUuid((string) $this->getUuidParameter($request));
		if ($category === null) {
			return $this->notFound($response, 'Category not found');
		}

		$error = $this->fillCategory($category, $this->parseBody($request), requireSlug: false);
		if ($error !== null) {
			return $error($response);
		}

		$this->categoryRepository->save($category);
		$this->snapshotService->rebuild();

		return $response->writeJsonBody(HelpCategoryResponse::fromEntity($category)->toArray());
	}

	#[Path('/editor/categories/{uuid}')]
	#[Method('DELETE')]
	#[RequestParameter(name: 'uuid', type: 'uuid', in: EndpointParameter::IN_PATH)]
	public function deleteCategory(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		if ($this->requireEditor($request) === null) {
			return $this->forbidden($response);
		}

		$category = $this->categoryRepository->getByUuid((string) $this->getUuidParameter($request));
		if ($category === null) {
			return $this->notFound($response, 'Category not found');
		}

		// Articles survive their category (the join column is SET NULL) and show up
		// under "Other" until they are moved somewhere else.
		$this->categoryRepository->delete($category);
		$this->snapshotService->rebuild();

		return $response->withStatus(IResponse::S204_NoContent);
	}

	#[Path('/editor/images')]
	#[Method('GET')]
	public function listImages(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		if ($this->requireEditor($request) === null) {
			return $this->forbidden($response);
		}

		return $response->writeJsonBody(array_map(
			static fn (HelpImage $image): array => HelpImageResponse::fromEntity($image)->toArray(),
			$this->imageRepository->getAll(),
		));
	}

	/**
	 * Uploads a screenshot. The body is JSON - {fileName, data} with data base64-encoded
	 * (optionally as a data: URL) - so the editor can post a pasted or dropped file the
	 * same way it posts everything else.
	 */
	#[Path('/editor/images')]
	#[Method('POST')]
	public function uploadImage(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		$user = $this->requireEditor($request);
		if ($user === null) {
			return $this->forbidden($response);
		}

		$body = $this->parseBody($request);
		$encoded = (string) ($body['data'] ?? '');
		// Data URLs arrive as "data:image/png;base64,iVBOR..." - keep only the payload.
		$encoded = preg_replace('/^data:[^,]*,/', '', $encoded) ?? $encoded;
		$bytes = base64_decode($encoded, true);
		if ($bytes === false || $bytes === '') {
			return $this->badRequest($response, 'Field data must be base64-encoded file contents');
		}
		if (strlen($bytes) > HelpImageStorage::MAX_SIZE) {
			return $this->badRequest($response, 'The image is larger than ' . (HelpImageStorage::MAX_SIZE / 1024 / 1024) . ' MB');
		}

		$extension = $this->imageStorage->extensionFor($bytes);
		if ($extension === null) {
			return $this->badRequest($response, 'Only PNG, JPEG, WebP and GIF images are accepted');
		}

		$image = new HelpImage();
		$image->uuid = Uuid::uuid4();
		$image->fileName = trim((string) ($body['fileName'] ?? '')) ?: ('image.' . $extension);
		$image->uploadedAt = new DateTimeImmutable();
		$image->user = $user;
		$image->path = $this->imageStorage->store($image->uuid, $bytes, $extension);

		$this->imageRepository->save($image);

		return $response->withStatus(IResponse::S201_Created)
			->writeJsonBody(HelpImageResponse::fromEntity($image)->toArray());
	}

	#[Path('/editor/images/{uuid}')]
	#[Method('DELETE')]
	#[RequestParameter(name: 'uuid', type: 'uuid', in: EndpointParameter::IN_PATH)]
	public function deleteImage(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		if ($this->requireEditor($request) === null) {
			return $this->forbidden($response);
		}

		$image = $this->imageRepository->getByUuid((string) $this->getUuidParameter($request));
		if ($image === null) {
			return $this->notFound($response, 'Image not found');
		}

		$this->imageStorage->delete($image->path);
		$this->imageRepository->delete($image);

		return $response->withStatus(IResponse::S204_NoContent);
	}

	/** Rebuilds the static snapshot by hand - after editing the database directly, say. */
	#[Path('/editor/publish')]
	#[Method('POST')]
	public function publish(ApiRequest $request, ApiResponse $response): ApiResponse
	{
		if ($this->requireEditor($request) === null) {
			return $this->forbidden($response);
		}

		$this->snapshotService->rebuild();

		return $response->withStatus(IResponse::S204_NoContent);
	}

	/**
	 * Applies a request body to an article. Returns null on success, or a callable that
	 * writes the error response - validation failures are many and each needs its own
	 * message, and this way both create and update report them identically.
	 *
	 * @param array<string, mixed> $body
	 * @return (callable(ApiResponse): ApiResponse)|null
	 */
	private function fill(HelpArticle $article, array $body, bool $requireSlug): ?callable
	{
		if ($requireSlug || array_key_exists('slug', $body)) {
			$slug = strtolower(trim((string) ($body['slug'] ?? '')));
			if (preg_match(self::SLUG_PATTERN, $slug) !== 1 || strlen($slug) > 100) {
				return fn (ApiResponse $response): ApiResponse => $this->badRequest(
					$response,
					'Field slug must be lowercase words separated by dashes',
				);
			}
			$existing = $this->articleRepository->getBySlug($slug);
			if ($existing !== null && $existing !== $article) {
				return fn (ApiResponse $response): ApiResponse => $this->conflict($response, 'Another article already uses this slug');
			}
			$article->slug = $slug;
		}

		if ($requireSlug || array_key_exists('title', $body)) {
			$title = trim((string) ($body['title'] ?? ''));
			if ($title === '' || strlen($title) > 200) {
				return fn (ApiResponse $response): ApiResponse => $this->badRequest($response, 'Field title is required');
			}
			$article->title = $title;
		}

		if (array_key_exists('summary', $body)) {
			$summary = trim((string) $body['summary']);
			if (strlen($summary) > 500) {
				return fn (ApiResponse $response): ApiResponse => $this->badRequest($response, 'Field summary is too long');
			}
			$article->summary = $summary;
		}

		if (array_key_exists('body', $body)) {
			$article->body = (string) $body['body'];
		}

		if (array_key_exists('keywords', $body)) {
			$keywords = $this->stringList($body['keywords']);
			if ($keywords === null) {
				return fn (ApiResponse $response): ApiResponse => $this->badRequest($response, 'Field keywords must be a list of strings');
			}
			$article->keywords = $keywords;
		}

		if (array_key_exists('seeAlso', $body)) {
			$seeAlso = $this->stringList($body['seeAlso']);
			if ($seeAlso === null) {
				return fn (ApiResponse $response): ApiResponse => $this->badRequest($response, 'Field seeAlso must be a list of article slugs');
			}
			// Unknown or unpublished slugs are allowed: an article may point at one that
			// is still being written, and the reader skips whatever is not in the manifest.
			$article->seeAlso = $seeAlso;
		}

		if (array_key_exists('topics', $body)) {
			$topics = $this->topicMap($body['topics']);
			if ($topics === null) {
				return fn (ApiResponse $response): ApiResponse => $this->badRequest(
					$response,
					'Field topics must map topic ids to anchors',
				);
			}
			$claimed = $this->claimedTopic($topics, $article);
			if ($claimed !== null) {
				return fn (ApiResponse $response): ApiResponse => $this->conflict(
					$response,
					sprintf('Topic %s is already answered by another article', $claimed),
				);
			}
			$article->topics = $topics;
		}

		if (array_key_exists('category', $body)) {
			if ($body['category'] === null || $body['category'] === '') {
				$article->category = null;
			} else {
				$category = $this->categoryRepository->getByUuid((string) $body['category']);
				if ($category === null) {
					return fn (ApiResponse $response): ApiResponse => $this->badRequest($response, 'Category not found');
				}
				$article->category = $category;
			}
		}

		if (array_key_exists('position', $body)) {
			$article->position = (int) $body['position'];
		}

		if (array_key_exists('published', $body)) {
			$article->published = (bool) $body['published'];
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $body
	 * @return (callable(ApiResponse): ApiResponse)|null
	 */
	private function fillCategory(HelpCategory $category, array $body, bool $requireSlug): ?callable
	{
		if ($requireSlug || array_key_exists('slug', $body)) {
			$slug = strtolower(trim((string) ($body['slug'] ?? '')));
			if (preg_match(self::SLUG_PATTERN, $slug) !== 1 || strlen($slug) > 100) {
				return fn (ApiResponse $response): ApiResponse => $this->badRequest(
					$response,
					'Field slug must be lowercase words separated by dashes',
				);
			}
			$existing = $this->categoryRepository->getBySlug($slug);
			if ($existing !== null && $existing !== $category) {
				return fn (ApiResponse $response): ApiResponse => $this->conflict($response, 'Another category already uses this slug');
			}
			$category->slug = $slug;
		}

		if ($requireSlug || array_key_exists('name', $body)) {
			$name = trim((string) ($body['name'] ?? ''));
			if ($name === '' || strlen($name) > 200) {
				return fn (ApiResponse $response): ApiResponse => $this->badRequest($response, 'Field name is required');
			}
			$category->name = $name;
		}

		if (array_key_exists('position', $body)) {
			$category->position = (int) $body['position'];
		}

		return null;
	}

	/**
	 * The first topic id already claimed by a different article, or null when they are
	 * all free. A topic resolves to exactly one article, or a question-mark button would
	 * not know where to go.
	 *
	 * @param array<string, string> $topics
	 */
	private function claimedTopic(array $topics, HelpArticle $article): ?string
	{
		foreach ($this->articleRepository->getAll() as $other) {
			if ($other === $article) {
				continue;
			}
			foreach (array_keys($topics) as $topic) {
				if (array_key_exists($topic, $other->topics)) {
					return $topic;
				}
			}
		}

		return null;
	}

	/**
	 * @return string[]|null null when the value is not a list of non-empty strings
	 */
	private function stringList(mixed $value): ?array
	{
		if (!is_array($value)) {
			return null;
		}

		$list = [];
		foreach ($value as $item) {
			if (!is_string($item)) {
				return null;
			}
			$item = trim($item);
			if ($item !== '') {
				$list[] = $item;
			}
		}

		return array_values(array_unique($list));
	}

	/**
	 * @return array<string, string>|null topic id => anchor (without the '#'), or null
	 *   when the value is not a valid topic map
	 */
	private function topicMap(mixed $value): ?array
	{
		if (!is_array($value)) {
			return null;
		}

		$topics = [];
		foreach ($value as $topic => $anchor) {
			if (!is_string($topic) || !is_string($anchor) || preg_match(self::TOPIC_PATTERN, $topic) !== 1) {
				return null;
			}
			$topics[$topic] = ltrim(trim($anchor), '#');
		}

		return $topics;
	}

	/** The signed-in user, but only when they may edit help articles. */
	private function requireEditor(ApiRequest $request): ?User
	{
		/** @var User|null $user */
		$user = $request->getAttribute('user');
		return $user !== null && $user->helpEditor ? $user : null;
	}

	private function badRequest(ApiResponse $response, string $message): ApiResponse
	{
		return $response->withStatus(IResponse::S400_BadRequest)->writeJsonBody(['error' => $message]);
	}

	private function conflict(ApiResponse $response, string $message): ApiResponse
	{
		return $response->withStatus(IResponse::S409_Conflict)->writeJsonBody(['error' => $message]);
	}

	private function notFound(ApiResponse $response, string $message): ApiResponse
	{
		return $response->withStatus(IResponse::S404_NotFound)->writeJsonBody(['error' => $message]);
	}

	/** Deliberately 403 for signed-out users too: the editor is not advertised. */
	private function forbidden(ApiResponse $response): ApiResponse
	{
		return $response->withStatus(IResponse::S403_Forbidden)->writeJsonBody(['error' => 'Forbidden']);
	}

}
