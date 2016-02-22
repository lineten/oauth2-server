<?php

namespace League\OAuth2\Server\Exception;

use Psr\Http\Message\ResponseInterface;
use Zend\Diactoros\Response;
use Zend\Diactoros\ServerRequest;
use Zend\Diactoros\Uri;

class OAuthServerException extends \Exception
{
    /**
     * @var int
     */
    private $httpStatusCode;

    /**
     * @var string
     */
    private $errorType;

    /**
     * @var null|string
     */
    private $hint;

    /**
     * @var null|string
     */
    private $redirectUri;

    /**
     * Throw a new exception.
     *
     * @param string      $message        Error message
     * @param int         $code           Error code
     * @param string      $errorType      Error type
     * @param int         $httpStatusCode HTTP status code to send (default = 400)
     * @param null|string $hint           A helper hint
     * @param null|string $redirectUri    A HTTP URI to redirect the user back to
     */
    public function __construct($message, $code, $errorType, $httpStatusCode = 400, $hint = null, $redirectUri = null)
    {
        parent::__construct($message, $code);
        $this->httpStatusCode = $httpStatusCode;
        $this->errorType = $errorType;
        $this->hint = $hint;
        $this->redirectUri = $redirectUri;
    }

    /**
     * Invalid grant type error.
     * @return static
     */
    public static function invalidGrantType()
    {
        $errorMessage = 'The provided authorization grant is invalid, expired, revoked, does not match ' .
            'the redirection URI used in the authorization request, or was issued to another client.';
        $hint = 'Check the `grant_type` parameter';

        return new static($errorMessage, 1, 'invalid_grant', 400, $hint);
    }

    /**
     * Unsupported grant type error.
     *
     * @return static
     */
    public static function unsupportedGrantType()
    {
        $errorMessage = 'The authorization grant type is not supported by the authorization server.';
        $hint = 'Check the `grant_type` parameter';

        return new static($errorMessage, 2, 'unsupported_grant_type', 400, $hint);
    }

    /**
     * Invalid request error.
     *
     * @param string      $parameter The invalid parameter
     * @param string|null $hint
     *
     * @return static
     */
    public static function invalidRequest($parameter, $hint = null)
    {
        $errorMessage = 'The request is missing a required parameter, includes an invalid parameter value, ' .
            'includes a parameter more than once, or is otherwise malformed.';
        $hint = ($hint === null) ? sprintf('Check the `%s` parameter', $parameter) : $hint;

        return new static($errorMessage, 3, 'invalid_request', 400, $hint);
    }

    /**
     * Invalid client error.
     *
     * @return static
     */
    public static function invalidClient()
    {
        $errorMessage = 'Client authentication failed';

        return new static($errorMessage, 4, 'invalid_client', 401);
    }

    /**
     * Invalid scope error.
     *
     * @param string      $scope       The bad scope
     * @param null|string $redirectUri A HTTP URI to redirect the user back to
     *
     * @return static
     */
    public static function invalidScope($scope, $redirectUri = null)
    {
        $errorMessage = 'The requested scope is invalid, unknown, or malformed';
        $hint = sprintf('Check the `%s` scope', $scope);

        return new static($errorMessage, 5, 'invalid_scope', 400, $hint, $redirectUri);
    }

    /**
     * Invalid credentials error.
     *
     * @return static
     */
    public static function invalidCredentials()
    {
        return new static('The user credentials were incorrect.', 6, 'invalid_credentials', 401);
    }

    /**
     * Server error.
     *
     * @param $hint
     *
     * @return static
     */
    public static function serverError($hint)
    {
        return new static(
            'The authorization server encountered an unexpected condition which prevented it from fulfilling'
            . ' the request: ' . $hint,
            7,
            'server_error',
            500
        );
    }

    /**
     * Invalid refresh token.
     *
     * @param string|null $hint
     *
     * @return static
     */
    public static function invalidRefreshToken($hint = null)
    {
        return new static('The refresh token is invalid.', 8, 'invalid_request', 400, $hint);
    }

    /**
     * Access denied.
     *
     * @param string|null $hint
     * @param string|null $redirectUri
     *
     * @return static
     */
    public static function accessDenied($hint = null, $redirectUri = null)
    {
        return new static(
            'The resource owner or authorization server denied the request.',
            9,
            'access_denied',
            401,
            $hint,
            $redirectUri
        );
    }

    /**
     * @return string
     */
    public function getErrorType()
    {
        return $this->errorType;
    }

    /**
     * Generate a HTTP response.
     *
     * @param \Psr\Http\Message\ResponseInterface $response
     * @param bool                                $useFragment True if errors should be in the URI fragment instead of
     *                                                         query string
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function generateHttpResponse(ResponseInterface $response = null, $useFragment = false)
    {
        if (!$response instanceof ResponseInterface) {
            $response = new Response();
        }

        $headers = $this->getHttpHeaders();

        $payload = [
            'error'   => $this->errorType,
            'message' => $this->getMessage(),
        ];

        if ($this->hint !== null) {
            $payload['hint'] = $this->hint;
        }

        if ($this->redirectUri !== null) {
            $redirectUri = new Uri($this->redirectUri);
            parse_str($redirectUri->getQuery(), $redirectPayload);

            if ($useFragment === true) {
                $headers['Location'] = (string) $redirectUri->withFragment(http_build_query(
                    array_merge($redirectPayload, $payload)
                ));
            } else {
                $headers['Location'] = (string) $redirectUri->withQuery(http_build_query(
                    array_merge($redirectPayload, $payload)
                ));
            }
        }

        foreach ($headers as $header => $content) {
            $response = $response->withHeader($header, $content);
        }

        $response->getBody()->write(json_encode($payload));

        return $response->withStatus($this->getHttpStatusCode());
    }

    /**
     * Get all headers that have to be send with the error response.
     *
     * @return array Array with header values
     */
    public function getHttpHeaders()
    {
        $headers = [
            'Content-type' => 'application/json',
        ];

        // Add "WWW-Authenticate" header
        //
        // RFC 6749, section 5.2.:
        // "If the client attempted to authenticate via the 'Authorization'
        // request header field, the authorization server MUST
        // respond with an HTTP 401 (Unauthorized) status code and
        // include the "WWW-Authenticate" response header field
        // matching the authentication scheme used by the client.
        // @codeCoverageIgnoreStart
        if ($this->errorType === 'invalid_client') {
            $authScheme = null;
            $request = new ServerRequest();
            if (isset($request->getServerParams()['PHP_AUTH_USER']) &&
                $request->getServerParams()['PHP_AUTH_USER'] !== null
            ) {
                $authScheme = 'Basic';
            } else {
                $authHeader = $request->getHeader('authorization');
                if ($authHeader !== []) {
                    if (strpos($authHeader[0], 'Bearer') === 0) {
                        $authScheme = 'Bearer';
                    } elseif (strpos($authHeader[0], 'MAC') === 0) {
                        $authScheme = 'MAC';
                    } elseif (strpos($authHeader[0], 'Basic') === 0) {
                        $authScheme = 'Basic';
                    }
                }
            }
            if ($authScheme !== null) {
                $headers[] = 'WWW-Authenticate: ' . $authScheme . ' realm="OAuth"';
            }
        }

        // @codeCoverageIgnoreEnd
        return $headers;
    }

    /**
     * Returns the HTTP status code to send when the exceptions is output.
     *
     * @return int
     */
    public function getHttpStatusCode()
    {
        return $this->httpStatusCode;
    }

    /**
     * @return null|string
     */
    public function getHint()
    {
        return $this->hint;
    }
}
