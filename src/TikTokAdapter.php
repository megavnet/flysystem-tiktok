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
use GuzzleHttp\Psr7;
use InvalidArgumentException;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToListContents;
use Symfony\Component\Cache\Adapter\FilesystemAdapter as CacheAdapter;
use Symfony\Contracts\Cache\ItemInterface;

class TikTokAdapter implements ChecksumProvider, FilesystemAdapter
{
    protected array $config;

    protected MimeTypeDetector $mimeTypeDetector;

    protected GuzzleClient $client;

    protected CacheAdapter $cache;

    public function __construct(
        array $config,
        ?MimeTypeDetector $mimeTypeDetector = null
    ) {
        $this->config = $config;
        $this->cache = new CacheAdapter();
        if (!isset($this->config['access_token'])) {
            throw new InvalidArgumentException('Access token is required');
        }
        if (!isset($this->config['base_uri'])) {
            $this->config['base_uri'] = 'https://business-api.tiktok.com/open_api/v1.3/';
        }

        $this->client = $this->getClient();
        if (!isset($this->config['advertiser_id'])) {
            $advertisers = $this->getAdvertisers();
            if (count($advertisers) > 0) {
                $this->config['advertiser_id'] = $advertisers[0]['advertiser_id'];
            } else {
                throw new InvalidArgumentException('Not found any advertisers');
            }
        }

        $this->mimeTypeDetector = $mimeTypeDetector ?: new FinfoMimeTypeDetector;
    }

    public function getClient(): GuzzleClient
    {
        $client = new GuzzleClient([
            'base_uri' => $this->config['base_uri'],
            'headers' => [
                'Access-Token' => $this->config['access_token'],
                'Content-Type' => 'application/json',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            ],
        ]);

        return $client;
    }

    public function fileExists(string $path): bool
    {
        throw UnableToCheckExistence::forLocation($path, new Exception('Adapter does not support file existence check.'));
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
    public function put($file_name, $contents)
    {
        try {
            $mimeType = $this->mimeTypeDetector->detectMimeTypeFromPath($file_name) ?: $this->mimeTypeDetector->detectMimeTypeFromBuffer($contents);
            if ($mimeType === 'image/jpeg' || $mimeType === 'image/png') {
                $response = $this->uploadImage($file_name, $contents);
                return $response;
            } elseif ($mimeType === 'video/mp4') {
                $response = $this->uploadVideo($file_name, $contents);
                return $response;
            } else {
                throw UnableToWriteFile::atLocation($file_name, 'Unsupported file type: ' . $mimeType);
            }
        } catch (Exception $exception) {
            throw UnableToWriteFile::atLocation($file_name, $exception->getMessage(), $exception);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function write(string $file_name, string $contents, Config $config): void
    {
        throw UnableToWriteFile::atLocation($file_name, 'Adapter does not support file writing.');
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
    protected function uploadImage(string $file_path, ?string $contents, ?array $options = []): array
    {
        $multipart = [
            [
                'name'     => 'advertiser_id',
                'contents' => $this->config['advertiser_id']
            ],
            [
                'name'     => 'image_file',
                'contents' => $contents ?? Psr7\Utils::tryFopen($file_path, 'r')
            ],
            [
                'name'     => 'upload_type',
                'contents' => 'UPLOAD_BY_FILE',
            ],
            [
                'name'     => 'image_signature',
                'contents' => md5($contents),
            ]
        ];

        if (isset($options['include_file_name'])) {
            $multipart[] = [
                'name'     => 'file_name',
                'contents' => basename($file_path),
            ];
        }

        $response = $this->client->request('POST', 'file/image/ad/upload/', [
            'multipart' => $multipart,
        ]);
        $body = json_decode($response->getBody(), true);
        if (isset($body['data']) && !empty($body['data'])) {
            return $body['data'];
        } else {
            $message = $body['message'] ?? 'Unknown error';
            throw new TikTokRequestException('Failed to upload image: ' . $message, $body);
        }
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
    protected function uploadVideo(string $file_path, ?string $contents, ?array $options = []): array
    {
        $contents = $contents ?: Psr7\Utils::tryFopen($file_path, 'r');
        $multipart = [
            [
                'name'     => 'advertiser_id',
                'contents' => $this->config['advertiser_id']
            ],
            [
                'name'     => 'video_file',
                'contents' => $contents,
                'filename' => basename($file_path),
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
                'contents' => basename($file_path),
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

    protected function getAdvertisers()
    {
        if (!isset($this->config['app_id']) || !isset($this->config['secret'])) {
            throw new InvalidArgumentException('App ID and secret are required for get advertisers');
        }

        $cacheKey = 'tiktok_advertisers_' . $this->config['app_id'] . '_' . $this->config['secret'];

        $value = $this->cache->get($cacheKey, function (ItemInterface $item): array {
            $response = $this->client->request('GET', 'oauth2/advertiser/get/', [
                'query' => [
                    'app_id' => $this->config['app_id'],
                    'secret' => $this->config['secret'],
                ]
            ]);
            $body = json_decode($response->getBody(), true);
            $item->expiresAfter(60 * 60 * 24);
            return $body['data']['list'] ?? [];
        });
        return $value;
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
