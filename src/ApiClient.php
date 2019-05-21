<?php

namespace Los\ApiClient;

use GuzzleHttp\Exception as GuzzleException;
use GuzzleHttp\Psr7 as GuzzlePsr7;
use Los\ApiClient\Exception;
use Los\ApiClient\HttpClient\Guzzle6HttpClient;
use Los\ApiClient\HttpClient\HttpClientInterface;
use Los\ApiClient\Resource\ApiResource;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use Ramsey\Uuid\Uuid;
use Throwable;
use Zend\EventManager\EventManagerAwareTrait;

final class ApiClient implements ApiClientInterface
{
    use EventManagerAwareTrait;

    /** @var HttpClientInterface */
    private $httpClient;

    /** @var array  */
    private $defaultOptions = [];

    /** @var RequestInterface */
    private $defaultRequest;

    /** @var bool */
    private $httpErrors = true;

    /** @var ResponseInterface */
    private $response;

    /**
     * Extra information. Provided by the client
     * @var mixed
     */
    private $extra;

    /** @var array  */
    private static $validContentTypes = [
        'application/hal+json',
        'application/json',
        'application/vnd.error+json'
    ];

    private $cache;

    private $defaultTtl;

    /**
     * Client constructor.
     * @param string|UriInterface $rootUrl
     * @param HttpClientInterface|null $httpClient
     * @param array $options
     */
    public function __construct(
        string $rootUrl,
        array $options = [],
        ?CacheInterface $cache = null
    ) {
        $this->httpClient = new Guzzle6HttpClient();

        $this->cache = $cache;

        $this->defaultOptions = $options;

        $this->defaultTtl = $options['default_ttl'] ?? 600;

        $this->defaultRequest = new GuzzlePsr7\Request(
            'GET',
            $rootUrl,
            array_merge_recursive(
                [
                    'User-Agent' => get_class($this),
                    'Accept'     => implode(', ', self::$validContentTypes)
                ],
                $this->defaultOptions['headers'] ?? []
            )
        );
    }

    public function __clone()
    {
        $this->httpClient     = clone $this->httpClient;
        $this->defaultRequest = clone $this->defaultRequest;
    }

    /**
     * @return UriInterface
     */
    public function getRootUrl() : UriInterface
    {
        return $this->defaultRequest->getUri();
    }

    /**
     * @param string|UriInterface $rootUrl
     * @return ApiClientInterface
     */
    public function withRootUrl($rootUrl) : ApiClientInterface
    {
        $instance = clone $this;

        $instance->defaultRequest = $instance->defaultRequest->withUri(GuzzlePsr7\uri_for($rootUrl));

        return $instance;
    }

    /**
     * @param string $name
     * @return array|string[]
     */
    public function getHeader(string $name)
    {
        return $this->defaultRequest->getHeader($name);
    }

    /**
     * @param string $name
     * @param string|string[] $value
     * @return ApiClientInterface
     */
    public function withHeader($name, $value) : ApiClientInterface
    {
        $instance = clone $this;
        $instance->defaultRequest = $instance->defaultRequest->withHeader(
            $name,
            $value
        );
        return $instance;
    }

    /**
     * @param string|UriInterface $uri
     * @param array $options
     * @return ApiResource
     * @throws Exception\ClientException
     * @throws Exception\RequestException
     * @throws Exception\ServerException
     * @throws Exception\BadResponseException
     */
    public function get($uri, array $options = [])
    {
        return $this->request('GET', $uri, $options);
    }

    /**
     * @param string $uri
     * @param string $cacheKey
     * @param array $options
     * @param int|null $ttl
     *
     * @return ApiResource
     * @throws Exception\BadResponseException
     * @throws Exception\ClientException
     * @throws Exception\RequestException
     * @throws Exception\ServerException
     * @throws InvalidArgumentException
     */
    public function getCached(string $uri, string $cacheKey, array $options = [], ?int $ttl = null) : ApiResource
    {
        if ($ttl === null) {
            $ttl = $this->defaultTtl;
        }

        if (! $this->cache instanceof CacheInterface) {
            throw new Exception\RuntimeException('No cache defined.');
        }

        if ($this->cache->has($cacheKey) !== false) {
            return ApiResource::fromResponse(new GuzzlePsr7\Response(200, [], $this->cache->get($cacheKey)));
        }

        $response = $this->get($uri, $options);

        $responseArray = $response->toArray();

        if (! $response->isErrorResource() && ! empty($responseArray)) {
            $this->cache->set($cacheKey, json_encode($responseArray), $ttl);
        }

        return $response;
    }

    /**
     * @param string|UriInterface $uri
     * @param array $options
     * @return ApiResource
     * @throws Exception\BadResponseException
     * @throws Exception\ClientException
     * @throws Exception\RequestException
     * @throws Exception\ServerException
     */
    public function post($uri, array $options = [])
    {
        return $this->request('POST', $uri, $options);
    }

    /**
     * @param string|UriInterface $uri
     * @param array $options
     * @return ApiResource
     * @throws Exception\ClientException
     * @throws Exception\RequestException
     * @throws Exception\ServerException
     * @throws Exception\BadResponseException
     */
    public function patch($uri, array $options = [])
    {
        return $this->request('PUT', $uri, $options);
    }

    /**
     * @param string|UriInterface $uri
     * @param array $options
     * @return ApiResource
     * @throws Exception\ClientException
     * @throws Exception\RequestException
     * @throws Exception\ServerException
     * @throws Exception\BadResponseException
     */
    public function put($uri, array $options = [])
    {
        return $this->request('PUT', $uri, $options);
    }

    /**
     * @param string|UriInterface $uri
     * @param array $options
     * @return ApiResource
     * @throws Exception\ClientException
     * @throws Exception\RequestException
     * @throws Exception\ServerException
     * @throws Exception\BadResponseException
     */
    public function delete($uri, array $options = [])
    {
        return $this->request('DELETE', $uri, $options);
    }

    /**
     * @param string $method
     * @param string|UriInterface $uri
     * @param array $options
     * @return ApiResource
     * @throws Exception\RequestException
     * @throws Exception\ClientException
     * @throws Exception\ServerException
     * @throws Exception\BadResponseException
     */
    public function request(
        $method,
        $uri,
        array $options = []
    ) {
        $request = $this->createRequest($method, $uri, array_merge_recursive($this->defaultOptions, $options));

        $this->getEventManager()->trigger('request.pre', $this);

        try {
            $requestTime = microtime(true);

            $response = $this->httpClient->send($request);

            if (isset($options['add_request_time']) && $options['add_request_time'] === true) {
                $responseTime = (float) sprintf('%.2f', (microtime(true) - $requestTime) * 1000);

                $response = $this->addResponseTime($response, $responseTime);
            }
        } catch (GuzzleException\ConnectException $e) {
            $this->getEventManager()->trigger('request.fail', $this, $e);
            throw Exception\RequestException::fromThrowable($e);
        } catch (GuzzleException\ClientException $e) {
            $this->getEventManager()->trigger('request.fail', $this, $e);
            throw Exception\ClientException::fromThrowable($e);
        } catch (GuzzleException\ServerException $e) {
            $this->getEventManager()->trigger('request.fail', $this, $e);
            throw Exception\ServerException::fromThrowable($e);
        } catch (Throwable $e) {
            $this->getEventManager()->trigger('request.fail', $this, $e);
            throw new Exception\RuntimeException($e->getMessage(), 500, $e);
        }

        $this->getEventManager()->trigger('request.post', $this);

        return $this->handleResponse($response, (bool) ($options['raw_response'] ?? false));
    }

    /**
     * @param string $method
     * @param string|UriInterface $uri
     * @param array $options
     * @return RequestInterface|static
     */
    public function createRequest(
        $method,
        $uri,
        array $options = []
    ) {
        /** @var RequestInterface $request */
        $request = clone $this->defaultRequest;
        $request = $request->withMethod($method);
        $request = $request->withUri(
            self::resolveUri($request->getUri(), $uri)
        );
        $request = $this->applyOptions($request, $options);
        return $request;
    }

    /**
     * @param RequestInterface $request
     * @param array $options
     * @return RequestInterface
     */
    private function applyOptions(RequestInterface $request, array $options)
    {
        if (isset($options['query'])) {
            $request = $this->applyQuery($request, $options['query']);
        }

        if (isset($options['headers'])) {
            foreach ($options['headers'] as $name => $value) {
                $request = $request->withHeader($name, $value);
            }
        }

        if (isset($options['body'])) {
            $request = $this->applyBody($request, $options['body']);
        }

        if (isset($options['add_request_id']) && $options['add_request_id'] === true) {
            $request = $this->addRequestId($request);
        }

        if (isset($options['add_request_depth']) && $options['add_request_depth'] === true) {
            $request = $this->incrementRequestDepth($request);
        }

        $this->httpErrors = (bool) ($options['http_errors'] ?? true);

        return $request;
    }

    /**
     * @param RequestInterface $request
     * @param array|string $query
     * @return RequestInterface
     */
    private function applyQuery(RequestInterface $request, $query)
    {
        $uri = $request->getUri();

        if (! is_array($query)) {
            $query = GuzzlePsr7\parse_query($query);
        }

        $newQuery = array_merge(
            GuzzlePsr7\parse_query($uri->getQuery()),
            $query
        );

        return $request->withUri(
            $uri->withQuery(http_build_query($newQuery, null, '&'))
        );
    }

    /**
     * @param RequestInterface $request
     * @param array|string $body
     * @return RequestInterface
     */
    private function applyBody(RequestInterface $request, $body)
    {
        if (is_array($body)) {
            $body = json_encode($body);
            if (! $request->hasHeader('Content-Type')) {
                $request = $request->withHeader(
                    'Content-Type',
                    'application/json'
                );
            }
        }

        return $request->withBody(GuzzlePsr7\stream_for($body));
    }

    /**
     * @param ResponseInterface $response
     * @param bool $rawResponse
     * @return ApiResource|null
     * @throws Exception\BadResponseException
     */
    private function handleResponse(ResponseInterface $response, bool $rawResponse) : ?ApiResource
    {
        $statusCode = $response->getStatusCode();
        $this->response = $response;

        if ($this->httpErrors && ($statusCode < 200 || $statusCode >= 400)) {
            throw Exception\BadResponseException::create($response);
        }

        if ($rawResponse) {
            return null;
        }

        return ApiResource::fromResponse($response);
    }

    /**
     * @param UriInterface $base
     * @param string|UriInterface $rel
     * @return mixed
     */
    private static function resolveUri($base, $rel)
    {
        static $resolver, $castRel;

        if (! $resolver) {
            $resolver = ['GuzzleHttp\Psr7\UriResolver', 'resolve'];
            $castRel = true;
        }

        if ($castRel && ! ($rel instanceof UriInterface)) {
            $rel = new GuzzlePsr7\Uri($rel);
        }

        return $resolver($base, $rel);
    }

    /**
     * @param RequestInterface $request
     * @param string|null $id
     * @return RequestInterface
     */
    public function addRequestId(RequestInterface $request, string $id = null) : RequestInterface
    {
        if (! $request->hasHeader('X-Request-Id')) {
            return clone $request;
        }

        if ($id === null) {
            $id = defined('REQUEST_ID') ? constant('REQUEST_ID') : Uuid::uuid4();
        }

        $request = $request->withoutHeader('X-Request-Id');
        return $request->withHeader('X-Request-Id', $id);
    }

    /**
     * @param ResponseInterface $response
     * @param float $time
     * @return ResponseInterface
     */
    public function addResponseTime(ResponseInterface $response, float $time) : ResponseInterface
    {
        $response = $response->withoutHeader('X-Response-Time');
        return $response->withHeader('X-Response-Time', sprintf('%2.2fms', $time));
    }

    /**
     * @param RequestInterface $request
     * @param string|null $name
     * @return RequestInterface
     */
    public function addRequestName(RequestInterface $request, string $name = null) : RequestInterface
    {
        if (empty($name)) {
            return clone $request;
        }

        $request = $request->withoutHeader('X-Request-Name');
        return $request->withHeader('X-Request-Name', $name);
    }

    /**
     * @param RequestInterface $request
     * @param int $depth
     * @return RequestInterface
     */
    public function addRequestDepth(RequestInterface $request, int $depth = 0) : RequestInterface
    {
        if ($request->hasHeader('X-Request-Depth')) {
            return $this->incrementRequestDepth($request);
        }

        return $request->withHeader('X-Request-Depth', $depth);
    }

    /**
     * @param RequestInterface $request
     * @return RequestInterface
     */
    public function incrementRequestDepth(RequestInterface $request) : RequestInterface
    {
        $depth = 0;

        if ($request->hasHeader('X-Request-Depth')) {
            $depth = $request->getHeader('X-Request-Depth')[0];
            $request = $request->withoutHeader('X-Request-Depth');
        }

        $depth++;

        return $request->withHeader('X-Request-Depth', $depth);
    }

    /**
     * @return mixed $extra
     */
    public function getExtra()
    {
        return $this->extra;
    }

    /**
     * @param mixed $extra
     * @return ApiClientInterface
     */
    public function setExtra($extra) : ApiClientInterface
    {
        $this->extra = $extra;
        return $this;
    }

    /**
     * @return ResponseInterface
     */
    public function response(): ?ResponseInterface
    {
        return $this->response;
    }

    /**
     * @return HttpClientInterface
     */
    public function httpClient(): HttpClientInterface
    {
        return $this->httpClient;
    }

    /**
     * @param HttpClientInterface $httpClient
     * @return ApiClientInterface
     */
    public function withHttpClient(HttpClientInterface $httpClient) : ApiClientInterface
    {
        $instance = clone $this;
        $instance->httpClient = $httpClient;
        return $instance;
    }
}
