<?php

namespace Native\Mobile\Support;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Turns a Guzzle failure into one line worth printing.
 *
 * Guzzle folds the response body into RequestException's message, so a CDN
 * serving an HTML error page produces dozens of lines of markup in the console
 * — and buries the one fact that tells the developer what to do. The status
 * line is that fact.
 */
class TransferFailure
{
    public static function describe(GuzzleException $e): string
    {
        if ($e instanceof RequestException && ($response = $e->getResponse()) !== null) {
            return trim('HTTP '.$response->getStatusCode().' '.$response->getReasonPhrase());
        }

        // ConnectException and its siblings carry no response, and their message
        // is the cURL error — "Could not resolve host", "SSL certificate
        // problem" — which is already the useful part.
        return $e->getMessage();
    }
}
