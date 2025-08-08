<?php

namespace Megavn\FlysystemTikTok;

use Generator;
use League\Flysystem\ChecksumProvider;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\PathPrefixer;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToProvideChecksum;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;
use Exception;
use GrahamCampbell\GuzzleFactory\GuzzleFactory;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToListContents;
use Psr\Cache\InvalidArgumentException as CacheInvalidArgumentException;
use Random\RandomException;
use Symfony\Component\Cache\Adapter\FilesystemAdapter as CacheAdapter;
use Symfony\Contracts\Cache\ItemInterface;

class TikTokAdapter implements ChecksumProvider, FilesystemAdapter
{
    protected array $config;

    protected MimeTypeDetector $mimeTypeDetector;

    protected GuzzleClient $client;

    protected CacheAdapter $cache;

    protected bool $useCookie = false;

    public function __construct(
        array $config,
        ?MimeTypeDetector $mimeTypeDetector = null
    ) {
        $this->config = $config;
        $this->cache = new CacheAdapter();
        if (!isset($this->config['access_token']) && !isset($this->config['cookie'])) {
            throw new InvalidArgumentException('Access token or cookie is required');
        }
        if (!isset($this->config['base_uri'])) {
            if (isset($this->config['access_token'])) {
                $this->config['base_uri'] = 'https://business-api.tiktok.com/open_api/v1.3/';
            } else {
                $this->config['base_uri'] = 'https://ads.tiktok.com/';
            }
        }

        $this->client = $this->getClient();
        if (!isset($this->config['advertiser_id'])) {
            $advertiser_id = $this->getAdvertiserId();
            if ($advertiser_id) {
                $this->config['advertiser_id'] = $advertiser_id;
            } else {
                throw new InvalidArgumentException('Not found any advertisers');
            }
        }

        $this->mimeTypeDetector = $mimeTypeDetector ?: new FinfoMimeTypeDetector;
    }

    public function getClient(): GuzzleClient
    {
        if (isset($this->config['cookie'])) {
            $this->useCookie = true;
            // convert cookie string to array
            $cookies = [];
            $cookieArray = array_filter(array_map('trim', explode(';', $this->config['cookie'])));
            foreach ($cookieArray as $item) {
                $parts = explode('=', $item);
                if (count($parts) === 2) {
                    $cookies[$parts[0]] = $parts[1];
                } else if (count($parts) === 1) {
                    $cookies['sessionid_ss_ads'] = $parts[0];
                }
            }
            if (!isset($cookies['csrftoken'])) {
                throw new TikTokRequestException('The cookie csrftoken is required');
                // $cookies['csrftoken'] = $this->getCsrfToken(\GuzzleHttp\Cookie\CookieJar::fromArray($cookies, 'business.tiktok.com'));
            }
            if (!isset($cookies['sessionid_ss_ads'])) {
                throw new TikTokRequestException('The cookie sessionid_ss_ads is required');
            }
            $jar = \GuzzleHttp\Cookie\CookieJar::fromArray($cookies, 'ads.tiktok.com');
        } else {
            $jar = null;
        }
        $client_options = [
            'base_uri' => $this->config['base_uri'],
            'headers' => [
                // if access_token is set, use it, otherwise use cookie
                'Access-Token' => $this->config['access_token'] ?? '',
                'x-csrftoken' => $cookies['csrftoken'] ?? '',
                // 'Content-Type' => 'application/json',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            ],
            'cookies' => $jar,
        ];
        $client = new GuzzleClient($client_options);

        return $client;
    }

    /**
     * Write the contents of a file.
     *
     * @param  string  $path
     * @param  \Psr\Http\Message\StreamInterface|\Illuminate\Http\File|\Illuminate\Http\UploadedFile|string|resource  $contents
     * @param  mixed  $options
     * @return array
     * @throws GuzzleException
     * @throws Exception
     * @throws UnableToWriteFile
     */
    public function put($file_name, $contents = null, array $options = [])
    {
        try {
            $mimeType = $this->mimeTypeDetector->detectMimeTypeFromPath($file_name) ?: $this->mimeTypeDetector->detectMimeTypeFromBuffer($contents);
            if ($mimeType === 'image/jpeg' || $mimeType === 'image/png') {
                $response = $this->uploadImage($file_name, $contents, $options);
                $url = $response['url'] ?? $response['image_url'];
                $url = $this->transformImageUrl($url);
                return $url;
            } elseif ($mimeType === 'video/mp4') {
                $response = $this->uploadVideo($file_name, $contents, $options);
                $url = $response['preview_url'] ?? '';
                return $url;
            } else {
                throw UnableToWriteFile::atLocation($file_name, 'Unsupported file type: ' . $mimeType);
            }
        } catch (Exception $exception) {
            throw UnableToWriteFile::atLocation($file_name, $exception->getMessage(), $exception);
        }
    }

    public function putMany(array $files, array $options = []): array
    {
        $requests = function () use ($files) {
            foreach ($files as $file_name => $contents) {
                yield function() use ($file_name, $contents) {
                    return $this->uploadImage($file_name, $contents, [], false);
                };
            }
        };
        $responses = [];
        $pool = new \GuzzleHttp\Pool($this->client, $requests(), [
            'concurrency' => $options['concurrency'] ?? 5,
            'fulfilled' => function (Response $response, $index) use (&$responses) {
                // this is delivered each successful response
                $body = json_decode($response->getBody(), true);
                if (isset($body['data']) && !empty($body['data'])) {
                    $url = $body['data']['url'] ?? $body['data']['image_url'];
                    $url = $this->transformImageUrl($url);
                    $responses[$index] = $url;
                } else {
                    $responses[$index] = $body['message'] ?? $body['msg'] ?? 'Unknown error';
                }
                return;
            },
            'rejected' => function (RequestException $reason, $index) use (&$responses) {
                // this is delivered each failed request
                $responses[$index] = $reason->getMessage();
                return;
            },
        ]);
        // Initiate the transfers and create a promise
        $promise = $pool->promise();
        // Force the pool of requests to complete.
        $promise->wait();

        return $responses;
    }

    protected function uploadImage(string $file_name, string $contents, ?array $options = [], bool $wait = true): array|PromiseInterface
    {
        if ($this->useCookie) {
            $promise = $this->uploadImageUseCookie($file_name, $contents, $options);
        } else {
            $promise = $this->uploadImageUseAccessToken($file_name, $contents, $options);
        }
        if (!$wait) {
            return $promise;
        }
        $response = $promise->wait();
        $body = json_decode($response->getBody(), true);
        if (isset($body['data']) && !empty($body['data'])) {
            return $body['data'];
        } else {
            $message = $body['message'] ?? 'Unknown error';
            throw new TikTokRequestException('Failed to upload image: ' . $message, $body);
        }
    }

    protected function uploadImageUseCookie(string $file_name, string $contents, ?array $options = []): PromiseInterface
    {
        $multipart = [
            [
                'name'     => 'Filedata',
                'contents' => $contents,
                'filename' => basename($file_name),
            ],
        ];

        $promise = $this->client->requestAsync('POST', 'https://ads.tiktok.com/mi/api/v2/i18n/material/image/upload/', [
            'query' => [
                'aadvid' => $this->config['advertiser_id'],
                'Content-Type' => 'multipart/form-data',
            ],
            'multipart' => $multipart,
        ]);
        return $promise;
    }

    protected function uploadImageUseAccessToken(string $file_name, string $contents, ?array $options = []): PromiseInterface
    {
        $multipart = [
            [
                'name'     => 'advertiser_id',
                'contents' => $this->config['advertiser_id']
            ],
            [
                'name'     => 'image_file',
                'contents' => $contents,
                'filename' => basename($file_name),
            ],
            [
                'name'     => 'upload_type',
                'contents' => 'UPLOAD_BY_FILE',
            ],
            [
                'name'     => 'image_signature',
                // $contents maybe a stream, so we need to get md5 from stream
                'contents' => md5($contents),
            ]
        ];

        if (isset($options['include_file_name'])) {
            $multipart[] = [
                'name'     => 'file_name',
                'contents' => basename($file_name),
            ];
        }

        $promise = $this->client->requestAsync('POST', 'file/image/ad/upload/', [
            'multipart' => $multipart,
        ]);
        return $promise;
    }

    /**
     *
     * @param string $file_name
     * @param string $contents
     * @param array $options
     * @return array
     * @throws GuzzleException
     * @throws Exception
     */
    protected function uploadVideo(string $file_name, string $contents, ?array $options = []): array
    {
        if ($this->useCookie) {
            throw new TikTokRequestException('Upload video with cookie is not supported');
        }
        $multipart = [
            [
                'name'     => 'advertiser_id',
                'contents' => $this->config['advertiser_id']
            ],
            [
                'name'     => 'video_file',
                'contents' => $contents,
                'filename' => basename($file_name),
            ],
            [
                'name'     => 'upload_type',
                'contents' => 'UPLOAD_BY_FILE',
            ],
            [
                'name'     => 'video_signature',
                'contents' => md5($contents),
            ],
            [
                'name'     => 'is_third_party',
                'contents' => ($options['is_third_party'] ?? false) ? 'true' : 'false',
            ],
            [
                'name'     => 'flaw_detect',
                'contents' => ($options['flaw_detect'] ?? false) ? 'true' : 'false',
            ],
            [
                'name'     => 'auto_fix_enabled',
                'contents' => ($options['auto_fix_enabled'] ?? false) ? 'true' : 'false',
            ],
            [
                'name'     => 'auto_bind_enabled',
                'contents' => ($options['auto_bind_enabled'] ?? false) ? 'true' : 'false',
            ]
        ];

        if (isset($options['include_file_name'])) {
            $multipart[] = [
                'name'     => 'file_name',
                'contents' => basename($file_name),
            ];
        }

        $response = $this->client->request('POST', 'file/video/ad/upload/', [
            'multipart' => $multipart,
        ]);
        $body = json_decode($response->getBody(), true);
        if (isset($body['data']) && !empty($body['data'])) {
            $video_id = $body['data'][0]['video_id'];
            sleep(1);
            $video_info = $this->getVideoInfo($video_id);
            return $video_info;
        } else {
            $message = $body['message'] ?? 'Unknown error';
            throw new TikTokRequestException('Failed to upload video: ' . $message, $body);
        }
    }

    public function getVideoInfo(string $video_id): array
    {
        $response = $this->client->request('GET', 'file/video/ad/info/', [
            'query' => [
                'advertiser_id' => $this->config['advertiser_id'],
                'video_ids' => '["' . $video_id . '"]',
            ]
        ]);
        $body = json_decode($response->getBody(), true);
        if (isset($body['data'], $body['data']['list']) && !empty($body['data']['list'])) {
            return $body['data']['list'][0];
        } else {
            $message = $body['message'] ?? 'Unknown error';
            throw new TikTokRequestException('Failed to get video info: ' . $message, $body);
        }
    }

    protected function getAdvertiserId(): string
    {
        if (!$this->useCookie && (!isset($this->config['app_id']) || !isset($this->config['app_secret']))) {
            throw new InvalidArgumentException('App ID and secret are required for get advertisers');
        }

        $cacheKey = $this->useCookie ? 'tiktok_advertisers_cookie_' . md5($this->config['cookie']) : 'tiktok_advertiser_' . $this->config['app_id'] . '_' . $this->config['app_secret'];

        $value = $this->cache->get($cacheKey, function (ItemInterface $item): string {
            if ($this->useCookie) {
                $response = $this->client->request('GET', 'https://ads.tiktok.com/api/v4/i18n/account/permission/detail/');
                $body = json_decode($response->getBody(), true);
                $data = $body['data']['account']['id'] ?? '';
            } else {
                $response = $this->client->request('GET', 'oauth2/advertiser/get/', [
                    'query' => [
                        'app_id' => $this->config['app_id'],
                        'secret' => $this->config['app_secret'],
                    ]
                ]);
                $body = json_decode($response->getBody(), true);
                $data = $body['data']['list'][0]['advertiser_id'] ?? '';
            }
            $item->expiresAfter(60 * 60 * 24);
            return $data;
        });
        return $value;
    }

    /**
     * Not working, need to fix
     * @param mixed $jar
     * @return string
     * @throws *2dcb3860
     * @throws CacheInvalidArgumentException
     * @throws RandomException
     */
    protected function getCsrfToken($jar): string
    {
        $value = $this->cache->get('tiktok_csrf_token', function (ItemInterface $item) use ($jar): string {
            $client = new GuzzleClient([
                'cookies' => $jar,
                'headers' => [
                    'Referer' => 'https://ads.tiktok.com',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                ],
            ]);
            $response = $client->request('GET', 'https://business.tiktok.com/api/bff/v3/bm/setting/csrf-token');
            $body = json_decode($response->getBody(), true);
            $item->expiresAfter(60 * 60 * 24);
            return $body['data']['csrfToken'] ?? '';
        });
        return $value;
    }

    function transformImageUrl($url) {
        return preg_replace_callback(
            '#^https://(p\d+)-ad-site-sign-(\w+)\.ibyteimg\.com/([^/]+)/([^~]+)~[^?]+\?.*$#',
            function($matches) {
                $subdomain = "{$matches[1]}-ad-{$matches[2]}";
                $bucket = $matches[3];
                $object = $matches[4];
                return "https://{$subdomain}.ibyteimg.com/obj/{$bucket}/{$object}";
            },
            $url
        );
    }

    /**
     * {@inheritDoc}
     */
    public function write(string $file_name, string $contents, Config $config): void
    {
        throw UnableToWriteFile::atLocation($file_name, 'Adapter does not support file writing.');
    }

    public function fileExists(string $path): bool
    {
        throw UnableToCheckExistence::forLocation($path, new Exception('Adapter does not support file existence check.'));
    }

    /**
     * {@inheritDoc}
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        throw UnableToWriteFile::atLocation($path, 'Adapter does not support file writing.');
    }

    /**
     * {@inheritDoc}
     */
    public function read(string $path): string
    {
        throw UnableToReadFile::fromLocation($path, 'Adapter does not support file reading.');
    }

    /**
     * {@inheritDoc}
     */
    public function readStream(string $path)
    {
        throw UnableToReadFile::fromLocation($path, 'Adapter does not support file reading.');
    }

    public function directoryExists(string $path): bool
    {
        throw UnableToCheckExistence::forLocation($path, new Exception('Adapter does not support directory existence check.'));
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $path): void
    {
        throw UnableToDeleteFile::atLocation($path, 'Adapter does not support file deletion.');
    }

    /**
     * {@inheritDoc}
     */
    public function deleteDirectory(string $path): void
    {
        throw UnableToDeleteDirectory::atLocation($path, 'Adapter does not support directory deletion.');
    }

    /**
     * {@inheritDoc}
     */
    public function createDirectory(string $path, Config $config): void
    {
        throw UnableToCreateDirectory::atLocation($path, 'Adapter does not support directory creation.');
    }

    /**
     * {@inheritDoc}
     */
    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path, 'Adapter does not support visibility controls.');
    }

    /**
     * {@inheritDoc}
     */
    public function visibility(string $path): FileAttributes
    {
        // Noop
        return new FileAttributes($path);
    }

    /**
     * @return iterable<StorageAttributes>
     *
     * @throws FilesystemException
     */
    public function listContents(string $path, bool $deep): iterable
    {
        throw new UnableToListContents('Adapter does not support listing contents.');
    }

    /**
     * {@inheritDoc}
     */
    public function move(string $source, string $destination, Config $config): void
    {
        throw new UnableToMoveFile('Adapter does not support file moving.');
    }

    /**
     * {@inheritDoc}
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        throw new UnableToCopyFile('Adapter does not support file copying.');
    }

    /**
     * {@inheritDoc}
     */
    public function mimeType(string $path): FileAttributes
    {
        return new FileAttributes(
            $path,
            null,
            null,
            null,
            $this->mimeTypeDetector->detectMimeTypeFromPath($path)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function lastModified(string $path): FileAttributes
    {
        throw new UnableToRetrieveMetadata('Adapter does not support file last modified calculation.', $path);
    }

    /**
     * {@inheritDoc}
     */
    public function checksum(string $path, Config $config): string
    {
        throw new UnableToProvideChecksum('Adapter does not support checksum calculation.', $path);
    }

    /**
     * {@inheritDoc}
     */
    public function fileSize(string $path): FileAttributes
    {
        throw new UnableToRetrieveMetadata('Adapter does not support file size calculation.', $path);
    }

    public function getUrl(string $path): string
    {
        throw new UnableToRetrieveMetadata('Adapter does not support file URL retrieval.', $path);
    }
}
