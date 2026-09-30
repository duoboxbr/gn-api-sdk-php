<?php

namespace Gerencianet;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use Gerencianet\Exception\GerencianetException;
use Gerencianet\Exception\AuthorizationException;

class Request
{
    private $client;
    private $config;
    private $certified_path;

    public function __construct(array $options = null)
    {
        $this->config = Config::options($options);
        $composerData = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        $this->certified_path = isset($options['certified_path']) ? $options['certified_path'] : null;

        $clientData = [
            'debug' => $this->config['debug'],
            'base_uri' => $this->config['baseUri'],
            'headers' => [
                'Content-Type' => 'application/json',
                'api-sdk' => 'php-' . $composerData['version']
            ]
        ];

        if (isset($options['partner_token'])) {
            $clientData['headers']['partner-token'] = $options['partner_token'];
        }

        $this->client = new Client($clientData);
    }

    public function send($method, $route, $requestOptions)
    {
        try {
            if ($this->certified_path) {
                $this->client->setDefaultOption('verify', $this->certified_path);
            }

            if (isset($this->config['pixCert'])) {
                if (file_exists(realpath($this->config['pixCert']))) {
                    $requestOptions['cert'] = realpath($this->config['pixCert']);

                    $certinfo = openssl_x509_parse(file_get_contents($requestOptions['cert']));
                    $today = date("Y-m-d H:i:s");
                    $validTo = date('Y-m-d H:i:s', $certinfo['validTo_time_t']);

                    if ($validTo <= $today) {
                        throw new GerencianetException(['nome' => 'forbidden', 'mensagem' => 'Authentication certificate expired on ' . $validTo], 403);
                    }
                } else {
                    throw new GerencianetException(['nome' => 'forbidden', 'mensagem' => 'Certificate not found'], 403);
                }
            }

            // Custom header data
            if (isset($this->config['headers'])) {
                foreach ($this->config['headers'] as $key => $value) {
                    $requestOptions['headers'][$key] = $value;
                }
            }

            $response = $this->client->request($method, $route, $requestOptions);
            $responseBody = (string) $response->getBody();

            $this->logApi($method, $route, $requestOptions, [
                'responseCode' => $response->getStatusCode(),
                'responseHeaders' => $response->getHeaders(),
                'responseData' => $responseBody,
            ]);

            return json_decode($responseBody, true);
        } catch (ClientException $e) {
            $responseBody = (string) $e->getResponse()->getBody();

            $this->logApi($method, $route, $requestOptions, [
                'responseCode' => $e->getResponse()->getStatusCode(),
                'responseHeaders' => $e->getResponse()->getHeaders(),
                'responseData' => $responseBody,
            ]);

            if (is_array(json_decode($responseBody, true)) && $e->getResponse()->getStatusCode() != 401) {
                throw new GerencianetException(json_decode($responseBody, true), $e->getResponse()->getStatusCode());
            } else {
                throw new AuthorizationException(
                    $e->getResponse()->getStatusCode(),
                    $e->getResponse()->getReasonPhrase(),
                    $responseBody
                );
            }
        } catch (ServerException $se) {
            $responseBody = (string) $se->getResponse()->getBody();

            $this->logApi($method, $route, $requestOptions, [
                'responseCode' => $se->getResponse()->getStatusCode(),
                'responseHeaders' => $se->getResponse()->getHeaders(),
                'responseData' => $responseBody,
            ]);

            $decodedBody = json_decode($responseBody, true);

            throw new GerencianetException(
                is_array($decodedBody) ? $decodedBody : [
                    'nome' => 'server_error',
                    'mensagem' => $responseBody !== '' ? $responseBody : $se->getResponse()->getReasonPhrase(),
                ],
                $se->getResponse()->getStatusCode()
            );
        } catch (\Exception $e) {
            $this->logApi($method, $route, $requestOptions, [
                'curlErrorMessage' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Salva o log da requisição/resposta via log_api_save(), quando disponível
     * (função definida pelo ispbox — a SDK também é usada fora dele, por isso o function_exists)
     */
    private function logApi($method, $route, array $requestOptions, array $response): void
    {
        if (!function_exists('log_api_save')) {
            return;
        }

        $body = $requestOptions['json'] ?? null;

        log_api_save(array_merge([
            'method' => strtoupper($method),
            'url' => rtrim($this->config['baseUri'], '/') . '/' . ltrim($route, '/'),
            'requestHeaders' => $this->buildRequestHeaders($method, $route, $requestOptions),
            'requestBody' => is_array($body) ? json_encode($body) : $body,
        ], $response));
    }

    /**
     * Monta o bloco de headers HTTP da requisição no mesmo formato usado
     * pelo restante do sistema (linha de request + Host + demais headers)
     */
    private function buildRequestHeaders($method, $route, array $requestOptions): string
    {
        $host = parse_url($this->config['baseUri'], PHP_URL_HOST);

        $lines = [strtoupper($method) . ' ' . $route . ' HTTP/1.1', 'Host: ' . $host];

        foreach (($requestOptions['headers'] ?? []) as $name => $value) {
            $lines[] = $name . ': ' . (is_array($value) ? implode(', ', $value) : $value);
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    public function __get($property)
    {
        if (property_exists($this, $property)) {
            return $this->$property;
        }
    }

    public function __set($property, $value)
    {
        if (property_exists($this, $property)) {
            $this->$property = $value;
        }
    }
}
