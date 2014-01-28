<?php
namespace Xola\ElasticsearchProxyBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Security\Core\User\UserInterface;

class ElasticsearchProxyController extends Controller
{

    public function proxyAction(Request $request, $slug)
    {
        $user = $this->get('security.context')->getToken()->getUser();

        if (!$user || !$user instanceof UserInterface) {
            // User not authenticated
            throw new UnauthorizedHttpException('');
        }


        // Get content for passing on in elastic search request
        $data = json_decode($request->getContent(), true);

        // Authorisation filter is the filter on seller
        $authFilter = array('term' => array('seller.id' => $user->getId()));

        $filterCounter = array('applied' => 0, 'notApplied' => 0);
        // Inject authorisation filter
        $this->addAuthFilter($data, $authFilter, $filterCounter);



        if ($filterCounter['applied'] <= 0 || $filterCounter['notApplied'] > 0) {
            // Authorisation filter could not be applied. Bad Request.
            throw new BadRequestHttpException();
        }

        // Get query string
        $query = $request->getQueryString();

        // TODO: Do we want to add the protocol to the url ?
        // Construct url for making elastic search request
        $config = $this->container->getParameter('xola_elasticsearch_proxy');
        $url = $config['client']['host'] . ':' . $config['client']['port'] . '/' . $slug;

        if ($query) {
            // Query string exists. Add it to the url
            $url .= '?' . $query;
        }

        // Method for elastic search request is same is the request method
        $method = $request->getMethod();

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $response = curl_exec($ch);
        $curlInfo = curl_getinfo($ch);
        curl_close($ch);

        if ($response === false) {
            return new JsonResponse('', 404);
        } else {
            return new JsonResponse($response, $curlInfo['http_code']);
        }
    }

    /**
     * Modifies the specified elastic search query by injecting it with the specified authorisation filter.
     * Looks for all the boolean filters in the query and adds authorisation filter within 'MUST' clause.
     * Additionally sets the class variable 'filterApplied = TRUE' if the query is modified. Recursively calls
     * itself on child array values
     *
     *
     * @param array $query          Elastic search query array
     * @param array $authFilter     Authorisation filter that is to be added inside the query
     * @param array $filterCounter  No. of times the filter was applied/not. Has keys 'applied' and 'notApplied' in it.
     *                              For an applicable filter, if authorisation is added, 'applied' gets incremented.
     *                              Else 'notApplied' gets incremented.
     * @param int $appliedFilter    Applicable only when called recursively. Incremented each time the authFilter gets applied.
     * @param bool $isQuery         Flag to indicate if $query is an elastic search query field or a child array of it.
     */
    private function addAuthFilter(&$query, $authFilter, &$filterCounter, &$appliedFilter = 0, $isQuery = false)
    {
        // Set default values if null specified.
        if (!is_array($filterCounter)) {
            $filterCounter = array();
        }
        // Filter counter must have key 'applied'. No. of filters where authorisation was added. This should
        // ideally be positive to ensure that the query was added with authorisation filter at least once.
        if (!isset($filterCounter['applied'])) $filterCounter['applied'] = 0;

        // Filter counter must have key 'notApplied'. No. of filters where addition of authorisation was missed. This
        // should ideally be zero, i.e we have added authorisation on all filters where ever applicable
        if (!isset($filterCounter['notApplied'])) $filterCounter['notApplied'] = 0;

        foreach ($query as $key => $val) {

            if ($key === 'filter') {
                if ($isQuery) {
                    // This is filter within a query. Fine
                    if (!empty($query[$key]['bool'])) {
                        if (!is_array($query[$key]['bool']['must'])) {
                            $query[$key]['bool']['must'] = array();
                        }
                        array_push($query[$key]['bool']['must'], $authFilter);
                        $appliedFilter++;
                    }
                    //Else: This filter does not have 'bool' key in it. Right now we support only boolean filters.
                }
                // Else. This is a filter that is not within a query. Will get rejected as we don't increment
                // $filterCounter['applied']
            } else {

                if ($key == 'query' && !$isQuery) {
                    // This is a top level query field.

                    // Counter to check how many times filter was applied within this array
                    $applyFilterCount = 0;

                    $this->addAuthFilter($query[$key], $authFilter, $filterCounter, $applyFilterCount, true);

                    // This was a top level query field. Check if the Auth filter was added within this.
                    if ($applyFilterCount <= 0) {
                        // Filter was not applied to this query. Not right. This is a top level query and an
                        // authorisation filter was supposed to get added within it.
                        $filterCounter['notApplied'] += 1;
                    } else {
                        // Filter was successfully apply to this query
                        $filterCounter['applied'] += 1;
                    }
                } else {
                    $this->addAuthFilter($query[$key], $authFilter, $filterApplied, $isQuery);
                }
            }

        }
    }
}