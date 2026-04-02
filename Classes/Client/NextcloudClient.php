<?php

declare(strict_types=1);

namespace Codeblick\NextcloudFal\Client;

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

class NextcloudClient
{
    private Client $client;
    private string $webdavBasePath;
    private ?LoggerInterface $logger;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $username,
        private readonly string $password,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger;

        if ($this->baseUrl === '' || $this->username === '') {
            throw new \InvalidArgumentException('NextCloud base URL and username must not be empty.');
        }

        $parsedUrl = parse_url(rtrim($this->baseUrl, '/'));
        $scheme = $parsedUrl['scheme'] ?? 'https';
        $host = $parsedUrl['host'] ?? '';
        $port = isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '';
        $basePath = rtrim($parsedUrl['path'] ?? '', '/');

        if ($host === '') {
            throw new \InvalidArgumentException(sprintf('Invalid NextCloud URL: "%s"', $this->baseUrl));
        }

        $this->webdavBasePath = $basePath . '/remote.php/dav/files/' . rawurlencode($this->username);

        $this->client = new Client([
            'base_uri' => $scheme . '://' . $host . $port,
            'auth' => [$this->username, $this->password],
            'headers' => [
                'OCS-APIREQUEST' => 'true',
                'Accept-Encoding' => 'gzip, deflate',
            ],
            'decode_content' => true,
            'http_errors' => false,
            'timeout' => 300,
            'connect_timeout' => 10,
        ]);
    }

    /**
     * @return array{success: bool, status: int, message: string, webdavPath: string}
     */
    public function testConnection(): array
    {
        $webdavPath = $this->buildWebdavPath('/');

        try {
            $response = $this->client->request('PROPFIND', $webdavPath, [
                'headers' => [
                    'Depth' => '0',
                    'Content-Type' => 'application/xml',
                ],
                'body' => '<?xml version="1.0" encoding="UTF-8"?>
                    <d:propfind xmlns:d="DAV:">
                        <d:prop><d:resourcetype/></d:prop>
                    </d:propfind>',
            ]);

            $status = $response->getStatusCode();
            $body = $response->getBody()->getContents();

            return [
                'success' => $status === 207,
                'status' => $status,
                'message' => match (true) {
                    $status === 207 => 'Connection successful',
                    $status === 401 => 'Authentication failed – check username/password',
                    $status === 404 => 'WebDAV endpoint not found – check base URL',
                    $status === 403 => 'Access forbidden – check permissions',
                    default => sprintf('Unexpected HTTP %d response', $status),
                },
                'webdavPath' => $webdavPath,
                'responseBody' => substr($body, 0, 2000),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => 0,
                'message' => 'Connection error: ' . $e->getMessage(),
                'webdavPath' => $webdavPath,
                'responseBody' => '',
            ];
        }
    }

    public function propfind(string $path, int $depth = 1): array
    {
        $webdavPath = $this->buildWebdavPath($path);

        $response = $this->client->request('PROPFIND', $webdavPath, [
            'headers' => [
                'Depth' => (string)$depth,
                'Content-Type' => 'application/xml',
            ],
            'body' => '<?xml version="1.0" encoding="UTF-8"?>
                <d:propfind xmlns:d="DAV:">
                    <d:prop>
                        <d:resourcetype/>
                        <d:getcontentlength/>
                        <d:getlastmodified/>
                        <d:creationdate/>
                        <d:getcontenttype/>
                        <d:getetag/>
                    </d:prop>
                </d:propfind>',
        ]);

        if ($response->getStatusCode() !== 207) {
            return [];
        }

        return $this->parseMultiStatusResponse($response);
    }

    public function get(string $path): string
    {
        $response = $this->client->request('GET', $this->buildWebdavPath($path));

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(
                sprintf('Failed to get file "%s": HTTP %d', $path, $response->getStatusCode())
            );
        }

        return $response->getBody()->getContents();
    }

    /**
     * Download a file directly to a local path using streaming (memory-efficient).
     */
    public function downloadToFile(string $remotePath, string $localPath): bool
    {
        $response = $this->client->request('GET', $this->buildWebdavPath($remotePath), [
            'sink' => $localPath,
        ]);

        return $response->getStatusCode() === 200;
    }

    public function put(string $path, string $contents): bool
    {
        $response = $this->client->request('PUT', $this->buildWebdavPath($path), [
            'body' => $contents,
        ]);

        return in_array($response->getStatusCode(), [200, 201, 204], true);
    }

    public function putFile(string $path, string $localFilePath): bool
    {
        $stream = fopen($localFilePath, 'r');
        if ($stream === false) {
            throw new \RuntimeException(sprintf('Cannot open local file "%s" for reading', $localFilePath));
        }

        try {
            $response = $this->client->request('PUT', $this->buildWebdavPath($path), [
                'body' => $stream,
            ]);

            $status = $response->getStatusCode();
            if (!in_array($status, [200, 201, 204], true)) {
                throw new \RuntimeException(
                    sprintf('Failed to upload file "%s" to Nextcloud: HTTP %d', $path, $status)
                );
            }

            return true;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function delete(string $path): bool
    {
        $response = $this->client->request('DELETE', $this->buildWebdavPath($path));

        return in_array($response->getStatusCode(), [200, 204], true);
    }

    public function mkcol(string $path): bool
    {
        $response = $this->client->request('MKCOL', $this->buildWebdavPath($path));

        return in_array($response->getStatusCode(), [201], true);
    }

    public function move(string $sourcePath, string $destinationPath, bool $overwrite = false): bool
    {
        $response = $this->client->request('MOVE', $this->buildWebdavPath($sourcePath), [
            'headers' => [
                'Destination' => $this->buildFullWebdavUrl($destinationPath),
                'Overwrite' => $overwrite ? 'T' : 'F',
            ],
        ]);

        return in_array($response->getStatusCode(), [200, 201, 204], true);
    }

    public function copy(string $sourcePath, string $destinationPath, bool $overwrite = false): bool
    {
        $response = $this->client->request('COPY', $this->buildWebdavPath($sourcePath), [
            'headers' => [
                'Destination' => $this->buildFullWebdavUrl($destinationPath),
                'Overwrite' => $overwrite ? 'T' : 'F',
            ],
        ]);

        return in_array($response->getStatusCode(), [200, 201, 204], true);
    }

    /**
     * Check existence using a lightweight HEAD request instead of PROPFIND.
     */
    public function exists(string $path): bool
    {
        $response = $this->client->request('HEAD', $this->buildWebdavPath($path), [
            'timeout' => 10,
        ]);

        // 200 = file exists, 301 = collection redirect (folder exists)
        return in_array($response->getStatusCode(), [200, 301], true);
    }

    private function buildWebdavPath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $segments = explode('/', $path);
        $encoded = array_map('rawurlencode', $segments);
        return $this->webdavBasePath . implode('/', $encoded);
    }

    private function buildFullWebdavUrl(string $path): string
    {
        $parsedUrl = parse_url(rtrim($this->baseUrl, '/'));
        $scheme = $parsedUrl['scheme'] ?? 'https';
        $host = $parsedUrl['host'] ?? '';
        $port = isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '';

        return $scheme . '://' . $host . $port . $this->buildWebdavPath($path);
    }

    private function parseMultiStatusResponse(ResponseInterface $response): array
    {
        $body = $response->getBody()->getContents();
        $xml = @simplexml_load_string($body);
        if ($xml === false) {
            return [];
        }

        $entries = [];

        foreach ($xml->children('DAV:') as $responseNode) {
            if ($responseNode->getName() !== 'response') {
                continue;
            }

            $href = (string)$responseNode->children('DAV:')->href;
            if ($href === '') {
                continue;
            }

            $path = $this->webdavPathToIdentifier($href);

            // Find the propstat with status 200
            $foundProp = null;
            foreach ($responseNode->children('DAV:') as $child) {
                if ($child->getName() !== 'propstat') {
                    continue;
                }
                $status = (string)$child->children('DAV:')->status;
                if (str_contains($status, '200')) {
                    $foundProp = $child->children('DAV:')->prop;
                    break;
                }
            }

            if ($foundProp === null) {
                continue;
            }

            $davProps = $foundProp->children('DAV:');

            $isDirectory = false;
            if (isset($davProps->resourcetype)) {
                foreach ($davProps->resourcetype->children('DAV:') as $rtChild) {
                    if ($rtChild->getName() === 'collection') {
                        $isDirectory = true;
                        break;
                    }
                }
            }

            $lastModified = isset($davProps->getlastmodified) ? (string)$davProps->getlastmodified : '';
            $contentLength = isset($davProps->getcontentlength) ? (string)$davProps->getcontentlength : '';
            $contentType = isset($davProps->getcontenttype) ? (string)$davProps->getcontenttype : '';
            $creationDate = isset($davProps->creationdate) ? (string)$davProps->creationdate : '';

            $entries[] = [
                'path' => $path,
                'is_directory' => $isDirectory,
                'size' => $contentLength !== '' ? (int)$contentLength : 0,
                'mtime' => $lastModified !== '' ? strtotime($lastModified) : 0,
                'ctime' => $creationDate !== '' ? strtotime($creationDate) : 0,
                'mimetype' => $contentType,
            ];
        }

        return $entries;
    }

    private function webdavPathToIdentifier(string $href): string
    {
        $decoded = rawurldecode($href);
        $basePath = rawurldecode($this->webdavBasePath);

        if (str_starts_with($decoded, $basePath)) {
            $identifier = substr($decoded, strlen($basePath));
        } else {
            $identifier = $decoded;
        }

        if ($identifier === '' || $identifier === false) {
            return '/';
        }

        if (!str_starts_with($identifier, '/')) {
            $identifier = '/' . $identifier;
        }

        return $identifier;
    }
}
