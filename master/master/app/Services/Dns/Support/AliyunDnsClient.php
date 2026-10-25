<?php

namespace App\Services\Dns\Support;

use App\Support\RediErrorMessage;

use GuzzleHttp\Client;
use RuntimeException;

class AliyunDnsClient
{
    protected string $accessKeyId;

    protected string $accessKeySecret;

    protected string $endpoint;

    protected Client $http;

    public function __construct(string $accessKeyId, string $accessKeySecret, string $endpoint = 'https://dns.aliyuncs.com')
    {
        $this->accessKeyId = $accessKeyId;
        $this->accessKeySecret = $accessKeySecret;
        $this->endpoint = rtrim($endpoint, '/');
        $this->http = new Client(['timeout' => 30]);
    }

    public function call(string $action, array $params = []): array
    {
        $query = array_merge([
            'Format' => 'JSON',
            'Version' => '2015-01-09',
            'AccessKeyId' => $this->accessKeyId,
            'SignatureMethod' => 'HMAC-SHA1',
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'SignatureVersion' => '1.0',
            'SignatureNonce' => md5(uniqid('', true)),
            'Action' => $action,
        ], $params);

        ksort($query);
        $canonicalized = '';
        foreach ($query as $key => $value) {
            $canonicalized .= '&'.$this->percentEncode((string) $key).'='.$this->percentEncode((string) $value);
        }
        $stringToSign = 'GET&%2F&'.$this->percentEncode(substr($canonicalized, 1));
        $query['Signature'] = base64_encode(hash_hmac('sha1', $stringToSign, $this->accessKeySecret.'&', true));

        $response = $this->http->get($this->endpoint.'/', ['query' => $query]);
        $body = json_decode((string) $response->getBody(), true);
        if (! is_array($body)) {
            throw new RuntimeException(RediErrorMessage::get('dns.aliyun_response_invalid'));
        }
        if (! empty($body['Code'])) {
            throw new RuntimeException($body['Message'] ?? $body['Code']);
        }

        return $body;
    }

    protected function percentEncode(string $value): string
    {
        $encoded = rawurlencode($value);
        $encoded = str_replace(['+', '*', '%7E'], ['%20', '%2A', '~'], $encoded);

        return $encoded;
    }
}
