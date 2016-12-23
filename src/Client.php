<?php

/**
 * PHPOAIPMH Library
 *
 * @license http://opensource.org/licenses/MIT
 * @link https://github.com/caseyamcl/phpoaipmh
 * @version 2.0
 * @package caseyamcl/phpoaipmh
 * @author Casey McLaughlin <caseyamcl@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * ------------------------------------------------------------------
 */

namespace Phpoaipmh;

use Phpoaipmh\Exception\OaipmhException;
use Phpoaipmh\Exception\MalformedResponseException;
use Phpoaipmh\HttpAdapter\HttpAdapterInterface;
use Phpoaipmh\Model\RecordPage;
use Phpoaipmh\Model\RequestParameters;

/**
 * OAI-PMH Client class retrieves and decodes OAI-PMH from a given URL
 *
 * @since v1.0
 * @author Casey McLaughlin <caseyamcl@gmail.com>
 */
class Client implements ClientInterface
{
    const AUTO_DETECT = null;

    /**
     * @var HttpAdapterInterface
     */
    private $httpClient;

    /**
     * @var DateGranularity
     */
    private $dateGranularity;

    /**
     * Constructor
     *
     * @param HttpAdapterInterface $httpClient       HTTP Client
     * @param DateGranularity      $dateGranularity  Optionally override auto-detection of date granularity
     */
    public function __construct(HttpAdapterInterface $httpClient, DateGranularity $dateGranularity = self::AUTO_DETECT)
    {
        $this->httpClient      = $httpClient;
        $this->dateGranularity = $dateGranularity;
    }

    /**
     * @return HttpAdapterInterface
     */
    public function getHttpClient()
    {
        return $this->httpClient;
    }

    /**
     * Get a single record or single item from the OAI-PMH endpoint
     *
     * @param RequestParameters $requestParameters
     * @return \SimpleXMLElement
     */
    public function getRecord(RequestParameters $requestParameters)
    {
        return $this->request($requestParameters);
    }

    /**
     * Get date granularity (either set manually or detected in the server)
     *
     * @param string $endpointUrl
     * @param array  $extraParams
     * @return DateGranularity
     */
    public function getDateGranularity($endpointUrl, array $extraParams = [])
    {
        if ($this->dateGranularity) {
            return $this->dateGranularity;
        }
        else {
            $requestParams = new RequestParameters($endpointUrl, 'Identify', $extraParams);
            $record = $this->request($requestParams);

            $format = (isset($record->Identify->granularity))
                ? (string) $record->Identify->granularity
                : DateGranularity::DATE;

            return new DateGranularity($format);
        }
    }

    /**
     * @param RequestParameters $requestParameters
     * @return \Generator|\SimpleXMLElement[]
     */
    public function iterateRecords(RequestParameters $requestParameters)
    {
        foreach ($this->iteratePages($requestParameters) as $page) {
            foreach ($page->getRecords() as $record) {
                yield $record;
            }
        }
    }

    /**
     * @param RequestParameters $requestParameters
     * @return \Generator|RecordPage[]
     */
    public function iteratePages(RequestParameters $requestParameters)
    {
        // Determine date granularity
        $dateGranularity = $this->getDateGranularity($requestParameters->getEndpointUrl());

        // Iterator control
        $continue      = true;
        $requestParams = clone $requestParameters;

        while ($continue) {

            // Do the request
            $response = $this->request($requestParams);

            // Send the request
            $pageRecord = RecordPage::buildFromRawXml(
                $response,
                $requestParams,
                $dateGranularity
            );

            // If there is a resumption token, proceed
            if ($pageRecord->getPaginationInfo()->hasResumptionToken()) {
                $continue = true;
                $requestParams = $requestParams->withParam(
                    'ResumptionToken',
                    $pageRecord->getPaginationInfo()->getResumptionToken()
                );
            }
            else {
                $continue = false;
            }

            yield $pageRecord;
        }
    }

    /**
     * Get the total number of records
     *
     * @param RequestParameters $requestParameters
     * @return int|null  Returns NULL if count not provided
     */
    public function getNumTotalRecords(RequestParameters $requestParameters)
    {
        /** @var RecordPage $currentPage Get the first page... */
        $currentPage = $this->iteratePages($requestParameters)->current();
        return $currentPage->getPaginationInfo()->getCompleteRecordCount();
    }

    /**
     * Perform a request and return a OAI SimpleXML Document
     *
     * @param  RequestParameters $requestParameters
     * @return \SimpleXMLElement An XML document
     */
    private function request(RequestParameters $requestParameters)
    {
        //Build the HTTP query params
        $httpQueryParams = array_merge(
            ['verb' => $requestParameters->getVerb()],
            $requestParameters->getParams()
        );

        //Do the request and return the decoded response
        $responseBody = $this->httpClient->request($requestParameters->getEndpointUrl(), $httpQueryParams);
        return $this->decodeResponse($responseBody);
    }

    /**
     * Decode the response into XML
     *
     * @param  string            $resp The response body from a HTTP request
     * @return \SimpleXMLElement An XML document
     */
    protected function decodeResponse($resp)
    {
        //Setup a SimpleXML (a.k.a. 'Record') Document
        try {
            $xml = @new \SimpleXMLElement($resp);
        } catch (\Exception $e) {
            throw new MalformedResponseException(sprintf("Could not decode XML Response: %s", $e->getMessage()));
        }

        //If we get back a OAI-PMH error, throw a OaipmhException
        if (isset($xml->error)) {
            $code = (string) $xml->error['code'];
            $msg  = (string) $xml->error;
            throw new OaipmhException($code, $msg);
        }

        return $xml;
    }
}
