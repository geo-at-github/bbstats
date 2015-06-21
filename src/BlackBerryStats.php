<?php

/*
 * This file is part of the BlackBerryStats (BBStats) package.
 *
 * (c) Georg Kamptner <public@geoathome.at>
 * Available on GitHub: https://github.com/geo-at-github/bbstats
 *
 * MIT License
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace GeoAtHome\BBStats;


use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Message\MessageInterface;
use Symfony\Component\Validator\Constraints\DateTime;


class BlackBerryStats
{
    const REPORT_TYPE_DOWNLOADS         = 1;
    const REPORT_TYPE_DOWNLOADS_SUMMARY = 2;
    const REPORT_TYPE_PURCHASES         = 3;
    const REPORT_TYPE_SUBSCRIPTIONS     = 4;
    const REPORT_TYPE_REVIEWS           = 5;

    const REPORT_STATE_UNKNOWN      = 0;
    const REPORT_STATE_PROCESSING   = 1;
    const REPORT_STATE_READY        = 2;

    /**
     * @var array;
     */
    protected $REPORT_DOWNLOAD_FILENAMES;

    /**
     * @var array
     */
    protected $queue;

    /**
     * @var int
     */
    protected $queuePos;

    /**
     * @var array
     */
    protected $defaultHeaders;

    /**
     * @var array
     */
    protected $defaultCurlOptions;

    /**
     * @var array
     */
    protected $tmp;

    /**
     * @var \GuzzleHttp\Client
     */
    protected $client;

    /**
     * @var \GuzzleHttp\Cookie\CookieJar
     */
    protected $cookieJar;

    /**
     * Downloaded zips are stored and extracted in this directory.
     * Make sure php has read and write permissions for this directory.
     *
     * @var string
     */
    protected $tmpDir;

    /**
     * After login() has finished this contains an array with
     * all the necessary login data for future requests.
     *
     * <pre>
     * array(
     *   'JSESSIONID'       => '...'
     *   'ISV_COOKIE_DATA'  => '...'
     *   'ISV_SESSION_ID'   => '...'
     *   'csrfToken'        => '...'
     * )
     * </pre>
     *
     * @var array
     */
    protected $loginTokens;

    /**
     * @param   string  $tmpDir     Downloaded zips are stored and extracted in this directory.
     *                              Make sure php has read and write permissions for this directory.
     */
    public function __construct( $tmpDir )
    {
        // index corresponds to BlackBerryStats::REPORT_TYPE_...
        // Example: Super_Awesome_App_DownloadSummary_11_May_2015_to_10_Jun_2015_by_date.zip
        $this->REPORT_DOWNLOAD_FILENAMES = array(
            1 => "%s_Downloads_for_%s_to_%s_by_date.zip",
            2 => "%s_DownloadSummary_%s_to_%s_by_date.zip",
            3 => "%s_Purchase_for_%s_to_%s_by_date.zip",
            4 => "%s_Subscriptions_for_%s_to_%s_by_day.zip", // not sure this is correct, ToDO: test it
            5 => "%s_Reviews_%s_to_%s_by_date.zip"
        );

        $this->tmpDir = $tmpDir;
        $this->tmp = array();
        $this->client = new \GuzzleHttp\Client();
        $this->cookieJar = new \GuzzleHttp\Cookie\CookieJar();

        $this->defaultHeaders = array(
            "Host"              => "appworld.blackberry.com",
            "User-Agent"        => "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:37.0) Gecko/20100101 Firefox/37.0",
            "Accept"            => "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
            "Accept-Language"   => "en-US,en;q=0.5" ,
            "DNT"               => "1",
            "Connection"        => "keep-alive",
            "Pragma"            => "no-cache",
            "Cache-Control"     => "no-cache"
        );

        $this->defaultCurlOptions = array(
            CURLOPT_SSL_VERIFYHOST  => false,
            CURLOPT_SSL_VERIFYPEER  => false
        );
    }

    protected function start()
    {
        $this->queuePos = -1;
        $this->next(); // repeats until it´s done (recursion)
    }

    protected function next()
    {
        $this->queuePos++;

        // init tmp
        $this->tmp[$this->queue[$this->queuePos]] = array();

        // make request
        $response = call_user_func( array($this, $this->queue[$this->queuePos]) );

        // handle response (extract tmp data)
        call_user_func( array($this, $this->queue[$this->queuePos]."Result"), $response );

        if( $this->queuePos < sizeof( $this->queue ) - 1 )
        {
            $this->next();
        }
    }

    protected function end()
    {
        // ToDo: cleanup after request (don´t close the session)
    }

    /**
     * @param $str
     * @param $prefix
     * @param $suffix
     * @return null|string
     */
    protected function extract($str, $prefix, $suffix)
    {
        $matches = array();
        preg_match('/'.$prefix.'([^'.$suffix[0].']*)'.$suffix.'/', $str, $matches);
        if( sizeof($matches) > 1 )
        {
            $result = rawurldecode($matches[1]);
        }
        else
        {
            $result = "";
        }
        return $result;
    }

    protected function getCookie( $name, $path, $attr = null )
    {
        $cookies = $this->cookieJar->toArray();
        foreach( $cookies as $c => $cookie )
        {
            if( strpos($cookie["Path"], $path) === 0 && $cookie["Name"] == $name )
            {
                if( $attr === null )
                {
                    return $cookie;
                }
                else
                {
                    return $cookie[$attr];
                }
            }
        }
        return null;
    }

    /**
     * Logs the user in and returns an ASSOC array with the login tokens or an empty array if the login failed.
     *
     * @param   string      $username
     * @param   string      $password
     *
     * @return array    Returns an ASSOC array with the login tokens or an empty array if the login failed.
     */
    public function login( $username, $password )
    {
        $this->tmp['loginData']['username'] = $username;
        $this->tmp['loginData']['password'] = $password;

        $this->queue = array(
            'reqHome',
            'reqLoginInitiator',
            'reqAuth',
            'reqLogin',
            'reqVerifyLogin'
        );

        $this->start();

        return $this->loginTokens;
    }

    public function logout()
    {
        if( empty($this->loginTokens) )
        {
            trigger_error( "BlackBerryStats::logout(): Login tokens are empty. Login first!" );
            return;
        }

        $csrfToken = $this->loginTokens["csrfToken"];

        $url = "https://appworld.blackberry.com/isvportal/logout.do";
        $response = $this->client->get($url, array(
            "config" => array(
                "curl" => $this->defaultCurlOptions
            ),
            "headers" => array(
                    "Referer" => "https://appworld.blackberry.com/isvportal/reports/home.do?csrfToken=" . $csrfToken,
                ) + $this->defaultHeaders,
            "query" => array(
                "rand" => rand(1000,9999)
            ),
            "cookies" => $this->cookieJar
        ));

        // empty the login token since they are now useless
        $this->loginTokens = array();

        return $response;
    }

    protected function reqHome()
    {
        $url = 'https://appworld.blackberry.com/isvportal/home.do';
        $response = $this->client->get($url, array(
            'config' => [
                'curl' => $this->defaultCurlOptions
            ],
            "headers" => $this->defaultHeaders,
            "cookies" => $this->cookieJar
        ));

        return $response;
    }

    protected function reqHomeResult( \GuzzleHttp\Message\MessageInterface $response )
    {
        $result = $response->getBody()->getContents();

        // jsessionid
        $this->tmp['home']['jsessionid'] = $this->getCookie( "JSESSIONID", "/isvportal", "Value" );

        // csrftoken
        $matches = array();
        preg_match('/\?csrfToken=([^"]*)\">/', $result, $matches);
        $this->tmp['home']['csrfToken'] = $matches[1];
    }

    protected function reqLoginInitiator()
    {
        $url = "https://appworld.blackberry.com/isvportal/sso/loginInitiator.do;jsessionid=".$this->tmp['home']['jsessionid'];
        $response = $this->client->get($url, array(
            'config' => array(
                'curl' => $this->defaultCurlOptions
            ),
            "headers" => $this->defaultHeaders,
            'query' => array(
                "csrfToken" => $this->tmp['home']['csrfToken']
            ),
            "cookies" => $this->cookieJar
        ));

        return $response;
    }

    protected function reqLoginInitiatorResult( \GuzzleHttp\Message\MessageInterface $response )
    {
        $result = $response->getBody()->getContents();

        // openidAssocHandle
        $matches = array();
        preg_match('/openid.assoc_handle" value="([^"]*)"\/>/', $result, $matches);
        $this->tmp['loginInitiator']['openidAssocHandle'] = $matches[1];
    }

    protected function reqAuth()
    {
        $url = "https://blackberryid.blackberry.com/openid/auth";
        $response = $this->client->post($url, array(
            'config' => array(
                'curl' => $this->defaultCurlOptions
            ),
            "headers" => $this->defaultHeaders,
            'query' => array(
                "csrfToken" => $this->tmp['home']['csrfToken']
            ),
            'body' => array(
                "openid.ns"                             => "http://specs.openid.net/auth/2.0",
                "openid.claimed_id"                     => "http://specs.openid.net/auth/2.0/identifier_select",
                "openid.identity"                       => "http://specs.openid.net/auth/2.0/identifier_select",
                "openid.return_to"                      => "https://appworld.blackberry.com/isvportal/sso/verifyLogin.do",
                "openid.realm"                          => "https://appworld.blackberry.com",
                "openid.assoc_handle"                   => $this->tmp['loginInitiator']['openidAssocHandle'],
                "openid.mode"                           => "checkid_setup",
                "openid.ns.ext1"                        => "http://openid.net/srv/ax/1.0",
                "openid.ext1.mode"                      => "fetch_request",
                "openid.ext1.type.email"                => "http://axschema.org/contact/email",
                "openid.ext1.type.firstname"            => "http://axschema.org/namePerson/first",
                "openid.ext1.type.lastname"             => "http://axschema.org/namePerson/last",
                "openid.ext1.type.nickname"             => "http://axschema.org/namePerson/friendly",
                "openid.ext1.type.confirmedemail"       => "http://axschema.org/contact/confirmedemail",
                "openid.ext1.required"                  => "email,firstname,lastname,nickname,confirmedemail",
                "openid.ns.ext2"                        => "http://specs.openid.net/extensions/pape/1.0",
                "openid.ext2.preferred_auth_policies"   => "",
                "openid.ext2.max_auth_age"              => "60"
            ),
            "cookies" => $this->cookieJar
        ));

        return $response;
    }

    protected function reqAuthResult( \GuzzleHttp\Message\MessageInterface $response )
    {
        $result = $response->getBody()->getContents();

        $this->tmp["callbackuri"]["formId:logincommandLink"] = $this->extract( $result, 'name="formId:logincommandLink".+?value="', '"' );
        $this->tmp["callbackuri"]["callbackuri"]             = $this->extract( $result, 'name="callbackuri".+?value="', '"' );
        $this->tmp["callbackuri"]["userdata"]                = $this->extract( $result, 'name="userdata".+?value="', '"' );
        $this->tmp["callbackuri"]["authtype"]                = $this->extract( $result, 'name="authtype".+?value="', '"' );
        $this->tmp["callbackuri"]["openidmode"]              = $this->extract( $result, 'name="openidmode".+?value="', '"' );
        $this->tmp["callbackuri"]["css"]                     = $this->extract( $result, 'name="css".+?value="', '"' );
        $this->tmp["callbackuri"]["realm"]                   = $this->extract( $result, 'name="realm".+?value="', '"' );
        $this->tmp["callbackuri"]["requireConfirmedEmail"]   = $this->extract( $result, 'name="requireConfirmedEmail".+?value="', '"' );
        $this->tmp["callbackuri"]["email"]                   = $this->extract( $result, 'name="email" value="', '"' );
        $this->tmp["callbackuri"]["rpid"]                    = $this->extract( $result, 'name="rpid".+?value="', '"' );
        $this->tmp["callbackuri"]["sig"]                     = $this->extract( $result, 'name="sig".+?value="', '"' );
        $this->tmp["callbackuri"]["azEdit"]                  = $this->extract( $result, 'name="azEdit".+?value="', '"' );
        $this->tmp["callbackuri"]["javax.faces.ViewState"]   = $this->extract( $result, 'name="javax.faces.ViewState".+?value="', '"' );

        // extract bbidcchk cookie data from javascript
        /** Sample Code:
         * var name = "bbidcchk";
         * document.cookie = name + " = 1; secure; path=/bbid";
         */
        $matches = array();
        preg_match('/"bbidcchk";.+?document.cookie *=.+?= *([^;]*) *;/s', $result, $matches);
        $this->tmp["callbackuri"]["bbidcchk"] = $matches[1];
        // add to cookies jar
        $this->cookieJar->setCookie( new SetCookie(array(
            "Name"      => "bbidcchk",
            "Value"     => $this->tmp["callbackuri"]["bbidcchk"],
            "Secure"    => 1,
            "Path"      => "/bbid",
            "Domain"    => "blackberryid.blackberry.com",
            "Expires"   => time() + 60 * 60 * 24
        )));
    }

    protected function reqLogin()
    {
        $url = "https://blackberryid.blackberry.com/bbid/login";
        $response = $this->client->post($url, array(
            'config' => array(
                'curl' => $this->defaultCurlOptions
            ),
            "headers" => $this->defaultHeaders,
            'query' => array(
            ),
            'body' => array(
                "formId"                    => "formId",
                "formId:email"              => $this->tmp['loginData']['username'],
                "formId:password"           => $this->tmp['loginData']['password'],
                "formId:logincommandLink"   => $this->tmp["callbackuri"]["formId:logincommandLink"],
                "callbackuri"               => $this->tmp["callbackuri"]["callbackuri"],
                "userdata"                  => $this->tmp["callbackuri"]["userdata"],
                "authtype"                  => $this->tmp["callbackuri"]["authtype"],
                "openidmode"                => $this->tmp["callbackuri"]["openidmode"],
                "css"                       => $this->tmp["callbackuri"]["css"],
                "realm"                     => $this->tmp["callbackuri"]["realm"],
                "requireConfirmedEmail"     => $this->tmp["callbackuri"]["requireConfirmedEmail"],
                "email"                     => $this->tmp["callbackuri"]["email"],
                "rpid"                      => $this->tmp["callbackuri"]["rpid"],
                "sig"                       => $this->tmp["callbackuri"]["sig"],
                "azEdit"                    => $this->tmp["callbackuri"]["azEdit"],
                "javax.faces.ViewState"     => $this->tmp["callbackuri"]["javax.faces.ViewState"],
                "conversationPropagation"   => "join"
            ),
            "cookies" => $this->cookieJar
        ));

        return $response;
    }

    protected function reqLoginResult( \GuzzleHttp\Message\MessageInterface $response )
    {
        $result = $response->getBody()->getContents();

        $this->tmp["aw"] = array();
        $this->tmp["aw"]['form']["openid.ns"]                          = $this->extract( $result, 'name="openid.ns".+?value="', '"' );
        $this->tmp["aw"]['form']["openid.op_endpoint"]                 = $this->extract( $result, 'name="openid.op_endpoint".+?value="', '"' );
        $this->tmp["aw"]['form']["openid.claimed_id"]                  = $this->extract( $result, 'name="openid.claimed_id".+?value="', '"' );
        $this->tmp["aw"]['form']["openid.response_nonce"]              = $this->extract( $result, 'name="openid.response_nonce".+?value="', '"' );
        $this->tmp["aw"]['form']["openid.mode"]                        = $this->extract( $result, 'name="openid.mode".+?value="', '"' );
        $this->tmp["aw"]['form']["openid.identity"]                    = $this->extract( $result, 'name="openid.identity".+?value="', '"' );
        $this->tmp["aw"]['form']["openid.return_to"]                   = $this->extract( $result, 'name="openid.return_to".+?value="', '"' );
        $this->tmp["aw"]['form']["openid.assoc_handle"]                = $this->extract( $result, 'name="openid.assoc_handle" value="', '"' );
        $this->tmp["aw"]['form']["openid.signed"]                      = $this->extract( $result, 'name="openid.signed".+?value="', '"' );
        $this->tmp["aw"]['form']["openid.sig"]                         = $this->extract( $result, 'name="openid.sig".+?value="', '"' );
        $this->tmp["aw"]['form']["openid.ns.ext1"]                     = $this->extract( $result, 'name="openid.ns.ext1".+?value="', '"' );
        $this->tmp["aw"]['form']["openid.ext1.auth_policies"]          = $this->extract( $result, 'name="openid.ext1.auth_policies".+?value="', '"' );
        $this->tmp["aw"]['form']["openid.ext1.auth_time"]              = $this->extract( $result, 'name="openid.ext1.auth_time".+?value="', '"' );
        $this->tmp["aw"]['form']["openid.ns.ext2"]                     = $this->extract( $result, 'name="openid.ns.ext2".+?value="', '"' );
        $this->tmp["aw"]['form']["openid.ext2.mode"]                   = $this->extract( $result, 'name="openid.ext2.mode".+?value="', '"' );
        $this->tmp["aw"]['form']["openid.ext2.type.email"]             = $this->extract( $result, 'name="openid.ext2.type.email".+?value="', '"' );
        $this->tmp["aw"]['form']["openid.ext2.value.email"]            = $this->extract( $result, 'name="openid.ext2.value.email".+?value="', '"' );
        $this->tmp["aw"]['form']["openid.ext2.type.firstname"]         = $this->extract( $result, 'name="openid.ext2.type.firstname".+?value="', '"' );
        $this->tmp["aw"]['form']["openid.ext2.value.firstname"]        = $this->extract( $result, 'name="openid.ext2.value.firstname".+?value="', '"' );
        $this->tmp["aw"]['form']["openid.ext2.type.lastname"]          = $this->extract( $result, 'name="openid.ext2.type.lastname".+?value="', '"' );
        $this->tmp["aw"]['form']["openid.ext2.value.lastname"]         = $this->extract( $result, 'name="openid.ext2.value.lastname".+?value="', '"' );
        $this->tmp["aw"]['form']["openid.ext2.type.nickname"]          = $this->extract( $result, 'name="openid.ext2.type.nickname".+?value="', '"' );
        $this->tmp["aw"]['form']["openid.ext2.value.nickname"]         = $this->extract( $result, 'name="openid.ext2.value.nickname".+?value="', '"' );
        $this->tmp["aw"]['form']["openid.ext2.type.confirmedemail"]    = $this->extract( $result, 'name="openid.ext2.type.confirmedemail".+?value="', '"' );
        $this->tmp["aw"]['form']["openid.ext2.value.confirmedemail"]   = $this->extract( $result, 'name="openid.ext2.value.confirmedemail".+?value="', '"' );
    }

    protected function reqVerifyLogin()
    {
        $url = "https://appworld.blackberry.com/isvportal/sso/verifyLogin.do";
        $response = $this->client->post($url, array(
            'config' => array(
                'curl' => $this->defaultCurlOptions
            ),
            "headers" => $this->defaultHeaders,
            'query' => array(
            ),
            'body' => $this->tmp["aw"]['form'],
            "cookies" => $this->cookieJar
        ));

        return $response;
    }

    protected function reqVerifyLoginResult( \GuzzleHttp\Message\MessageInterface $response )
    {
        $result = $response->getBody()->getContents();

        $csrfToken = $this->extract( $result, 'home.do\?csrfToken=', '"' );

        // save token and session data if available
        if( $this->getCookie("ISV_SESSION_ID", "/isvportal", "Value") !== null )
        {
            $this->loginTokens = array(
                "JSESSIONID"       => $this->getCookie("JSESSIONID", "/isvportal", "Value"),
                "ISV_COOKIE_DATA"  => $this->getCookie("ISV_COOKIE_DATA", "/isvportal", "Value"),
                "ISV_SESSION_ID"   => $this->getCookie("ISV_SESSION_ID", "/isvportal", "Value"),
                "csrfToken"        => $csrfToken
            );
        }
        else
        {
            // login failed > reset loginTokens to empty array
            $this->loginTokens = array();
        }

        return $this->loginTokens;
    }

    /**
     * Use this to initialize a new session without a fresh login (reuse the old tokens).
     *
     * @param   array   $loginTokens    An array containing "JSESSIONID", "ISV_COOKIE_DATA", "ISV_SESSION_ID" and "csrfToken".
     */
    public function setLoginTokens( $loginTokens )
    {
        $this->loginTokens = $loginTokens + $this->loginTokens;

        $this->cookieJar->setCookie(new SetCookie(array(
            "Name"      => "JSESSIONID",
            "Value"     => $loginTokens["JSESSIONID"],
            "Path"      => "/isvportal",
            "Domain"    => "blackberryid.blackberry.com",
            "Expires"   => time() + 60 * 60 * 24
        )));

        $this->cookieJar->setCookie(new SetCookie(array(
            "Name"      => "ISV_COOKIE_DATA",
            "Value"     => $loginTokens["ISV_COOKIE_DATA"],
            "Path"      => "/isvportal",
            "Domain"    => "blackberryid.blackberry.com",
            "Expires"   => time() + 60 * 60 * 24
        )));

        $this->cookieJar->setCookie(new SetCookie(array(
            "Name"      => "ISV_SESSION_ID",
            "Value"     => $loginTokens["ISV_SESSION_ID"],
            "Path"      => "/isvportal",
            "Domain"    => "blackberryid.blackberry.com",
            "Expires"   => time() + 60 * 60 * 24
        )));
    }

    public function getLoginTokens()
    {
        return $this->loginTokens;
    }

    /**
     * @param   mixed       $date       Either an int (offset in days relative to time()) or
     *                                  a date in the format $format ( default: "Y-m-d" ).
     * @param   string      $format     A date format (default is "Y-m-d" e.g. YYYY-MM-DD).
     * @return  \DateTime
     */
    protected function getDateTimeFromInput( $date, $format = 'Y-m-d' )
    {
        if( is_numeric($date) )
        {
            $st = time() + 60 * 60 * 24 * $date;
            $result = date_create();
            date_timestamp_set( $result, $st );
        }
        else
        {
            $result = date_create_from_format($format, $date);
        }
        $result->setTime(1,1,1);

        return $result;
    }


    /**
     * @param $appId            int Numeric App ID | "all" (Report for all Aps).
     * @param $reportType       int BlackBerryStats::REPORT_TYPE_*
     * @param $startDate        int Nr. of days (offset to today) or date in format YYYY-MM-DD
     * @param $endDate          int Nr. of days (offset to today) or date in format YYYY-MM-DD
     * @return BrowserRequest
     */
    public function scheduleReport( $appId, $reportType, $startDate = -14, $endDate = 0 )
    {
        if( empty($this->loginTokens) )
        {
            trigger_error( "BlackBerryStats::scheduleReport(): Login tokens are empty. Login first!" );
            return;
        }

        $startDate = $this->getDateTimeFromInput( $startDate, "Y-m-d" );
        $endDate = $this->getDateTimeFromInput( $endDate, "Y-m-d" );
        $url = "https://appworld.blackberry.com/isvportal/reports/scheduleData.do";
        $response = $this->client->post($url, array(
            "config" => array(
                "curl" => $this->defaultCurlOptions
            ),
            "headers" => array(
                "Referer" => "https://appworld.blackberry.com/isvportal/reports/scheduleDataPage.do?csrfToken=" . $this->loginTokens["csrfToken"],
            ) + $this->defaultHeaders,
            "body" => array(
                "csrfToken"             => $this->loginTokens["csrfToken"],
                "selectedReportType"    => $reportType,
                "selectedSubType"       => 0,
                "selectedContent"       => ( $appId == "all" ) ? "" : $appId,
                "selectedVG"            => "",
                "selectedPeriod"        => 1,
                "selectedSortOption"    => 0,
                "startDate"             => $startDate->format("Y-m-d"),
                "endDate"               => $endDate->format("Y-m-d"),
            ),
            "cookies" => $this->cookieJar
        ));

        return $response;
    }

    /**
     * Returns reports meta data in the form of:
     * <pre>
     * [
     *      [
     *          "downloadLink" => "https://appworld.blackberry.com/isvportal/reports/downloadData.do?csrfToken=...&fileName=....zip"
     *          "fileName" => "....zip"
     *          "deleteLink" => "https://appworld.blackberry.com/isvportal/reports/deleteData.do?csrfToken=...&fileName=....zip"
     *          "state" => BlackBerryStats::REPORT_STATE_READY
     *      ],
     *      [
     *          "downloadLink" => "https://appworld.blackberry.com/isvportal/reports/downloadData.do?csrfToken=...&fileName=....zip"
     *          "fileName" => "....zip"
     *          "deleteLink" => "https://appworld.blackberry.com/isvportal/reports/deleteData.do?csrfToken=...&fileName=....zip"
     *          "state" => BlackBerryStats::REPORT_STATE_READY
     *      ]
     * ]
     * </pre>
     *
     * Be aware that unfinished reports do not show up in the reports list even though their are in processing.
     *
     * @return array
     */
    public function getReports()
    {
        if( empty($this->loginTokens) )
        {
            trigger_error( "BlackBerryStats::getReports(): Login tokens are empty. Login first!" );
            return;
        }

        $response = $this->reqReports();
        $result = $this->reqReportsResult($response);

        return $result;
    }

    protected function reqReports()
    {
        $url = "https://appworld.blackberry.com/isvportal/reports/fetchDownloadListAction.do";
        $response = $this->client->post($url, array(
            "config" => array(
                "curl" => $this->defaultCurlOptions
            ),
            "headers" => array(
                    "Referer" => "https://appworld.blackberry.com/isvportal/reports/home.do?csrfToken=" . $this->loginTokens["csrfToken"],
                ) + $this->defaultHeaders,
            "body" => array(
                "csrfToken"   => $this->loginTokens["csrfToken"],
            ),
            "cookies" => $this->cookieJar
        ));

        return $response;
    }

    protected function reqReportsResult( \GuzzleHttp\Message\MessageInterface $response )
    {
        $result = $response->getBody()->getContents();
        $matches = array();
        // Sample: <a href="/isvportal/reports/downloadData.do?csrfToken=WNVZ-J9M0-V86A-1FLF-PPAB-2X69-IJG5-BV2D&fileName=Sniper_Ops_3D_Kill_Terror_Shooter_Downloads_for_23_Apr_2015_to_07_May_2015_by_date.zip" class="data-dump data-ready">Sniper_Ops_3D_Kill_Terror_Shooter_Downloads_for_23_Apr_2015_to_07_May_2015_by_date.zip</a></td>
        preg_match_all( '|/isvportal/reports/downloadData\.do.+?&fileName=([^"]*)|', $result, $matches, PREG_SET_ORDER);

        $reports = array();
        foreach($matches as $key => $match)
        {
            $report = array(
                "downloadLink"  => "https://appworld.blackberry.com" . $match[0],
                "fileName"      => $match[1],
                "deleteLink"    => "https://appworld.blackberry.com/isvportal/reports/deleteData.do?csrfToken=".$this->loginTokens["csrfToken"]."&fileName=".$match[1]
            );
            array_push( $reports, $report );

        }

        return $reports;
    }

    /**
     * Downloads and unzips a report into $targetPath.
     *
     * It expects $reports as an array in the form of:
     * <pre>
     *  [
     *      "downloadLink" => "download.do/?asdasd"
     *      "fileName" => "File.zip"
     *      "deleteLink" => "delete.do?asdasd"
     *  ]
     * </pre>
     *
     * @param   array   $report         The report array with "downloadLink", "fileName" and "deleteLink".
     *                                  You can get it with the BlackBerryStats::getReports() method.
     * @param   string  $filePath       Absolute path to the resulting .csv file (result CSV-data will be stored in this file)
     * @param   bool    $returnCsvData  Whether to return the interpreted csv data or not (consumes a lot of memory for big files).
     */
    public function downloadReport( $report, $filePath = null, $returnCsvData = false )
    {
        if( empty($this->loginTokens) )
        {
            trigger_error( "BlackBerryStats::getReports(): Login tokens are empty. Login first!" );
            return;
        }

        $csrfToken = $this->loginTokens["csrfToken"];

        $zipFilePath = $this->tmpDir . $report["fileName"];
        $unzipDirPath = $this->tmpDir . basename($report["fileName"],".zip") . "/";
        $csvFilePath = $unzipDirPath . basename($report["fileName"],".zip").".csv";

        $url = $report["downloadLink"];
        $response = $this->client->get($url, array(
            "config" => array(
                "curl" => $this->defaultCurlOptions
            ),
            "headers" => array(
                "Referer" => "https://appworld.blackberry.com/isvportal/reports/home.do?csrfToken=" . $csrfToken,
                ) + $this->defaultHeaders,
            "query" => array(
                "csrfToken" => $this->loginTokens["csrfToken"],
            ),
            "cookies" => $this->cookieJar
        ));

        // save file
        file_put_contents( $zipFilePath, $response->getBody()->getContents() );

        // unzip
        if( !file_exists( $unzipDirPath ) )
        {
            mkdir( $unzipDirPath );
        }
        $zip = new \ZipArchive;
        if( $zip->open($zipFilePath) === true )
        {
            $zip->extractTo($unzipDirPath);
            $zip->close();
        }
        $zip = null;

        // delete zip
        unlink( $zipFilePath );

        // move unzipped file
        $finalFilePath = $csvFilePath;
        if( $filePath !== null )
        {
            rename( $csvFilePath, $filePath );
            $finalFilePath = $filePath;
        }

        // extract data from csv if necessary
        $data = null;
        if( $returnCsvData == true )
        {
            $data = $this->csvToArray( $finalFilePath, ',', '"' );
        }

        // remove files and dirs
        if( file_exists( $csvFilePath ) )
        {
            unlink( $csvFilePath );
        }
        rmdir( $unzipDirPath );

        return $data;
    }

    protected function csvToArray($filePath, $delimiter = null, $enclosure = null)
    {
        if(!file_exists($filePath) || !is_readable($filePath))
            return FALSE;

        $header = NULL;
        $data = array();
        if (($handle = fopen($filePath, 'r')) !== FALSE)
        {
            while (($row = fgetcsv($handle, null, $delimiter, $enclosure)) !== FALSE)
            {
                if(!$header)
                {
                    $header = $row;
                }
                else
                {
                    $data[] = array_combine($header, $row);
                }
                unset($row);
            }
            fclose($handle);
        }
        return $data;
    }

    /**
     * Deletes the given report (calls the deleteLink).
     *
     * It expects $reports as an array in the form of:
     * <pre>
     *  [
     *      "downloadLink" => "download.do/?asdasd"
     *      "fileName" => "File.zip"
     *      "deleteLink" => "delete.do?asdasd"
     *  ]
     *  </pre>
     *
     * @param   array   $report         The app array with "deleteLink".
     *                                  You can get it with the BlackBerryStats::getReports() method.
     */
    public function deleteReport( $report )
    {
        if( empty($this->loginTokens) )
        {
            trigger_error( "BlackBerryStats::deleteReport(): Login tokens are empty. Login first!" );
            return;
        }

        $csrfToken = $this->loginTokens["csrfToken"];

        $url = $report["deleteLink"];
        $response = $this->client->get($url, array(
            "config" => array(
                "curl" => $this->defaultCurlOptions
            ),
            "headers" => array(
                    "Referer" => "https://appworld.blackberry.com/isvportal/reports/home.do?csrfToken=" . $csrfToken,
                ) + $this->defaultHeaders,
            "query" => array(
                "csrfToken" => $this->loginTokens["csrfToken"],
            ),
            "cookies" => $this->cookieJar
        ));

        return $response;
    }

    /**
     * Deletes all reports.
     */
    public function deleteAllReports()
    {
        if( empty($this->loginTokens) )
        {
            trigger_error( "BlackBerryStats::deleteAllReports(): Login tokens are empty. Login first!" );
            return;
        }

        $csrfToken = $this->loginTokens["csrfToken"];

        $url = "https://appworld.blackberry.com/isvportal/reports/deleteAllData.do";
        $response = $this->client->get($url, array(
            "config" => array(
                "curl" => $this->defaultCurlOptions
            ),
            "headers" => array(
                    "Referer" => "https://appworld.blackberry.com/isvportal/reports/home.do?csrfToken=" . $csrfToken,
                ) + $this->defaultHeaders,
            "query" => array(
                "csrfToken" => $this->loginTokens["csrfToken"],
            ),
            "cookies" => $this->cookieJar
        ));

        return $response;
    }

    /**
     * Returns all apps meta data in the form of:
     * <pre>
     * [
     *      [
     *          "name" => "Super Cool App: Elite Edition"
     *          "linkName" => "Super_Cool_App_Elite_Edition"
     *          "appId" => 123456788
     *      ],
     *      [
     *          "name" => "Another Super Cool App: Premium Edition"
     *          "linkName" => "Another_Super_Cool_App_Premium_Edition"
     *          "appId" => 123456789
     *      ]
     * ]
     * </pre>
     * Attention: How BlackBerry forms the linkName is speculation on our part (it may be wrong in some cases).
     *            You should rather use the appId whenever possible.
     *
     * @return array
     */
    public function getApps()
    {
        if( empty($this->loginTokens) )
        {
            trigger_error( "BlackBerryStats::getApps(): Login tokens are empty. Login first!" );
            return;
        }

        $response = $this->reqApps();
        $result = $this->reqAppsResult($response);

        return $result;
    }

    protected function reqApps()
    {
        $url = "https://appworld.blackberry.com/isvportal/reports/fetchProductsAction.do";
        $response = $this->client->post($url, array(
            "config" => array(
                "curl" => $this->defaultCurlOptions
            ),
            "headers" => array(
                "Referer" => "https://appworld.blackberry.com/isvportal/reports/scheduleDataPage.do?csrfToken=" . $this->loginTokens["csrfToken"]
                ) + $this->defaultHeaders,
            "body" => array(
                "csrfToken" => $this->loginTokens["csrfToken"],
                "selectedReportType" => 1,
                "selectedSubType" => 0,
                "selectedContent" => 0,
                "selectedVG" => "",
                "selectedPeriod" => 1,
                "startDate" => "",
                "endDate" => ""
            ),
            "cookies" => $this->cookieJar
        ));

        return $response;
    }

    protected function reqAppsResult( \GuzzleHttp\Message\MessageInterface $response )
    {
        $result = $response->json();
        array_shift($result["contents"]); // first entry is "All Aps"

        $apps = array();
        foreach($result["contents"] as $key => $currApp)
        {
            $app = array(
                "name"      => $currApp["name"],
                "linkName"  => $currApp["name"],
                "appId"     => $currApp["id"]
            );
            // transform linkName
            // How BlackBerry forms the linkName is speculation on our part (it may be wrong in some cases).
            $app["linkName"] = mb_ereg_replace('[^a-zA-z0-9 ]', "", $app["linkName"]);
            $app["linkName"] = mb_ereg_replace(' +', "_", $app["linkName"] );

            array_push( $apps, $app );
        }

        return $apps;
    }

    /**
     * Returns the report download file name for the given app and report type.
     *
     * Example:
     *  IN: App data of "Super Awesome App", BlackBerryStats::REPORT_TYPE_DOWNLOADS_SUMMARY, 2015-05-11, 2015-06-10
     *  OUT: "Super_Awesome_App_DownloadSummary_11_May_2015_to_10_Jun_2015_by_date.zip"
     *
     * @param $app              array   A single app entry as returned by BlackBerryStats::getApps() or "all"
     * @param $reportType       int     BlackBerryStats::REPORT_TYPE_*
     * @param $startDate        int     Nr. of days (offset to today) or date in format YYYY-MM-DD
     * @param $endDate          int     Nr. of days (offset to today) or date in format YYYY-MM-DD
     *
     * @return string
     */
    protected function getReportDownloadFileNameForApp( $app, $reportType, $startDate = -14, $endDate = 0 )
    {
        if( $app != "all" )
        {
            if( empty($app) or !array_key_exists('linkName', $app) )
            {
                trigger_error( "BlackBerryStats::getReportDownloadFileNameForApp(): Parameter app is empty or key 'linkName' is missing!" );
                return;
            }
        }
        else
        {
            // construct $app for an "All Applications" report.
            $app = array(
                "name"      => "All Applications",
                "linkName"  => "All_Applications",
                "appId"     => "all"
            );
        }

        // convert "%s_DownloadSummary_%s_to_%s_by_date"
        //  to
        // Super_Awesome_App_DownloadSummary_11_May_2015_to_10_Jun_2015_by_date.zip

        $baseStr = $this->REPORT_DOWNLOAD_FILENAMES[$reportType];
        $appName = $app['linkName'];
        $startDate = $this->getDateTimeFromInput( $startDate, "Y-m-d" );
        $endDate = $this->getDateTimeFromInput( $endDate, "Y-m-d" );

        return sprintf( $baseStr, $appName, $startDate->format("d_M_Y"), $endDate->format("d_M_Y") );
    }

    /**
     * Returns the reports  state which is either BBStats::REPORT_STATE_UNKNOWN,
     * BBStats::REPORT_STATE_PROCESSING or BBStats::REPORT_STATE_READY.
     *
     * Be aware that BBStats::getReports() returns only finished reports.
     *
     * Example:
     *  IN: App data of "Super Awesome App", BlackBerryStats::REPORT_TYPE_DOWNLOADS_SUMMARY, 2015-05-11, 2015-06-10
     *  OUT: int 0 (which is BBStats::REPORT_STATE_UNKNOWN)
     *
     * @param $app              array   A single app entry as returned by BlackBerryStats::getApps()
     * @param $reportType       int     BlackBerryStats::REPORT_TYPE_*
     * @param $startDate        int     Nr. of days (offset to today) or date in format YYYY-MM-DD
     * @param $endDate          int     Nr. of days (offset to today) or date in format YYYY-MM-DD
     *
     *
     * @return  array   Returns the report meta data in the form of:
     * <pre>
     *  [
     *      "downloadLink" => "https://appworld.blackberry.com/isvportal/reports/downloadData.do?csrfToken=...&fileName=....zip"
     *      "fileName" => "....zip"
     *      "deleteLink" => "https://appworld.blackberry.com/isvportal/reports/deleteData.do?csrfToken=...&fileName=....zip"
     *      "state" => BlackBerryStats::REPORT_STATE_UNKNOWN or BlackBerryStats::REPORT_STATE_PROCESSING or BlackBerryStats::REPORT_STATE_READY
     *  ]
     * </pre>
     */
    public function getReportState( $app, $reportType, $startDate = -14, $endDate = 0 )
    {
        if( empty($this->loginTokens) )
        {
            trigger_error( "BlackBerryStats::getReports(): Login tokens are empty. Login first!" );
            return;
        }

        $response = $this->reqReportState();
        $reportName = $this->getReportDownloadFileNameForApp( $app, $reportType, $startDate, $endDate );
        $result = $this->reqReportStateResult( $response, $reportName );

        return $result;
    }

    protected function reqReportState()
    {
        $url = "https://appworld.blackberry.com/isvportal/reports/fetchDownloadListAction.do";
        $response = $this->client->post($url, array(
            "config" => array(
                "curl" => $this->defaultCurlOptions
            ),
            "headers" => array(
                    "Referer" => "https://appworld.blackberry.com/isvportal/reports/home.do?csrfToken=" . $this->loginTokens["csrfToken"],
                ) + $this->defaultHeaders,
            "body" => array(
                "csrfToken"   => $this->loginTokens["csrfToken"],
            ),
            "cookies" => $this->cookieJar
        ));

        return $response;
    }

    protected function reqReportStateResult( \GuzzleHttp\Message\MessageInterface $response, $reportName )
    {
        $state = BlackBerryStats::REPORT_STATE_UNKNOWN;

        $result = $response->getBody()->getContents();
        $matches = array();
        // Sample: <a href="/isvportal/reports/downloadData.do?csrfToken=WNVZ-J9M0-V86A-1FLF-PPAB-2X69-IJG5-BV2D&fileName=Sniper_Ops_3D_Kill_Terror_Shooter_Downloads_for_23_Apr_2015_to_07_May_2015_by_date.zip" class="data-dump data-ready">Sniper_Ops_3D_Kill_Terror_Shooter_Downloads_for_23_Apr_2015_to_07_May_2015_by_date.zip</a></td>
        preg_match_all( '|/isvportal/reports/downloadData\.do.+?&fileName='.$reportName.'"|', $result, $matches, PREG_SET_ORDER);
        if( sizeof($matches) > 0 )
        {
            // Report download link found. We can assume that the report is finished.
            $state = BlackBerryStats::REPORT_STATE_READY;
        }
        else
        {
            // If we find the filename without .zip then the report is still processing
            if( stristr( $result, str_replace( ".zip", "", $reportName ) ) !== false )
            {
                $state = BlackBerryStats::REPORT_STATE_PROCESSING;
            }
        }

        $report = array(
            "downloadLink"  => "https://appworld.blackberry.com/isvportal/reports/downloadData.do?csrfToken=".$this->loginTokens["csrfToken"]."&fileName=".$reportName,
            "fileName"      => $reportName,
            "deleteLink"    => "https://appworld.blackberry.com/isvportal/reports/deleteData.do?csrfToken=".$this->loginTokens["csrfToken"]."&fileName=".$reportName,
            "state"         => $state
        );

        return $report;
    }

}